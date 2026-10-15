<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $respond = static function ($status, $message, $extra = []) {
        http_response_code($status);
        echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
        exit;
    };

    if (!array_key_exists('memberId', $_GET)
        || (!is_string($_GET['memberId']) && !is_int($_GET['memberId']))
        || trim((string) $_GET['memberId']) === '') {
        $respond(400, 'memberId is required.');
    }

    $memberId = trim((string) $_GET['memberId']);

    $fetchOne = static function ($sql, $types, ...$parameters) use ($conn) {
        $statement = mysqli_prepare($conn, $sql);
        if ($statement === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        try {
            if ($parameters && !mysqli_stmt_bind_param($statement, $types, ...$parameters)) {
                throw new RuntimeException(mysqli_stmt_error($statement));
            }
            if (!mysqli_stmt_execute($statement)) {
                throw new RuntimeException(mysqli_stmt_error($statement));
            }

            $result = mysqli_stmt_get_result($statement);
            if ($result === false) {
                throw new RuntimeException(mysqli_stmt_error($statement));
            }

            $row = mysqli_fetch_assoc($result);
            return $row ?: null;
        } finally {
            mysqli_stmt_close($statement);
        }
    };

    try {
        $member = $fetchOne(
            'SELECT `user_id`, `user_type`
             FROM `library_members`
             WHERE `id` = ? AND `inst_id` = ?
             LIMIT 1',
            'ss',
            $memberId,
            $instituteId
        );

        if ($member === null) {
            $respond(404, 'Library member not found.');
        }

        $userId = $member['user_id'];
        $userType = strtolower(trim((string) $member['user_type']));
        $card = null;

        if ($userType === 'student') {
            $user = $fetchOne(
                'SELECT `name`, `profile_image`, `email`, `phone`
                 FROM `users`
                 WHERE `id` = ? AND `inst_id` = ?
                 LIMIT 1',
                'ss',
                $userId,
                $instituteId
            );
            if ($user === null) {
                $respond(404, 'Student user not found.');
            }

            $student = $fetchOne(
                'SELECT `id`
                 FROM `students`
                 WHERE `user_id` = ? AND `inst_id` = ?
                 LIMIT 1',
                'ss',
                $userId,
                $instituteId
            );
            if ($student === null) {
                $respond(404, 'Student record not found.');
            }

            $studentFields = $fetchOne(
                "SELECT
                    MAX(CASE WHEN `field_name` = 'Session' THEN `value` END) AS `session`,
                    MAX(CASE WHEN `field_name` = 'Class / Standard' THEN `value` END) AS `class_name`,
                    MAX(CASE WHEN `field_name` = 'Section' THEN `value` END) AS `section_name`
                 FROM `student_field_values`
                 WHERE `student_id` = ? AND `inst_id` = ?
                   AND `field_name` IN ('Session', 'Class / Standard', 'Section')",
                'ss',
                $student['id'],
                $instituteId
            );

            $session = $studentFields['session'] ?? null;
            $className = $studentFields['class_name'] ?? null;
            $sectionName = $studentFields['section_name'] ?? null;
            $class = null;
            if ($className !== null && $className !== '' && $sectionName !== null && $sectionName !== '') {
                $class = $className . ' - ' . $sectionName;
            } elseif ($className !== null && $className !== '') {
                $class = $className;
            } elseif ($sectionName !== null && $sectionName !== '') {
                $class = $sectionName;
            }

            $validity = null;
            if ($session !== null && $session !== '') {
                $academicSession = $fetchOne(
                    'SELECT `end_date`
                     FROM `academic_sessions`
                     WHERE `session_name` = ? AND `inst_id` = ?
                     LIMIT 1',
                    'ss',
                    $session,
                    $instituteId
                );
                $validity = $academicSession['end_date'] ?? null;
            }

            $card = [
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'image' => $user['profile_image'],
                'session' => $session,
                'class' => $class,
                'validity' => $validity,
                'directory' => 'user'
            ];
        } elseif ($userType === 'teacher') {
            $user = $fetchOne(
                'SELECT `name`, `profile_image`, `email`, `phone`
                 FROM `users`
                 WHERE `id` = ? AND `inst_id` = ?
                 LIMIT 1',
                'ss',
                $userId,
                $instituteId
            );
            if ($user === null) {
                $respond(404, 'Teacher user not found.');
            }

            $card = [
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'image' => $user['profile_image'],
                'validity' => 'Life time',
                'directory' => 'user'
            ];
        } elseif ($userType === 'staff') {
            $user = $fetchOne(
                'SELECT `name`, `image`, `email`, `phone`, `user_role`
                 FROM `admin_users`
                 WHERE `id` = ? AND `inst_id` = ?
                 LIMIT 1',
                'ss',
                $userId,
                $instituteId
            );
            if ($user === null) {
                $respond(404, 'Staff user not found.');
            }

            $card = [
                'name' => $user['name'],
                'image' => $user['image'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'designation' => $user['user_role'],
                'validity' => 'Life time',
                'directory' => 'admin'
            ];
        } else {
            $respond(422, 'Unsupported library member user type.');
        }

        $respond(200, 'Library card details fetched successfully.', ['card' => $card]);
    } catch (Throwable $error) {
        error_log('Library card details fetch failed: ' . $error->getMessage());
        $respond(500, 'Failed to fetch library card details.');
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
