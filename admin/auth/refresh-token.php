<?php

date_default_timezone_set('Asia/Kolkata');
require "../../utils/headers.php";
require "../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if ($authResult['message'] !== 'Token expired') {
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
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);
    $currentToken = $authResult['token'];

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
    $updateSql = "UPDATE `admin_auth_tokens` SET `auth_token`='$newToken',`expires_at`='$newExpiry' WHERE `admin_id`='$userId' AND `auth_token`='$currentToken'";
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
