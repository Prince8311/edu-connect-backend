<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../../_db-connect.php";
    require __DIR__ . "/../../../../PHPMailer/Exception.php";
    require __DIR__ . "/../../../../PHPMailer/PHPMailer.php";
    require __DIR__ . "/../../../../PHPMailer/SMTP.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    $userId = $authResult['userId'];

    $respond = static function ($status, $message, $extra = []) {
        http_response_code($status);
        echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
        exit;
    };

    $inputData = json_decode(file_get_contents('php://input'), true);
    if (!is_array($inputData)) {
        $inputData = $_POST;
    }

    foreach (['memberUserId', 'memberUserType', 'enrollId', 'memberUserEmail'] as $field) {
        if (!array_key_exists($field, $inputData)
            || (!is_string($inputData[$field]) && !is_int($inputData[$field]))
            || trim((string) $inputData[$field]) === '') {
            $respond(400, $field . ' is required.');
        }
    }

    $memberUserId = trim((string) $inputData['memberUserId']);
    $memberUserType = trim((string) $inputData['memberUserType']);
    $enrollId = trim((string) $inputData['enrollId']);
    $memberUserEmail = trim((string) $inputData['memberUserEmail']);
    if (!filter_var($memberUserEmail, FILTER_VALIDATE_EMAIL)) {
        $respond(400, 'A valid memberUserEmail is required.');
    }
    $quote = static function ($value) use ($conn) {
        return "'" . mysqli_real_escape_string($conn, (string) $value) . "'";
    };
    $query = static function ($sql) use ($conn) {
        $result = mysqli_query($conn, $sql);
        if ($result === false) {
            throw new RuntimeException(mysqli_error($conn));
        }
        return $result;
    };

    $transactionStarted = false;
    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Could not start transaction.');
        }
        $transactionStarted = true;

        $existingResult = $query(
            'SELECT `id` FROM `library_members` WHERE `inst_id`=' . $quote($instituteId)
            . ' AND `user_id`=' . $quote($memberUserId)
            . ' AND `user_type`=' . $quote($memberUserType) . ' LIMIT 1 FOR UPDATE'
        );
        if (mysqli_num_rows($existingResult) > 0) {
            mysqli_rollback($conn);
            $transactionStarted = false;
            $respond(409, 'This library member already exists.');
        }

        $year = date('Y');
        $libraryId = null;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $source = $instituteId . ':' . $enrollId . ($attempt === 0 ? '' : ':' . $attempt);
            $code = strtoupper(substr(hash('sha256', $source), 0, 4));
            $candidate = 'LIB-' . $year . '-' . $code;
            $idResult = $query(
                'SELECT `id` FROM `library_members` WHERE `library_id`=' . $quote($candidate) . ' LIMIT 1 FOR UPDATE'
            );
            if (mysqli_num_rows($idResult) === 0) {
                $libraryId = $candidate;
                break;
            }
        }
        if ($libraryId === null) {
            throw new RuntimeException('Could not generate an available library ID.');
        }

        $createdAt = date('Y-m-d H:i:s');
        $query(
            'INSERT INTO `library_members` (`inst_id`, `user_id`, `user_type`, `enroll_id`, `library_id`, `created_at`, `created_by`) VALUES ('
            . implode(', ', [
                $quote($instituteId),
                $quote($memberUserId),
                $quote($memberUserType),
                $quote($enrollId),
                $quote($libraryId),
                $quote($createdAt),
                $quote($userId)
            ]) . ')'
        );

        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Could not commit library member changes.');
        }
        $transactionStarted = false;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            mysqli_rollback($conn);
        }
        error_log('Library member creation failed: ' . $error->getMessage());
        $respond(500, 'Failed to add library member.');
    }

    $emailSent = false;
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_MAIL');
        $mail->Password = getenv('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = getenv('SMTP_PORT');
        $mail->CharSet = 'UTF-8';

        $safeLibraryId = htmlspecialchars($libraryId, ENT_QUOTES, 'UTF-8');
        $mail->isHTML(true);
        $mail->setFrom(getenv('SMTP_MAIL'), getenv('SMTP_MAIL'));
        $mail->addAddress($memberUserEmail);
        $mail->Subject = 'Your Library Membership Has Been Created';
        $mail->Body = '<!DOCTYPE html>
            <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                </head>
                <body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,sans-serif;color:#2d3748;">
                    <div style="max-width:600px;margin:0 auto;padding:32px 16px;">
                        <div style="background:#ffffff;border-radius:8px;padding:32px;border:1px solid #e2e8f0;">
                            <h2 style="margin:0 0 20px;color:#1a365d;">Library Membership Confirmation</h2>
                            <p style="font-size:15px;line-height:1.7;margin:0 0 16px;">Dear Library Member,</p>
                            <p style="font-size:15px;line-height:1.7;margin:0 0 16px;">
                                Your library membership has been created successfully. Please keep the following Library ID for your records:
                            </p>
                            <p style="font-size:20px;line-height:1.5;margin:20px 0;padding:14px;background:#edf2f7;border-radius:6px;text-align:center;">
                                <strong>' . $safeLibraryId . '</strong>
                            </p>
                            <p style="font-size:15px;line-height:1.7;margin:0 0 16px;">
                                Please collect your library card from your institution at your convenience.
                            </p>
                            <p style="font-size:15px;line-height:1.7;margin:24px 0 0;">Regards,<br>Library Administration</p>
                        </div>
                    </div>
                </body>
            </html>';
        $mail->AltBody = "Dear Library Member,\n\nYour library membership has been created successfully.\nLibrary ID: "
            . $libraryId
            . "\n\nPlease collect your library card from your institution at your convenience.\n\nRegards,\nLibrary Administration";
        $mail->send();
        $emailSent = true;
    } catch (PHPMailerException $error) {
        error_log('Library membership email failed: ' . $error->getMessage());
    }

    $message = $emailSent
        ? 'Library member added and email sent successfully.'
        : 'Library member added successfully, but the email could not be sent.';
    $respond(201, $message, ['library_id' => $libraryId, 'email_sent' => $emailSent]);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
