<?php

require "../../../utils/headers.php";
require "../../../utils/middleware.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    $data = [
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ];
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode($data);
    exit;
}

if ($requestMethod === 'POST') {
    require "../../../_db-connect.php";
    global $conn;

    require "../../../PHPMailer/Exception.php";
    require "../../../PHPMailer/PHPMailer.php";
    require "../../../PHPMailer/SMTP.php";

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
    $email = mysqli_real_escape_string($conn, $inputData['email']);

    $nameCheckSql = "SELECT * FROM `registered_institutions` WHERE `inst_name`='$institutionName'";
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

    $phoneCheckSql = "SELECT * FROM `registered_institutions` WHERE `phone`='$phone'";
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

    $emailCheckSql = "SELECT * FROM `registered_institutions` WHERE `email`='$email'";
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

    $location = mysqli_real_escape_string($conn, $inputData['location']);
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
    $status = 1;
    $prefix = substr($cleanName, 0, 2) . substr($cleanName, -1);
    $randomNumber = rand(100, 999);
    $institutionId = strtoupper($prefix . $randomNumber);
    $password = bin2hex(random_bytes(6));
    $hashPass = password_hash($password, PASSWORD_DEFAULT);
    $userRole = "Institution Admin";

    $insertSql = "INSERT INTO `registered_institutions`(`inst_id`, `inst_name`, `phone`, `email`, `status`, `location`) VALUES ('$institutionId','$institutionName','$phone','$email','$status','$location')";
    $insertResult = mysqli_query($conn, $insertSql);

    $adminAddSql = "INSERT INTO `admin_users`(`name`, `email`, `phone`, `password`, `status`, `user_role`) VALUES ('$institutionName','$email','$phone','$hashPass','$status','$userRole')";
    $adminAddResult = mysqli_query($conn, $adminAddSql);

    if ($insertResult && $adminAddResult) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = 'mail.ticketbay.in';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'noreply@ticketbay.in';
            $mail->Password   = 'abhay$ticketbay@2024';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet = 'UTF-8';

            $mail->isHTML(true);
            $mail->setFrom('noreply@ticketbay.in', 'noreply@ticketbay.in');
            $mail->addAddress("$email", 'User');
            $mail->Subject = 'Account created successfully 📜📜📜';
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
                                                        <div class="logo" style="position: relative; text-align: center;"><img src="https://ticketbay.in/Backend/Images/Logo.png" alt="Logo" style="width: 30px;"></div>
                                                        <div class="body_message" style="position: relative; margin-top: 15px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #222; font-size: 15px; line-height: 1.4;">Hello,</span>
                                                            </p> 
                                                        </div>
                                                        <div style="position: relative; margin-top: 2px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">Your institution has been registered <b>' . $institutionName . '</b>. You can signin now as the admin of the institution in <a href="superadmin.ticketbay.in" style="color: #FC6736;" >superadmin.ticketbay.in</a> with the credentials:</span>
                                                            </p>
                                                        </div>
                                                        <div style="position: relative; margin-top: 6px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">User ID: <b>' . $email . ' /</b> <b>' . $phone . ' /</b></span>
                                                            </p>
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">Password: <b>' . $password . '</b></span>
                                                            </p>
                                                        </div>
                                                        <div style="position: relative; margin-top: 6px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">Later you can change the password by self.</span>
                                                            </p>
                                                        </div>
                                                        <div style="position: relative; margin-top: 30px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">Thanks & Regards,</span>
                                                            </p>
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: cursive; color: #fc6736; font-size: 18px; line-height: 1.4;"><b>Shetty Ticket Counter Pvt. Ltd.</b></span>
                                                            </p>
                                                        </div>
                                                        <div style="position: relative; margin-top: 15px;">
                                                            <p style="position: relative;"><b style="position: relative; font-family: sans-serif; font-size: 13px; color: #f00;">*NOTE:- Please do not share this message with anyone else.</b></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </body>
                                        </html>';
            $mail->send();
            $data = [
                'status' => 200,
                'message' => 'Institution added successfully.'
            ];
            header("HTTP/1.0 200 OK");
            echo json_encode($data);
        } catch (Exception $e) {
            $data = [
                'status' => 500,
                'message' => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}",
            ];
            header("HTTP/1.0 500 Message could not be sent");
        }
    } else {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
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
