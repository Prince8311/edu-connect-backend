<?php

require __DIR__ . "/../../utils/headers.php";

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../_db-connect.php";
    global $conn;

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (!empty($inputData)) {
        $user = mysqli_real_escape_string($conn, $inputData['name']);
        $loginByOtp = isset($inputData['loginByOtp']) ? (bool)$inputData['loginByOtp'] : false;

        $sql = "SELECT * FROM `users` WHERE `name`='$user' OR `email`='$user' OR `phone`='$user'";
        $result = mysqli_query($conn, $sql);

        if (!$result) {
            $response = [
                'success' => false,
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($response);
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
                $response = [
                    'success' => false,
                    'status' => 403,
                    'message' => 'Authentication denied.',
                    'userType' => $userType
                ];
                header("HTTP/1.0 403 Forbidden");
                echo json_encode($response);
                exit;
            }
            $authCheck = "SELECT * FROM `user_auth_tokens` WHERE `user_id`='$userId'";
            $authResult = mysqli_query($conn, $authCheck);
            if (!$authResult) {
                $response = [
                    'success' => false,
                    'status' => 500,
                    'message' => 'Database error: ' . mysqli_error($conn)
                ];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($response);
                exit;
            }
            $loginCount = mysqli_num_rows($authResult);
            if ($loginCount >= 5) {
                $response = [
                    'success' => false,
                    'status' => 403,
                    'message' => 'Maximum device limit reached. You are already logged in on 5 devices. Please log out from another device to continue.'
                ];
                header("HTTP/1.0 403 Forbidden");
                echo json_encode($response);
                exit;
            }
            if ($loginByOtp) {
                $otp = isset($inputData['otp']) ? trim($inputData['otp']) : null;
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
                $otp = mysqli_real_escape_string($conn, $inputData['otp']);
                if ($user === $userEmail) {
                    $savedOtp = $data['mail_otp'];
                    $expiryTime = $data['mail_otp_expires_at'];

                    if ($savedOtp === null) {
                        $response = [
                            'success' => false,
                            'status' => 401,
                            'message' => 'Authentication error',
                        ];
                        header("HTTP/1.0 401 Authentication error");
                        echo json_encode($response);
                        exit;
                    }

                    if (time() > strtotime($expiryTime)) {
                        $response = [
                            'success' => false,
                            'status' => 401,
                            'message' => 'OTP has expired. Please request a new one.'
                        ];
                        header("HTTP/1.0 401 Expired");
                        echo json_encode($response);
                        exit;
                    }

                    if ($savedOtp != $otp) {
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

                    $insertSql = "INSERT INTO `user_auth_tokens`(`user_id`, `auth_token`, `expires_at`) VALUES ('$userId','$authToken','$tokenExpiresAt')";
                    $insertResult = mysqli_query($conn, $insertSql);

                    if ($updateResult && $insertResult) {
                        $response = [
                            'success' => true,
                            'status' => 200,
                            'message' => 'Welcome back! You have successfully logged in.',
                            'data' => [
                                'user' => $payload,
                                'authToken' => $authToken
                            ],
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
                } else if ($user === $userPhone) {
                    $savedOtp = $data['phone_otp'];
                    $expiryTime = $data['phone_otp_expires_at'];

                    if ($savedOtp === null) {
                        $response = [
                            'success' => false,
                            'status' => 401,
                            'message' => 'Authentication error',
                        ];
                        header("HTTP/1.0 401 Authentication error");
                        echo json_encode($response);
                        exit;
                    }

                    if (time() > strtotime($expiryTime)) {
                        $response = [
                            'success' => false,
                            'status' => 401,
                            'message' => 'OTP has expired. Please request a new one.'
                        ];
                        header("HTTP/1.0 401 Expired");
                        echo json_encode($response);
                        exit;
                    }

                    if ($savedOtp != $otp) {
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

                    $insertSql = "INSERT INTO `user_auth_tokens`(`user_id`, `auth_token`, `expires_at`) VALUES ('$userId','$authToken','$tokenExpiresAt')";
                    $insertResult = mysqli_query($conn, $insertSql);

                    if ($updateResult && $insertResult) {
                        $response = [
                            'success' => true,
                            'status' => 200,
                            'message' => 'Welcome back! You have successfully logged in.',
                            'data' => [
                                'user' => $payload,
                                'authToken' => $authToken
                            ],
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
                $password = mysqli_real_escape_string($conn, $inputData['password']);
                $userPassword = $data['password'];

                if (password_verify($password, $userPassword)) {
                    $insertSql = "INSERT INTO `user_auth_tokens`(`user_id`, `auth_token`, `expires_at`) VALUES ('$userId','$authToken','$tokenExpiresAt')";
                    $insertResult = mysqli_query($conn, $insertSql);

                    if ($insertResult) {
                        $response = [
                            'success' => true,
                            'status' => 200,
                            'message' => 'Welcome back! You have successfully logged in.',
                            'data' => [
                                'user' => $payload,
                                'authToken' => $authToken
                            ],
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
                } else {
                    $response = [
                        'success' => false,
                        'status' => 400,
                        'message' => 'Incorrect password. Please try again.'
                    ];
                    header("HTTP/1.0 400 Forbidden");
                    echo json_encode($response);
                }
            }
        } else {
            $response = [
                'success' => false,
                'status' => 404,
                'message' => 'User not found.'
            ];
            header("HTTP/1.0 404 Not Found");
            echo json_encode($response);
            exit;
        }
    } else {
        $response = [
            'success' => false,
            'status' => 400,
            'message' => 'Bad Request: No input data provided.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($response);
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
