<?php

require "../../utils/headers.php";

if ($requestMethod === 'POST') {
    require "../../_db-connect.php";
    global $conn;

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (!empty($inputData)) {
        $user = mysqli_real_escape_string($conn, $inputData['name']);
        $loginByOtp = isset($inputData['loginByOtp']) ? (bool)$inputData['loginByOtp'] : false;

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
            $userName = $data['name'];
            $userEmail = $data['email'];
            $userType = $data['user_type'];
            $userRole = $data['user_role'];
            if ($userType != "super_admin" && $userType != "employee") {
                $data = [
                    'status' => 400,
                    'message' => 'Authentication denied.',
                    'userType' => $userType
                ];
                header("HTTP/1.0 400 Forbidden");
                echo json_encode($data);
                exit;
            }
            if ($loginByOtp) {
                $otp = mysqli_real_escape_string($conn, $inputData['otp']);
                if (empty(trim($otp))) {
                    $data = [
                        'status' => 400,
                        'message' => 'OTP is required.'
                    ];
                    header("HTTP/1.0 400 Bad Request");
                    echo json_encode($data);
                    exit;
                }

                $savedOtp = $data['mail_otp'];
                if ($savedOtp === null) {
                    $data = [
                        'status' => 401,
                        'message' => 'Authentication error.'
                    ];
                    header("HTTP/1.0 401 Authentication error");
                    echo json_encode($data);
                    exit;
                }

                if ($savedOtp == $otp) {
                    $payload = [
                        'id' => $userId,
                        'name' => $userName,
                        'email' => $userEmail,
                        'phone' => $userPhone,
                        'type' => $userType,
                        'role' => $userRole,
                    ];
                    $jsonPayload = json_encode($payload);
                    $randomBytes = random_bytes(64);
                    $tokenData = $jsonPayload . '|' . bin2hex($randomBytes);
                    $authToken = base64_encode($tokenData);
                    $expiresAt = date("Y-m-d H:i:s", time() + 86400);

                    $updateUserSql = "UPDATE `admin_users` SET `auth_token`='$authToken', `expires_at`='$expiresAt' WHERE `id` = '$userId'";
                    $updateResult = mysqli_query($conn, $updateUserSql);

                    if ($updateResult) {
                        setcookie(
                            "authToken",
                            $authToken,
                            [
                                'expires' => time() + 86400,
                                'path' => '/',
                                'domain' => '.ticketbay.in',
                                'secure' => true,
                                'httponly' => true,
                                'samesite' => 'None'
                            ]
                        );
                        $data = [
                            'status' => 200,
                            'message' => 'Authentication Successful.',
                            'authToken' => $authToken
                        ];
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
                        'status' => 404,
                        'message' => 'Wrong OTP',
                    ];
                    header("HTTP/1.0 404 Wrong OTP");
                    echo json_encode($data);
                }
            } else {
                $password = mysqli_real_escape_string($conn, $inputData['password']);
                if (empty(trim($password))) {
                    $data = [
                        'status' => 400,
                        'message' => 'Password is required.'
                    ];
                    header("HTTP/1.0 400 Bad Request");
                    echo json_encode($data);
                    exit;
                }
                if (password_verify($password, $data['password'])) {
                    $payload = [
                        'id' => $userId,
                        'name' => $userName,
                        'email' => $userEmail,
                        'phone' => $userPhone,
                        'type' => $userType,
                        'role' => $userRole,
                    ];
                    $jsonPayload = json_encode($payload);
                    $randomBytes = random_bytes(64);
                    $tokenData = $jsonPayload . '|' . bin2hex($randomBytes);
                    $authToken = base64_encode($tokenData);
                    $expiresAt = date("Y-m-d H:i:s", time() + 86400);

                    $updateUserSql = "UPDATE `admin_users` SET `auth_token`='$authToken', `expires_at`='$expiresAt' WHERE `id` = '$userId'";
                    $updateResult = mysqli_query($conn, $updateUserSql);
                    if ($updateResult) {
                        setcookie(
                            "authToken",
                            $authToken,
                            [
                                'expires' => time() + 86400,
                                'path' => '/',
                                'domain' => '.ticketbay.in',
                                'secure' => true,
                                'httponly' => true,
                                'samesite' => 'None'
                            ]
                        );
                        $data = [
                            'status' => 200,
                            'message' => 'Authentication Successful.',
                            'authToken' => $authToken
                        ];
                        header("HTTP/1.0 200 Ok");
                        echo json_encode($data);
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
                        'status' => 400,
                        'message' => 'Invalid Credentials',
                    ];
                    header("HTTP/1.0 400 Forbidden");
                    echo json_encode($data);
                }
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
