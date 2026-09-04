<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;

    require __DIR__ . "/../../../PHPMailer/Exception.php";
    require __DIR__ . "/../../../PHPMailer/PHPMailer.php";
    require __DIR__ . "/../../../PHPMailer/SMTP.php";
    require __DIR__ . "/../../../utils/email-safety.php";

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (empty($inputData)) {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $institutionName = mysqli_real_escape_string($conn, $inputData['institutionName']);
    $phone = mysqli_real_escape_string($conn, $inputData['phone']);
    $recipientEmail = trim((string)$inputData['email']);
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $data = [
            'status' => 400,
            'message' => 'A valid email address is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }
    $email = mysqli_real_escape_string($conn, $recipientEmail);
    $location = mysqli_real_escape_string($conn, $inputData['location']);

    $nameCheckSql = "SELECT * FROM `institutions` WHERE `inst_name`='$institutionName'";
    $nameCheckResult = mysqli_query($conn, $nameCheckSql);
    if ($nameCheckResult && mysqli_num_rows($nameCheckResult) === 1) {
        $data = [
            'status' => 400,
            'message' => 'Institution already registered.'
        ];
        header("HTTP/1.0 400 Already exists");
        echo json_encode($data);
        exit;
    }

    $phoneCheckSql = "SELECT * FROM `institutions` WHERE `phone`='$phone'";
    $phoneCheckResult = mysqli_query($conn, $phoneCheckSql);
    if ($phoneCheckResult && mysqli_num_rows($phoneCheckResult) === 1) {
        $data = [
            'status' => 400,
            'message' => 'Contact no. already registered.'
        ];
        header("HTTP/1.0 400 Already exists");
        echo json_encode($data);
        exit;
    }

    $emailCheckSql = "SELECT * FROM `institutions` WHERE `email`='$email'";
    $emailCheckResult = mysqli_query($conn, $emailCheckSql);
    if ($emailCheckResult && mysqli_num_rows($emailCheckResult) === 1) {
        $data = [
            'status' => 400,
            'message' => 'Email address already registered.'
        ];
        header("HTTP/1.0 400 Already exists");
        echo json_encode($data);
        exit;
    }

    $cleanName = preg_replace("/[^a-zA-Z]/", "", $institutionName);
    if (strlen($cleanName) < 3) {
        $data = [
            'status' => 400,
            'message' => 'Institution name must be at least 3 letters'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $emailReservation = reserveEmailSend($conn, $recipientEmail, 'institution_registration');
    if (!$emailReservation['allowed']) {
        $data = [
            'status' => 429,
            'message' => $emailReservation['reason']
        ];
        header('Retry-After: ' . $emailReservation['retry_after']);
        header("HTTP/1.0 429 Too Many Requests");
        echo json_encode($data);
        exit;
    }

    $status = 0;
    $insertSql = "INSERT INTO `institutions`(`inst_name`, `phone`, `email`, `status`, `location`) VALUES ('$institutionName','$phone','$email','$status','$location')";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = getenv('SMTP_HOST');
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('SMTP_MAIL');
            $mail->Password   = getenv('SMTP_PASSWORD');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = getenv('SMTP_PORT');
            $mail->CharSet = 'UTF-8';

            $mail->isHTML(true);
            $mail->setFrom(getenv('SMTP_MAIL'), getenv('SMTP_MAIL'));
            $mail->addAddress($recipientEmail, 'User');
            $mail->Subject = 'Registration successful 📜📜📜';
            $mail->Body    = '<!DOCTYPE html>
                                        <html lang="en">
                                            <head>
                                                <meta charset="UTF-8">
                                                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                                <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=SUSE:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">

                                                <style>
                                                    * {
                                                        margin: 0;
                                                        padding: 0;
                                                        box-sizing: border-box;
                                                        font-family: "SUSE", sans-serif;
                                                    }

                                                    .poppins-font {
                                                        font-family: "Poppins", sans-serif;
                                                    }
                                                </style>
                                            </head>
                                            <body style="position: relative;">
                                                <div style="position: relative; width: 100%;">
                                                    <div style="position: relative; background: #FFF; padding: 25px; border-radius: 10px; text-align: center;">
                                                        <div class="logo" style="position: relative; text-align: center;"><img
                                                                src="https://api.educonnekt.in/images/logo.png" alt="Logo" style="height: 25px;"></div>
                                                        <div style="position: relative; width: 300px; padding: 20px 10px; margin: 0 auto; margin-top: 10px; background-color: #FFF;  border-radius: 10px; box-shadow: 0 0 10px rgba(126, 126, 126, 0.3);">
                                                            <div style="position: relative; font-size: 16px; font-weight: 500;">Registration Request Received</div>
                                                            <div class="poppins-font" style="position: relative; font-size: 13px; margin-top: 10px; color: #838383; line-height: 1.5;">Thank you for starting your digital transformation with <span class="poppins-font" style="color: #1c41ff; font-weight: 500;">Edu Connekt</span>. Your request for <span class="poppins-font" style="color: #02c0ff; font-weight: 600;">' . $institutionName . '</span> has been successfully listed in our system. Our team is currently reviewing your application for administrative approval.</div>
                                                            <div class="poppins-font" style="position: relative; margin-top: 25px; padding-top: 8px; border-top: 1px solid #E1E0EA; font-size: 12px; color: #838383; font-weight: 300;">If you did not request this code, please ignore this email or contact support.</div>
                                                        </div>
                                                        <div style="position: relative; width: max-content; margin: 0 auto; margin-top: 15px; text-align: center;">
                                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center"
                                                                style="background-color: #E6E7E8; border-radius: 999px; padding: 6px 20px 6px 16px;">
                                                                <tr>
                                                                    <td style="vertical-align: middle;">
                                                                        <img src="https://api.educonnekt.in/images/security.svg" alt="Secure"
                                                                            style="width: 12px; display: block;">
                                                                    </td>
                                                                    <td style="vertical-align: middle; padding-left: 5px; padding-bottom: 3px;">
                                                                        <span class="poppins-font" style="font-size: 10px; font-weight: 500; color: #555;">
                                                                            SECURE AUTHENTICATION PROTOCOL
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                            </table>
                                                        </div>
                                                        <div style="position: relative; width: 300px; margin: 0 auto; margin-top: 25px;">
                                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto;">
                                                                <tr>
                                                                    <td style="vertical-align: middle;">
                                                                        <a href="https://educonnekt.in/privacy-policy" class="poppins-font" style="position: relative; font-size: 12px; text-decoration: none; color: #b3b3b3;">PRIVACY POLICY</a>
                                                                    </td>
                                                                    <td style="vertical-align: middle;">
                                                                        <a href="https://educonnekt.in/terms-conditions" class="poppins-font" style="position: relative; font-size: 12px; text-decoration: none; color: #b3b3b3; margin-left: 25px;">TERMS OF SERVICE</a>
                                                                    </td>
                                                                </tr>
                                                            </table>
                                                        </div>
                                                        <div class="poppins-font" style="position: relative; margin: 0 auto; margin-top: 5px; font-size: 11px; line-height: 1.3; color: #b3b3b3;">©2026 Edu Connekt by Shetty Ticket Counter Private Limited. All righs reserved.</div>
                                                    </div>
                                                </div>
                                            </body>
                                        </html>';
            $mail->send();
            completeEmailSendReservation($conn, (int)$emailReservation['event_id'], true);
            $data = [
                'status' => 200,
                'message' => 'Registration successful.'
            ];
            header("HTTP/1.0 200 OK");
            echo json_encode($data);
        } catch (Exception $e) {
            completeEmailSendReservation($conn, (int)$emailReservation['event_id'], false);
            $data = [
                'status' => 500,
                'message' => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}",
            ];
            header("HTTP/1.0 500 Message could not be sent");
            echo json_encode($data);
        }
    } else {
        completeEmailSendReservation($conn, (int)$emailReservation['event_id'], false);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
