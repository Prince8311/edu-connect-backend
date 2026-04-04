<?php

require "../../../utils/headers.php";

if ($requestMethod === 'POST') {
    require "../../../_db-connect.php";
    global $conn;

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (!empty($inputData)) {
        $user = mysqli_real_escape_string($conn, $inputData['name']);
        $loginByOtp = isset($inputData['loginByOtp']) ? (bool)$inputData['loginByOtp'] : false;

        $sql = "SELECT * FROM `users` WHERE `name`='$user' OR `email`='$user' OR `phone`='$user'";
        $result = mysqli_query($conn, $sql);

        if (!$result) {
            $data = [
                'success' => false,
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        if (mysqli_num_rows($result) === 1) {
            $data = mysqli_fetch_assoc($result);
            $userId = $data['id'];
            $userName = $data['name'];
            $userEmail = $data['email'];
            $userPhone = $data['phone'];
            $userType = $data['user_type'];
            $payload = [
                'id' => $userId,
                'name' => $userName,
                'email' => $userEmail,
                'phone' => $userPhone,
                'type' => $userType,
            ];
            $jsonPayload = json_encode($payload);
            $randomBytes = random_bytes(64);
            $tokenData = $jsonPayload . '|' . bin2hex($randomBytes);
            $authToken = base64_encode($tokenData);
            $tokenExpiresAt = date("Y-m-d H:i:s", time() + 86400);

            if ($userType != "student" && $userType != "teacher") {
                $data = [
                    'success' => false,
                    'status' => 403,
                    'message' => 'Authentication denied.',
                    'userType' => $userType
                ];
                header("HTTP/1.0 403 Forbidden");
                echo json_encode($data);
                exit;
            }
            $authCheck = "SELECT * FROM `user_auth_tokens` WHERE `user_id`='$userId'";
            $authResult = mysqli_query($conn, $authCheck);
            if (!$authResult) {
                $data = [
                    'success' => false,
                    'status' => 500,
                    'message' => 'Database error: ' . mysqli_error($conn)
                ];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($data);
                exit;
            }
            $loginCount = mysqli_num_rows($authResult);
            if ($loginCount >= 5) {
                $data = [
                    'success' => false,
                    'status' => 403,
                    'message' => 'Maximum device limit reached. You are already logged in on 5 devices. Please log out from another device to continue.'
                ];
                header("HTTP/1.0 403 Forbidden");
                echo json_encode($data);
                exit;
            }
            if ($loginByOtp) {
                $otp = mysqli_real_escape_string($conn, $inputData['otp']);
                if ($user === $userEmail) {
                    $savedOtp = $data['mail_otp'];
                    $expiryTime = $data['mail_otp_expires_at'];

                    if ($savedOtp === null) {
                        $data = [
                            'success' => false,
                            'status' => 401,
                            'message' => 'Authentication error',
                            'userId' => $userId
                        ];
                        header("HTTP/1.0 401 Authentication error");
                        echo json_encode($data);
                        exit;
                    }

                    if (time() > strtotime($expiryTime)) {
                        $data = [
                            'success' => false,
                            'status' => 401,
                            'message' => 'OTP has expired. Please request a new one.'
                        ];
                        header("HTTP/1.0 401 Expired");
                        echo json_encode($data);
                        exit;
                    }

                    if ($savedOtp != $otp) {
                        $data = [
                            'success' => false,
                            'status' => 404,
                            'message' => 'Invalid OTP. Please try again.',
                        ];
                        header("HTTP/1.0 404 Wrong OTP");
                        echo json_encode($data);
                        exit;
                    }

                    $updateUserSql = "UPDATE `users` SET `mail_otp`=NULL, `mail_otp_expires_at`=NULL WHERE `id` = '$userId'";
                    $updateResult = mysqli_query($conn, $updateUserSql);

                    $insertSql = "INSERT INTO `user_auth_tokens`(`user_id`, `auth_token`, `expires_at`) VALUES ('$userId','$authToken','$tokenExpiresAt')";
                    $insertResult = mysqli_query($conn, $insertSql);

                    if ($updateResult && $insertResult) {
                        $data = [
                            'success' => true,
                            'status' => 200,
                            'message' => 'Welcome back! You have successfully logged in.',
                        ];
                        header("HTTP/1.0 200 OK");
                        echo json_encode($data);
                    } else {
                        $data = [
                            'success' => false,
                            'status' => 500,
                            'message' => 'Database error: ' . mysqli_error($conn)
                        ];
                        header("HTTP/1.0 500 Internal Server Error");
                        echo json_encode($data);
                    }
                } else if ($user === $userPhone) {
                    $savedOtp = $data['phone_otp'];
                    $expiryTime = $data['phone_otp_expires_at'];

                    if ($savedOtp === null) {
                        $data = [
                            'success' => false,
                            'status' => 401,
                            'message' => 'Authentication error',
                            'userId' => $userId
                        ];
                        header("HTTP/1.0 401 Authentication error");
                        echo json_encode($data);
                        exit;
                    }

                    if (time() > strtotime($expiryTime)) {
                        $data = [
                            'success' => false,
                            'status' => 401,
                            'message' => 'OTP has expired. Please request a new one.'
                        ];
                        header("HTTP/1.0 401 Expired");
                        echo json_encode($data);
                        exit;
                    }

                    if ($savedOtp != $otp) {
                        $data = [
                            'success' => false,
                            'status' => 404,
                            'message' => 'Invalid OTP. Please try again.',
                        ];
                        header("HTTP/1.0 404 Wrong OTP");
                        echo json_encode($data);
                        exit;
                    }

                    $updateUserSql = "UPDATE `users` SET `phone_otp`=NULL, `phone_otp_expires_at`=NULL WHERE `id` = '$userId'";
                    $updateResult = mysqli_query($conn, $updateUserSql);

                    $insertSql = "INSERT INTO `user_auth_tokens`(`user_id`, `auth_token`, `expires_at`) VALUES ('$userId','$authToken','$tokenExpiresAt')";
                    $insertResult = mysqli_query($conn, $insertSql);

                    if ($updateResult && $insertResult) {
                        $data = [
                            'success' => true,
                            'status' => 200,
                            'message' => 'Welcome back! You have successfully logged in.',
                        ];
                        header("HTTP/1.0 200 OK");
                        echo json_encode($data);
                    } else {
                        $data = [
                            'success' => false,
                            'status' => 500,
                            'message' => 'Database error: ' . mysqli_error($conn)
                        ];
                        header("HTTP/1.0 500 Internal Server Error");
                        echo json_encode($data);
                    }
                }
            } else {
                $password = mysqli_real_escape_string($conn, $inputData['password']);
                $userPassword = $data['password'];

                if (password_verify($password, $userPassword)) {
                    $data = [
                        'success' => true,
                        'status' => 200,
                        'message' => 'Welcome back! You have successfully logged in.',
                    ];
                    header("HTTP/1.0 200 OK");
                    echo json_encode($data);
                } else {
                    $data = [
                        'success' => false,
                        'status' => 400,
                        'message' => 'Incorrect password. Please try again.'
                    ];
                    header("HTTP/1.0 400 Forbidden");
                    echo json_encode($data);
                }
            }
        } else {
            $data = [
                'success' => false,
                'status' => 404,
                'message' => 'User not found.'
            ];
            header("HTTP/1.0 404 Not Found");
            echo json_encode($data);
            exit;
        }
    } else {
        $data = [
            'success' => false,
            'status' => 400,
            'message' => 'Bad Request: No input data provided.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }
} else {
    $data = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
