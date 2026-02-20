<?php

require "../../utils/headers.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($requestMethod === 'POST') {
    require "../../_db-connect.php";
    global $conn;

    require "../../PHPMailer/Exception.php";
    require "../../PHPMailer/PHPMailer.php";
    require "../../PHPMailer/SMTP.php";

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (!empty($inputData)) {
        $user = mysqli_real_escape_string($conn, $inputData['name']);

        $sql = "SELECT * FROM `admin_users` WHERE `name`='$user' OR `email`='$user' OR `phone`='$user'";
        $result = mysqli_query($conn, $sql);

        if (!$result) {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
        }

        if (mysqli_num_rows($result) === 1) {
            $data = mysqli_fetch_assoc($result);
            $userId = $data['id'];
            $userEmail = $data['email'];
            $userType = $data['user_type'];

            if ($userType != "super_admin" && $userType != "employee") {
                $data = [
                    'status' => 400,
                    'message' => 'Permission denied.',
                    'userType' => $userType
                ];
                header("HTTP/1.0 400 Forbidden");
                echo json_encode($data);
                exit;
            }

            $otp = rand(100000, 999999);
            $otpPart1 = substr($otp, 0, 3);
            $otpPart2 = substr($otp, 3, 3);
            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host       = 'mail.ticketbay.in';
                $mail->SMTPAuth   = true;
                $mail->Username   = getenv('SMTP_MAIL');
                $mail->Password   = getenv('SMTP_PASSWORD');
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = getenv('SMTP_PORT');
                $mail->CharSet = 'UTF-8';

                $mail->isHTML(true);
                $mail->setFrom('noreply@ticketbay.in', 'noreply@ticketbay.in');
                $mail->addAddress("$userEmail", 'User');
                $mail->Subject = 'OTP for Authentication';
                $mail->Body    = '<!DOCTYPE html>
                                <html lang="en">
                                    <head>
                                        <meta charset="UTF-8">
                                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                        <link href="https://fonts.googleapis.com/css2?family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap" rel="stylesheet">
                                    </head>
                                    <body style="position: relative; margin: 0; padding: 0;">
                                        <div class="template_wrapper" style="position: relative; width: 100%;  padding: 10px; box-sizing: border-box; ">
                                            <div class="template" style="position: relative; background: #FFF; padding-bottom: 50px; border-radius: 5px;" >
                                                <div class="logo" style="position: relative; text-align: center;"><img src="https://ticketbay.in/Backend/Images/Logo.png" alt="Logo" style="width: 50px;"></div>
                                                <div class="body_message" style="position: relative; margin-top: 15px;">
                                                    <p style="position: relative; text-align: center;">
                                                        <span style="position: relative; text-align: center; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">Your OTP for authentication in <a href="" style="color: #FC6736;" >superadmin.ticketbay.in</a> is,</span>
                                                    </p>
                                                </div>
                                                <div class="body_message" style="position: relative; margin-top: 5px;">
                                                    <p style="position: relative; text-align: center;">
                                                        <span style="position: relative; text-align: center; font-family: sans-serif; color: #02C0FF; font-size: 20px; line-height: 1; font-weight: 600;">' . $otpPart1 . '  ' . $otpPart2 . '</span>
                                                    </p>
                                                </div>
                                                <div style="position: relative; margin-top: 30px;">
                                                    <p style="position: relative;"><b style="position: relative; font-family: sans-serif; font-size: 13px; color: #f00;">*NOTE:- Please do not share this message with anyone else.</b></p>
                                                </div>
                                            </div>
                                        </div>
                                    </body>
                                </html>';
                $mail->send();
                $updateSql = "UPDATE `admin_users` SET `mail_otp`='$otp', `auth_token`=NULL, `expires_at`=NULL WHERE `id` = '$userId'";
                $updateResult = mysqli_query($conn, $updateSql);

                if ($updateResult) {
                    $_SESSION['userId'] = $userId;
                    $data = [
                        'status' => 200,
                        'message' => 'OTP has been sent.'
                    ];
                    header("HTTP/1.0 200 OTP Sent");
                    echo json_encode($data);
                } else {
                    $data = [
                        'status' => 500,
                        'message' => 'Internal Server Error',
                    ];
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode($data);
                }
            } catch (Exception $e) {
                $data = [
                    'status' => 500,
                    'message' => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}",
                ];
                header("HTTP/1.0 500 Message could not be sent");
            }
        } else {
            $data = [
                'status' => 404,
                'message' => 'User Not Found',
            ];
            header("HTTP/1.0 404 User Not Found");
            echo json_encode($data);
        }
    } else {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
