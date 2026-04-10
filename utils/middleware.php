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
            'message' => 'Invalid token',
        ];
    }

    $row = mysqli_fetch_assoc($result);

    if (time() > strtotime($row['expires_at'])) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Token expired',
            'token' => $token,
            'userId' => $row['admin_id']
        ];
    }

    $userId = $row['admin_id'];
    $userSql = "SELECT `id`, `user_type`, `inst_id` FROM `admin_users` WHERE `id` = '$userId' LIMIT 1";
    $userResult = mysqli_query($conn, $userSql);

    if (!$userResult || mysqli_num_rows($userResult) === 0) {
        return [
            'authenticated' => false,
            'status' => 404,
            'message' => 'User not found'
        ];
    }

    $user = mysqli_fetch_assoc($userResult);

    if ($user['user_type'] === 'inst_admin') {
        if (empty($user['inst_id'])) {
            return [
                'authenticated' => false,
                'status' => 404,
                'message' => 'Institute not found'
            ];
        }

        $instId = $user['inst_id'];
        $instCheckSql = "SELECT `inst_id` FROM `institutions` WHERE `inst_id` = '$instId' LIMIT 1";
        $instResult = mysqli_query($conn, $instCheckSql);

        if (!$instResult || mysqli_num_rows($instResult) === 0) {
            return [
                'authenticated' => false,
                'status' => 404,
                'message' => 'Institute not found'
            ];
        }
    }

    return [
        'authenticated' => true,
        'token' => $token,
        'userId' => $userId,
        'user_type' => $user['user_type'],
        'inst_id' => $user['inst_id'] ?? null
    ];
}

function userAuthenticateRequest()
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
    $sql = "SELECT * FROM `user_auth_tokens` WHERE `auth_token`='$escapedToken'";
    $result = mysqli_query($conn, $sql);

    if (mysqli_num_rows($result) === 0) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Invalid token',
        ];
    }

    $row = mysqli_fetch_assoc($result);

    if (time() > strtotime($row['expires_at'])) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Token expired',
            'token' => $token,
            'userId' => $row['user_id']
        ];
    }

    return [
        'authenticated' => true,
        'token' => $token,
        'userId' => $row['user_id']
    ];
}
