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
    $instId = mysqli_real_escape_string($conn, (string) $authResult['inst_id']);
    $userType = $authResult['user_type'];
    $currentToken = $authResult['token'] ?? null;

    if ($userType !== 'guardian') {
        $response = [
            'success' => false,
            'status' => 403,
            'message' => 'The selected role is not eligible for student selection. Please continue using the teacher role flow.'
        ];
        header("HTTP/1.0 403 Forbidden");
        echo json_encode($response);
        exit;
    }

    $inputData = json_decode(file_get_contents("php://input"), true);
    $studentId = isset($inputData['student_id']) ? (int) $inputData['student_id'] : 0;

    if ($studentId <= 0) {
        $response = [
            'success' => false,
            'status' => 400,
            'message' => 'student_id is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($response);
        exit;
    }

    $studentSql = "SELECT `id` FROM `students`
        WHERE `id` = '$studentId' AND `guardian_id` = '$userId' AND `inst_id` = '$instId'
        LIMIT 1";
    $studentResult = mysqli_query($conn, $studentSql);

    if (!$studentResult) {
        $response = [
            'success' => false,
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($response);
        exit;
    }

    if (mysqli_num_rows($studentResult) !== 1) {
        $response = [
            'success' => false,
            'status' => 403,
            'message' => 'The selected student is not associated with your guardian account. Please choose a valid student profile.'
        ];
        header("HTTP/1.0 403 Forbidden");
        echo json_encode($response);
        exit;
    }

    $userSql = "SELECT `id`, `name`, `profile_image`, `email`, `phone`, `user_type`
        FROM `users` WHERE `id` = '$userId' AND `inst_id` = '$instId' LIMIT 1";
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

    if (mysqli_num_rows($userResult) !== 1) {
        $response = [
            'success' => false,
            'status' => 404,
            'message' => 'User not found.'
        ];
        header("HTTP/1.0 404 Not Found");
        echo json_encode($response);
        exit;
    }

    $userData = mysqli_fetch_assoc($userResult);
    $payload = [
        'id' => (int) $userData['id'],
        'name' => $userData['name'],
        'email' => $userData['email'],
        'phone' => $userData['phone'],
        'profile_image' => $userData['profile_image'] ?? null,
        'type' => $userData['user_type'],
        'student' => $studentId
    ];

    $jsonPayload = json_encode($payload);
    $randomBytes = random_bytes(64);
    $tokenData = $jsonPayload . '|' . bin2hex($randomBytes);
    $authToken = base64_encode($tokenData);
    $authTokenExpiry = date("Y-m-d H:i:s", time() + 86400);

    $escapedCurrentToken = mysqli_real_escape_string($conn, (string) $currentToken);
    $escapedAuthToken = mysqli_real_escape_string($conn, $authToken);
    $updateSql = "UPDATE `user_auth_tokens`
        SET `student_id` = '$studentId', `auth_token` = '$escapedAuthToken', `expires_at` = '$authTokenExpiry'
        WHERE `auth_token` = '$escapedCurrentToken' AND `user_id` = '$userId'";
    $updateResult = mysqli_query($conn, $updateSql);

    if (!$updateResult || mysqli_affected_rows($conn) !== 1) {
        $response = [
            'success' => false,
            'status' => 500,
            'message' => $updateResult
                ? 'Unable to update the authenticated session.'
                : 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($response);
        exit;
    }

    $response = [
        'success' => true,
        'status' => 200,
        'message' => 'Student switched successfully.',
        'data' => [
            'user' => $payload,
            'authToken' => $authToken
        ]
    ];
    header("HTTP/1.0 200 OK");
    echo json_encode($response);
    exit;
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
