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
            $userType = $data['user_type'];
            if ($userType != "student" && $userType != "teacher") {
                $data = [
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
                    'status' => 500,
                    'message' => 'Database error: ' . mysqli_error($conn)
                ];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($data);
            }
            $loginCount = mysqli_num_rows($authResult);
            if ($loginCount >= 5) {
                $data = [
                    'status' => 403,
                    'message' => 'Maximum device limit reached. You are already logged in on 5 devices. Please log out from another device to continue.'
                ];
                header("HTTP/1.0 403 Forbidden");
                echo json_encode($data);
                exit;
            }
            if ($loginByOtp) {
                $otp = mysqli_real_escape_string($conn, $inputData['otp']);
            } else {
            }
        } else {
            $data = [
                'status' => 404,
                'message' => 'User not found.'
            ];
            header("HTTP/1.0 404 Not Found");
            echo json_encode($data);
            exit;
        }
    } else {
        $data = [
            'status' => 400,
            'message' => 'Bad Request: No input data provided.'
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
