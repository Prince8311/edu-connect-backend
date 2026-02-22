<?php

date_default_timezone_set('Asia/Kolkata');
require "../../utils/headers.php";
require "../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['current_token']) {
    $data = [
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ];
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode($data);
    exit;
}

if ($requestMethod === 'GET') {
    require "../../_db-connect.php";
    global $conn;

    $currentToken = $authResult['current_token'];

    $escapedToken = mysqli_real_escape_string($conn, $currentToken);
    $userSql = "SELECT * FROM `admin_users` WHERE `auth_token`='$escapedToken'";
    $userResult = mysqli_query($conn, $userSql);

    $tokenRow = mysqli_fetch_assoc($userResult);
    $userId = $tokenRow['id'];
    $currentTime = time();

    if (mysqli_num_rows($userResult) === 0) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Invalid token'
        ];
    }

    // ------------------------------------------------
    // TOKEN EXPIRED → Extract user data and refresh
    // ------------------------------------------------

    // Decode: base64 → "json | salt"
    $decoded = base64_decode($currentToken);
    list($jsonPayload, $salt) = explode('|', $decoded, 2);

    $payload = json_decode($jsonPayload, true);

    if (!$payload || !isset($payload['id'])) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Expired token corrupted'
        ];
    }

    $newRandom = bin2hex(random_bytes(64));
    $newData   = json_encode($payload) . "|" . $newRandom;
    $newToken  = base64_encode($newData);

    // Update DB token
    $newExpiry = date("Y-m-d H:i:s", time() + 86400);
    $updateSql = "UPDATE `admin_users` SET `auth_token`='$newToken',`expires_at`='$newExpiry' WHERE `id`='$userId'";
    mysqli_query($conn, $updateSql);

    setcookie(
        "authToken",
        $newToken,
        [
            'expires' => time() + 86400,
            'path' => '/',
            'domain' => 'ticketbay.in',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None'
        ]
    );

    $data = [
        'status' => 200,
        'message' => 'Token refreshed successfully.',
        'newToken' => $newToken
    ];

    header("HTTP/1.0 200 OK");
    echo json_encode($data);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
