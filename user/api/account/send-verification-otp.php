<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$authResult = userAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;

    require __DIR__ . "/../../../PHPMailer/Exception.php";
    require __DIR__ . "/../../../PHPMailer/PHPMailer.php";
    require __DIR__ . "/../../../PHPMailer/SMTP.php";

    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    $userSql = "SELECT * FROM `users` WHERE `id`='$userId'";
    $userResult = mysqli_query($conn, $userSql);

    if (!$userResult) {
        $response = [
            'success' => false,
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($response);
        exit;
    }

    if (mysqli_num_rows($userResult) === 1) {
        $userData = mysqli_fetch_assoc($userResult);
        $userName = $userData['name'] ?? '';
        $userName = trim($userName);
        $firstName = explode(' ', $userName)[0];
        $userMail = $userData['email'];
        $isMailVerified = (bool)$userData['is_mail_verified'];
        $userPhone = $userData['phone'];
        $isPhoneVerified = (bool)$userData['is_phone_verified'];

        $inputData = json_decode(file_get_contents("php://input"), true);
        if (empty($inputData)) {
            $response = [
                'success' => false,
                'status' => 400,
                'message' => 'Empty request data'
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($response);
            exit;
        }

        $input = $inputData['name'];

        if ($input === $userData['email'] || $input === $userData['phone']) {
            $otp = rand(100000, 999999);
            $otpPart1 = substr($otp, 0, 3);
            $otpPart2 = substr($otp, 3, 3);
            $expiresAt = date("Y-m-d H:i:s", time() + 600);

            if ($input === $userData['email']) {
                if ($isMailVerified) {
                    $response = [
                        'success' => false,
                        'status' => 403,
                        'message' => 'Email address already verified.'
                    ];
                    header("HTTP/1.1 403 Forbidden");
                    echo json_encode($response);
                    exit;
                }

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
                    $mail->addAddress($input, $userName);
                    $mail->Subject = 'OTP for verification';
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
                                                                src="https://api.edu-connect.ticketbay.in/images/logo.png" alt="Logo" style="height: 25px;"></div>
                                                        <div
                                                            style="position: relative; width: 300px; padding: 20px; margin: 0 auto; margin-top: 25px; background-color: #FFF;  border-radius: 10px; box-shadow: 0 0 10px rgba(126, 126, 126, 0.3);">
                                                            <div style="position: relative; font-size: 18px; font-weight: 500;">Verify Your Identity</div>
                                                            <div class="poppins-font"
                                                                style="position: relative; font-size: 13px; margin-top: 10px; color: #838383; line-height: 1.3;">Use
                                                                the following One-Time Password (OTP) to complete your sign-in. This code is valid for <span
                                                                    class="poppins-font" style="color: #1e1e1e; font-weight: 500;">10 minutes.</span> </div>
                                                            <div
                                                                style="position: relative; width: 100%; padding: 8px 10px; background-color: #EFF1F2; margin-top: 15px; border-radius: 6px;  letter-spacing: 1px; font-size: 26px; font-weight: 600; color: #0072C3; box-shadow: 0 0 8px rgba(103, 103, 103, 0.3);">' . $otpPart1 . ' ' . $otpPart2 . '</div>
                                                            <div class="poppins-font"
                                                                style="position: relative; margin-top: 25px; padding-top: 8px; border-top: 1px solid #E1E0EA; font-size: 12px; color: #838383; font-weight: 300;">
                                                                If you did not request this code, please ignore this email or contact support.</div>
                                                        </div>
                                                        <div style="position: relative; width: max-content; margin: 0 auto; margin-top: 15px; text-align: center;">
                                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center"
                                                                style="background-color: #E6E7E8; border-radius: 999px; padding: 6px 20px 6px 16px;">
                                                                <tr>
                                                                    <td style="vertical-align: middle;">
                                                                        <img src="https://api.edu-connect.ticketbay.in/images/security.svg" alt="Secure"
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

                    $updateSql = "UPDATE `users` SET `mail_otp`='$otp',`mail_otp_expires_at`='$expiresAt' WHERE `id`='$userId'";
                    $updateResult = mysqli_query($conn, $updateSql);

                    if ($updateResult) {
                        $response = [
                            'success' => true,
                            'status' => 200,
                            'message' => 'OTP has been sent to your email.'
                        ];
                        header("HTTP/1.0 200 OK");
                        echo json_encode($response);
                    } else {
                        $response = [
                            'success' => false,
                            'status' => 500,
                            'message' => 'Internal Server Error',
                        ];
                        header("HTTP/1.0 500 Internal Server Error");
                        echo json_encode($response);
                    }
                } catch (Exception $e) {
                    $response = [
                        'success' => false,
                        'status' => 500,
                        'message' => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}",
                    ];
                    header("HTTP/1.0 500 Message could not be sen");
                    echo json_encode($response);
                }
            } else if ($input === $userData['phone']) {
                if ($isPhoneVerified) {
                    $response = [
                        'success' => false,
                        'status' => 403,
                        'message' => 'Phone no. already verified.'
                    ];
                    header("HTTP/1.1 403 Forbidden");
                    echo json_encode($response);
                    exit;
                }

                $key = getenv('SMS_API_KEY');
                $senderid = getenv('SMS_SENDER_ID');
                $tempid = getenv('VERIFICATION_SMS_TEMPLATE_ID');

                $message = "Dear $firstName, your OTP for mobile no. verification to Edu Connekt is $otp. OTP is valid for 10 minutes. Please do not share the OTP. SHETTY TICKET COUNTER";
                $message_content = urlencode($message);

                $url = "https://smsfortius.work/V2/?apikey=$key&senderid=$senderid&templateid=$tempid&number=$input&message=$message_content";
                $output = file_get_contents($url);

                $updateSql = "UPDATE `users` SET `phone_otp`='$otp',`phone_otp_expires_at`='$expiresAt' WHERE `id`='$userId'";
                $updateResult = mysqli_query($conn, $updateSql);

                if ($updateResult) {
                    $response = [
                        'success' => true,
                        'status' => 200,
                        'message' => 'OTP has been sent to your mobile no.'
                    ];
                    header("HTTP/1.0 200 OK");
                    echo json_encode($response);
                } else {
                    $response = [
                        'success' => false,
                        'status' => 500,
                        'message' => 'Internal Server Error',
                    ];
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode($response);
                }
            }
        } else {
            $response = [
                'success' => false,
                'status' => 404,
                'message' => 'User not found with the provided email or phone number.'
            ];
            header("HTTP/1.0 404 Not Found");
            echo json_encode($response);
        }
    } else {
        $response = [
            'success' => false,
            'status' => 401,
            'message' => 'User not found.'
        ];
        header("HTTP/1.0 401 Unauthorized");
        echo json_encode($response);
        exit;
    }
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
