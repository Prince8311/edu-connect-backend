<?php

function emailSafetyLimit(string $name, int $default): int
{
    $value = getenv($name);
    if ($value === false || !ctype_digit((string)$value) || (int)$value <= 0) {
        return $default;
    }

    return (int)$value;
}

function emailSafetyClientIp(): string
{
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function ensureEmailSendEventsTable(mysqli $conn): bool
{
    static $ready = false;

    if ($ready) {
        return true;
    }

    $sql = "CREATE TABLE IF NOT EXISTS `email_send_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `recipient_hash` CHAR(64) NOT NULL,
                `ip_hash` CHAR(64) NOT NULL,
                `category` VARCHAR(40) NOT NULL,
                `status` ENUM('reserved','sent','failed') NOT NULL DEFAULT 'reserved',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_email_recipient_created` (`recipient_hash`, `created_at`),
                INDEX `idx_email_ip_created` (`ip_hash`, `created_at`),
                INDEX `idx_email_category_created` (`category`, `created_at`),
                INDEX `idx_email_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $ready = mysqli_query($conn, $sql) !== false;
    return $ready;
}

function reserveEmailSend(mysqli $conn, string $recipient, string $category): array
{
    if (!ensureEmailSendEventsTable($conn)) {
        return [
            'allowed' => false,
            'retry_after' => 300,
            'reason' => 'Email safety storage is unavailable.'
        ];
    }

    $recipientHash = hash('sha256', strtolower(trim($recipient)));
    $ipHash = hash('sha256', emailSafetyClientIp());
    $isOtp = $category === 'otp';
    $recipientLimit = $isOtp
        ? emailSafetyLimit('EMAIL_OTP_RECIPIENT_LIMIT_15_MIN', 3)
        : emailSafetyLimit('EMAIL_RECIPIENT_LIMIT_60_MIN', 10);
    $ipLimit = $isOtp
        ? emailSafetyLimit('EMAIL_OTP_IP_LIMIT_15_MIN', 5)
        : emailSafetyLimit('EMAIL_IP_LIMIT_60_MIN', 20);
    $windowMinutes = $isOtp ? 15 : 60;
    $minuteLimit = emailSafetyLimit('EMAIL_GLOBAL_LIMIT_PER_MINUTE', 8);
    $dailyLimit = emailSafetyLimit('EMAIL_GLOBAL_LIMIT_PER_DAY', 80);
    $windowStart = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
    $minuteStart = date('Y-m-d H:i:s', time() - 60);
    $dailyStart = date('Y-m-d H:i:s', time() - 86400);
    $lockAcquired = false;

    try {
        $lockResult = mysqli_query($conn, "SELECT GET_LOCK('educonnekt_email_send_limit', 3) AS `acquired`");
        $lockRow = $lockResult ? mysqli_fetch_assoc($lockResult) : null;
        $lockAcquired = isset($lockRow['acquired']) && (int)$lockRow['acquired'] === 1;

        if (!$lockAcquired) {
            return [
                'allowed' => false,
                'retry_after' => 30,
                'reason' => 'Email service is busy. Please retry shortly.'
            ];
        }

        $countSql = "SELECT
                        SUM(CASE WHEN `recipient_hash` = ? AND `created_at` >= ? THEN 1 ELSE 0 END) AS `recipient_count`,
                        SUM(CASE WHEN `ip_hash` = ? AND `created_at` >= ? THEN 1 ELSE 0 END) AS `ip_count`,
                        SUM(CASE WHEN `created_at` >= ? THEN 1 ELSE 0 END) AS `minute_count`,
                        SUM(CASE WHEN `created_at` >= ? THEN 1 ELSE 0 END) AS `daily_count`
                     FROM `email_send_events`";
        $countStmt = mysqli_prepare($conn, $countSql);

        if (!$countStmt) {
            throw new RuntimeException('Failed to prepare email rate-limit query.');
        }

        mysqli_stmt_bind_param(
            $countStmt,
            'ssssss',
            $recipientHash,
            $windowStart,
            $ipHash,
            $windowStart,
            $minuteStart,
            $dailyStart
        );
        if (!mysqli_stmt_execute($countStmt)) {
            mysqli_stmt_close($countStmt);
            throw new RuntimeException('Failed to check email rate limits.');
        }

        $counts = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));
        mysqli_stmt_close($countStmt);

        if ((int)$counts['recipient_count'] >= $recipientLimit) {
            return ['allowed' => false, 'retry_after' => $windowMinutes * 60, 'reason' => 'Too many emails were requested for this address.'];
        }
        if ((int)$counts['ip_count'] >= $ipLimit) {
            return ['allowed' => false, 'retry_after' => $windowMinutes * 60, 'reason' => 'Too many email requests were made from this connection.'];
        }
        if ((int)$counts['minute_count'] >= $minuteLimit) {
            return ['allowed' => false, 'retry_after' => 60, 'reason' => 'The application email rate limit has been reached.'];
        }
        if ((int)$counts['daily_count'] >= $dailyLimit) {
            return ['allowed' => false, 'retry_after' => 3600, 'reason' => 'The application daily email safety limit has been reached.'];
        }

        $insertSql = "INSERT INTO `email_send_events` (`recipient_hash`, `ip_hash`, `category`, `status`) VALUES (?, ?, ?, 'reserved')";
        $insertStmt = mysqli_prepare($conn, $insertSql);
        if (!$insertStmt) {
            throw new RuntimeException('Failed to prepare email reservation.');
        }

        mysqli_stmt_bind_param($insertStmt, 'sss', $recipientHash, $ipHash, $category);
        if (!mysqli_stmt_execute($insertStmt)) {
            mysqli_stmt_close($insertStmt);
            throw new RuntimeException('Failed to reserve email capacity.');
        }

        $eventId = mysqli_insert_id($conn);
        mysqli_stmt_close($insertStmt);

        if (random_int(1, 100) === 1) {
            mysqli_query($conn, "DELETE FROM `email_send_events` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        }

        return [
            'allowed' => true,
            'event_id' => $eventId,
            'retry_after' => 0,
            'reason' => ''
        ];
    } catch (Throwable $error) {
        return [
            'allowed' => false,
            'retry_after' => 300,
            'reason' => 'Email safety checks could not be completed.'
        ];
    } finally {
        if ($lockAcquired) {
            mysqli_query($conn, "SELECT RELEASE_LOCK('educonnekt_email_send_limit')");
        }
    }
}

function completeEmailSendReservation(mysqli $conn, int $eventId, bool $sent): void
{
    if ($eventId <= 0) {
        return;
    }

    $status = $sent ? 'sent' : 'failed';
    $stmt = mysqli_prepare($conn, "UPDATE `email_send_events` SET `status` = ? WHERE `id` = ? LIMIT 1");
    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param($stmt, 'si', $status, $eventId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
