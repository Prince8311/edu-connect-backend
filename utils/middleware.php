<?php

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/auth-helper.php';
require_once __DIR__ . '/../_db-connect.php';

function adminAuthenticateRequest()
{
    $cookieToken = $_COOKIE['authToken'] ?? '';
    $authHeader  = getAuthorizationHeader();
    $frontendToken = null;

    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $frontendToken = $matches[1];
    }

    // 1. Cookie empty & Header empty → NOT authenticated
    if (empty($cookieToken) && empty($frontendToken)) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Authentication error',
            'cookieToken' => $cookieToken,
            'frontendToken' => $frontendToken
        ];
    }

    // 2. Cookie present & Header present → Must match
    if (!empty($cookieToken) && !empty($frontendToken)) {
        if ($cookieToken !== $frontendToken) {
            return [
                'authenticated' => false,
                'status' => 401,
                'message' => 'Authentication mismatch',
                'cookieToken' => $cookieToken,
                'frontendToken' => $frontendToken
            ];
        }
    }

    // 3. Token expired 
    if (empty($cookieToken) && !empty($frontendToken)) {
        return [
            'authenticated' => false,
            'status' => 401,
            'message' => 'Token expired',
            'current_token' => $frontendToken
        ];
    }

    return [
        'authenticated' => true,
        'token' => $cookieToken
    ];
}
