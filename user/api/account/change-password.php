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

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (empty($inputData)) {
        $response = [
            'success' => false,
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($response);
    }

    $password = mysqli_real_escape_string($conn, $inputData['password']);
    $newPassword = mysqli_real_escape_string($conn, $inputData['newPassword']);
    $confirmPassword = mysqli_real_escape_string($conn, $inputData['confirmPassword']);
    $hashPass = password_hash($newPassword, PASSWORD_DEFAULT);

    if ($password === $newPassword) {
        $response = [
            'success' => false,
            'status' => 403,
            'message' => 'New password should be different from the current one.'
        ];
        header("HTTP/1.0 403 Forbidden");
        echo json_encode($response);
    }

    if ($newPassword != $confirmPassword) {
        $response = [
            'success' => false,
            'status' => 403,
            'message' => 'Passwords not matched.'
        ];
        header("HTTP/1.0 403 Forbidden");
        echo json_encode($response);
    }

    $updateSql = "UPDATE `users` SET `password`='$hashPass' WHERE `id`='$userId'";
    $updateResult = mysqli_query($conn, $updateSql);

    if ($updateResult) {
        $response = [
            'success' => true,
            'status' => 200,
            'message' => 'Password changed successfully.'
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
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
