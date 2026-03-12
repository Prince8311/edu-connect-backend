<?php

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/../_db-connect.php';

function adminAuthenticateRequest()
{
    global $conn;
    $authHeader  = getAuthorizationHeader();
    $token = null;

    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
    }

    if (empty($token)) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Authentication required'
        ];
    }

    $escapedToken = mysqli_real_escape_string($conn, $token);
    $sql = "SELECT * FROM `admin_auth_tokens` WHERE `auth_token`='$escapedToken'";
    $result = mysqli_query($conn, $sql);

    if (mysqli_num_rows($result) === 0) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Invalid token'
        ];
    }

    $row = mysqli_fetch_assoc($result);

    if (time() > strtotime($row['expires_at'])) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Token expired'
        ];
    }

    return [
        'authenticated' => true,
        'token' => $token,
        'userId' => $row['admin_id']
    ];
}
