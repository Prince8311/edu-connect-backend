<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

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
        $userMail = $userData['email'];
        $isMailVerified = (bool)$userData['is_mail_verified'];
        $mailOtpExpiry = $userData['mail_otp_expires_at'];
        $userPhone = $userData['phone'];
        $isPhoneVerified = (bool)$userData['is_phone_verified'];
        $phoneOtpExpiry = $userData['phone_otp_expires_at'];

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

        $user = mysqli_real_escape_string($conn, $inputData['name']);
        $otp = mysqli_real_escape_string($conn, $inputData['otp']);

        if ($input === $userData['email'] || $input === $userData['phone']) {
            if (empty($otp)) {
                $response = [
                    'success' => false,
                    'status' => 400,
                    'message' => 'OTP is required',
                ];
                header("HTTP/1.0 400 Bad Request");
                echo json_encode($response);
                exit;
            }

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

                if (time() > strtotime($mailOtpExpiry)) {
                    $response = [
                        'success' => false,
                        'status' => 401,
                        'message' => 'OTP has expired. Please request a new one.'
                    ];
                    header("HTTP/1.0 401 Expired");
                    echo json_encode($response);
                    exit;
                }

                $savedMailOtp = $userData['mail_otp'];

                if ($savedMailOtp != $otp) {
                    $response = [
                        'success' => false,
                        'status' => 404,
                        'message' => 'Invalid OTP. Please try again.',
                    ];
                    header("HTTP/1.0 404 Wrong OTP");
                    echo json_encode($response);
                    exit;
                }

                $updateUserSql = "UPDATE `users` SET `mail_otp`=NULL, `mail_otp_expires_at`=NULL WHERE `id` = '$userId'";
                $updateResult = mysqli_query($conn, $updateUserSql);

                if ($updateResult) {
                    $response = [
                        'success' => true,
                        'status' => 200,
                        'message' => 'Email verified successfully.',
                    ];
                    header("HTTP/1.0 200 OK");
                    echo json_encode($response);
                } else {
                    $response = [
                        'success' => false,
                        'status' => 500,
                        'message' => 'Database error: ' . mysqli_error($conn)
                    ];
                    header("HTTP/1.0 500 Internal Server Error");
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

                if (time() > strtotime($mailOtpExpiry)) {
                    $response = [
                        'success' => false,
                        'status' => 401,
                        'message' => 'OTP has expired. Please request a new one.'
                    ];
                    header("HTTP/1.0 401 Expired");
                    echo json_encode($response);
                    exit;
                }

                $savedPhoneOtp = $userData['phone_otp'];

                if ($savedPhoneOtp != $otp) {
                    $response = [
                        'success' => false,
                        'status' => 404,
                        'message' => 'Invalid OTP. Please try again.',
                    ];
                    header("HTTP/1.0 404 Wrong OTP");
                    echo json_encode($response);
                    exit;
                }

                $updateUserSql = "UPDATE `users` SET `phone_otp`=NULL, `phone_otp_expires_at`=NULL WHERE `id` = '$userId'";
                $updateResult = mysqli_query($conn, $updateUserSql);

                if ($updateResult) {
                    $response = [
                        'success' => true,
                        'status' => 200,
                        'message' => 'Mobile no. verified successfully.',
                    ];
                    header("HTTP/1.0 200 OK");
                    echo json_encode($response);
                } else {
                    $response = [
                        'success' => false,
                        'status' => 500,
                        'message' => 'Database error: ' . mysqli_error($conn)
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
