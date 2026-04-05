<?php

require __DIR__ . "/../../utils/headers.php";
require __DIR__ . "/../../utils/middleware.php";

$authResult = userAuthenticateRequest();
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
    require __DIR__ . "/../../_db-connect.php";
    global $conn;

    $authToken = $authResult['token'];
    $sql = "DELETE FROM `user_auth_tokens` WHERE `auth_token`='$authToken'";
    $result = mysqli_query($conn, $sql);
    session_destroy();

    $data = [
        'success' => true,
        'status' => 200,
        'message' => 'Logged out successfully.',
    ];
    header("HTTP/1.0 200 OK");
    echo json_encode($data);
} else {
    $data = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
