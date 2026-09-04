<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

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

    $intent = isset($_GET['intent']) ? strtolower(trim($_GET['intent'])) : 'add';
    if ($intent !== 'add' && $intent !== 'update') {
        $data = [
            'status' => 400,
            'message' => 'Invalid intent. Allowed values are add/update.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if ($intent === 'update') {
        $institutionRowIdRaw = isset($inputData['id']) ? $inputData['id'] : '';
        $institutionRowId = (int)$institutionRowIdRaw;

        if ($institutionRowId <= 0) {
            $data = [
                'status' => 400,
                'message' => 'Valid inst_id (row id) is required for update intent.'
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }

        $phone = mysqli_real_escape_string($conn, isset($inputData['phone']) ? $inputData['phone'] : '');
        $recipientEmail = trim((string)(isset($inputData['email']) ? $inputData['email'] : ''));
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $data = ['status' => 400, 'message' => 'A valid email address is required.'];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }
        $email = mysqli_real_escape_string($conn, $recipientEmail);
        $status = isset($inputData['status']) ? (int)$inputData['status'] : 1;
        $city = mysqli_real_escape_string($conn, isset($inputData['city']) ? $inputData['city'] : '');
        $state = mysqli_real_escape_string($conn, isset($inputData['state']) ? $inputData['state'] : '');
        $latitude = mysqli_real_escape_string($conn, isset($inputData['latitude']) ? $inputData['latitude'] : '');
        $longitude = mysqli_real_escape_string($conn, isset($inputData['longitude']) ? $inputData['longitude'] : '');

        $institutionCheckSql = "SELECT `inst_name` FROM `institutions` WHERE `id`='$institutionRowId' LIMIT 1";
        $institutionCheckResult = mysqli_query($conn, $institutionCheckSql);
        if (!$institutionCheckResult || mysqli_num_rows($institutionCheckResult) === 0) {
            $data = [
                'status' => 404,
                'message' => 'Institution not found.'
            ];
            header("HTTP/1.0 404 Not Found");
            echo json_encode($data);
            exit;
        }

        $institutionRow = mysqli_fetch_assoc($institutionCheckResult);
        $institutionName = $institutionRow['inst_name'];

        $phoneCheckSql = "SELECT `id` FROM `institutions` WHERE `phone`='$phone' AND `id`!='$institutionRowIdRaw' LIMIT 1";
        $phoneCheckResult = mysqli_query($conn, $phoneCheckSql);
        if ($phoneCheckResult && mysqli_num_rows($phoneCheckResult) > 0) {
            $data = [
                'status' => 400,
                'message' => 'Contact no. already registered.'
            ];
            header("HTTP/1.0 400 Already exists");
            echo json_encode($data);
            exit;
        }

        $emailCheckSql = "SELECT `id` FROM `institutions` WHERE `email`='$email' AND `id`!='$institutionRowIdRaw' LIMIT 1";
        $emailCheckResult = mysqli_query($conn, $emailCheckSql);
        if ($emailCheckResult && mysqli_num_rows($emailCheckResult) > 0) {
            $data = [
                'status' => 400,
                'message' => 'Email address already registered.'
            ];
            header("HTTP/1.0 400 Already exists");
            echo json_encode($data);
            exit;
        }

        $emailReservation = reserveEmailSend($conn, $recipientEmail, 'institution_update');
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

        $updateSql = "UPDATE `institutions` SET `phone`='$phone', `email`='$email', `status`='$status', `city`='$city', `state`='$state', `latitude`='$latitude', `longitude`='$longitude' WHERE `id`='$institutionRowId'";
        $updateResult = mysqli_query($conn, $updateSql);

        if ($updateResult) {
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
                $mail->Subject = 'Institution details updated successfully 📜📜📜';
                $mail->Body    = '<!DOCTYPE html>
                                        <html lang="en">
                                            <head>
                                                <meta charset="UTF-8">
                                                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                                <link href="https://fonts.googleapis.com/css2?family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap" rel="stylesheet">
                                            </head>
                                            <body style="position: relative; margin: 0; padding: 0;">
                                                <div class="template_wrapper" style="position: relative; width: 100%;  padding: 10px; box-sizing: border-box; ">
                                                    <div class="template" style="position: relative; background: #FFF; padding: 20px; border-radius: 6px;" >
                                                        <div class="logo" style="position: relative; text-align: center;"><img src="https://api.educonnekt.in/images/logo.png" alt="Logo" style="width: 30px;"></div>
                                                        <div class="body_message" style="position: relative; margin-top: 15px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #222; font-size: 15px; line-height: 1.4;">Hello Sir/Madam,</span>
                                                            </p> 
                                                        </div>
                                                        <div style="position: relative; margin-top: 2px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">Your institution <b>' . $institutionName . '</b>, details has been updated successfully. Please login to your account and check at <a href="https://educonnekt.in" style="color: #0072C3;">educonnekt.in</a> with the credentials:</span>
                                                            </p>
                                                        </div>
                                                        <div style="position: relative; margin-top: 30px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">Thanks & Regards,</span>
                                                            </p>
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: cursive; color: #0072C3; font-size: 18px; line-height: 1.4;"><b>Shetty Ticket Counter Pvt. Ltd.</b></span>
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
                completeEmailSendReservation($conn, (int)$emailReservation['event_id'], true);

                $data = [
                    'status' => 200,
                    'message' => 'Institution updated successfully.'
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
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
        }
        exit;
    }

    $institutionName = mysqli_real_escape_string($conn, $inputData['institutionName']);
    $phone = mysqli_real_escape_string($conn, $inputData['phone']);
    $recipientEmail = trim((string)$inputData['email']);
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $data = ['status' => 400, 'message' => 'A valid email address is required.'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }
    $email = mysqli_real_escape_string($conn, $recipientEmail);

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

    $city = mysqli_real_escape_string($conn, $inputData['city']);
    $state = mysqli_real_escape_string($conn, $inputData['state']);
    $location = mysqli_real_escape_string($conn, $inputData['location']);
    $latitude = mysqli_real_escape_string($conn, $inputData['latitude']);
    $longitude = mysqli_real_escape_string($conn, $inputData['longitude']);
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
    $words = explode(" ", $institutionName);
    $initials = "";
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    $receiptPrefix = $initials;

    $emailReservation = reserveEmailSend($conn, $recipientEmail, 'institution_account');
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

    $adminAddSql = "INSERT INTO `admin_users`(`name`, `inst_id`, `email`, `phone`, `password`, `status`, `user_role`) VALUES ('Administrator','$institutionId','$email','$phone','$hashPass','$status','$userRole')";
    $adminAddResult = mysqli_query($conn, $adminAddSql);

    $insertSql = "INSERT INTO `institutions`(`inst_id`, `inst_name`, `phone`, `email`, `receipt_prefix`, `status`, `city`, `state`, `location`, `latitude`, `longitude`) VALUES ('$institutionId','$institutionName','$phone','$email','$receiptPrefix','$status','$city','$state','$location','$latitude','$longitude')";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult && $adminAddResult) {
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
                                                    <div class="template" style="position: relative; background: #FFF; padding: 20px; border-radius: 6px;" >
                                                        <div class="logo" style="position: relative; text-align: center;"><img src="https://api.educonnekt.in/images/logo.png" alt="Logo" style="width: 30px;"></div>
                                                        <div class="body_message" style="position: relative; margin-top: 15px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #222; font-size: 15px; line-height: 1.4;">Hello Sir/Madam,</span>
                                                            </p> 
                                                        </div>
                                                        <div style="position: relative; margin-top: 2px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">Your institution <b>' . $institutionName . '</b>, has been registered successfully. You can signin now as the admin of the institution in <a href="https://educonnekt.in" style="color: #0072C3;">educonnekt.in</a> with the credentials:</span>
                                                            </p>
                                                        </div>
                                                        <div style="position: relative; margin-top: 6px;">
                                                            <p style="position: relative;">
                                                                <span style="position: relative; font-family: sans-serif; color: #444; font-size: 15px; line-height: 1.4;">User ID: <b>' . $email . ' /</b> <b>' . $phone . '</b></span>
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
                                                                <span style="position: relative; font-family: cursive; color: #0072C3; font-size: 18px; line-height: 1.4;"><b>Shetty Ticket Counter Pvt. Ltd.</b></span>
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
            completeEmailSendReservation($conn, (int)$emailReservation['event_id'], true);
            $data = [
                'status' => 200,
                'message' => 'Institution added successfully.'
            ];
            header("HTTP/1.0 200 OK");
            echo json_encode($data);
        } catch (Exception $e) {
            completeEmailSendReservation($conn, (int)$emailReservation['event_id'], false);
            $data = [
                'status' => 500,
                'message' => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}",
            ];
            header("HTTP/1.0 500 Message could not be sen");
            echo json_encode($data);
        }
    } else {
        completeEmailSendReservation($conn, (int)$emailReservation['event_id'], false);
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
