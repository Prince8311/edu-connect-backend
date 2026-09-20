<?php

function normalizeUserPayload(array $payload): array
{
    foreach (['id', 'student'] as $key) {
        if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
            $payload[$key] = (int) $payload[$key];
        }
    }

    return $payload;
}

function normalizeUserRoles(string $userType): array
{
    $roles = array_map('trim', explode(',', strtolower($userType)));
    $roles = array_values(array_filter($roles, static function ($role) {
        return $role !== '';
    }));

    return array_values(array_unique($roles));
}

function generateTokenFromPayload(array $payload): string
{
    $payload = normalizeUserPayload($payload);
    $jsonPayload = json_encode($payload);
    $randomBytes = random_bytes(64);
    $tokenData = $jsonPayload . '|' . bin2hex($randomBytes);

    return base64_encode($tokenData);
}

function respondAfterSuccessfulAuthentication(mysqli $conn, int $userId, array $payload, string $userType, array $responseData = []): void
{
    $payload = normalizeUserPayload($payload);
    $roles = normalizeUserRoles($userType);
    sort($roles);

    if (count($roles) === 1 && ($roles[0] === 'student' || $roles[0] === 'teacher')) {
        $activeRole = $roles[0];
        $authToken = generateTokenFromPayload($payload);
        $tokenExpiresAt = date("Y-m-d H:i:s", time() + 86400);

        $authCheck = "SELECT * FROM `user_auth_tokens` WHERE `user_id`='$userId'";
        $authResult = mysqli_query($conn, $authCheck);
        if (!$authResult) {
            $response = [
                'success' => false,
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($response);
            exit;
        }

        $loginCount = mysqli_num_rows($authResult);
        if ($loginCount >= 5000) {
            $response = [
                'success' => false,
                'status' => 403,
                'message' => 'Maximum device limit reached. You are already logged in on 5 devices. Please log out from another device to continue.'
            ];
            header("HTTP/1.0 403 Forbidden");
            echo json_encode($response);
            exit;
        }

        $insertSql = "INSERT INTO `user_auth_tokens`(`user_id`, `user_type`, `auth_token`, `expires_at`) VALUES ('$userId','$activeRole','$authToken','$tokenExpiresAt')";
        $insertResult = mysqli_query($conn, $insertSql);

        if ($insertResult) {
            $response = [
                'success' => true,
                'status' => 200,
                'message' => 'Welcome back! You have successfully logged in.',
                'data' => array_merge($responseData, [
                    'next_screen' => 'home',
                    'user' => $payload,
                    'authToken' => $authToken
                ]),
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
        exit;
    }

    if (count($roles) === 1 && $roles[0] === 'guardian') {
        $tempToken = generateTokenFromPayload($payload);
        $tempTokenExpiry = date("Y-m-d H:i:s", time() + 604800);
        $insertSql = "INSERT INTO `user_auth_tokens`(`user_id`, `user_type`, `temp_token`, `temp_token_expiry`) VALUES ('$userId','guardian','$tempToken','$tempTokenExpiry')";
        $insertResult = mysqli_query($conn, $insertSql);

        if (!$insertResult) {
            $response = [
                'success' => false,
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($response);
            exit;
        }

        $response = [
            'success' => true,
            'status' => 200,
            'message' => 'Please select a student to continue.',
            'data' => array_merge($responseData, [
                'next_screen' => 'selectStudent',
                'tempToken' => $tempToken
            ]),
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($response);
        exit;
    }

    if (in_array('guardian', $roles, true) && in_array('teacher', $roles, true)) {
        $tempToken = generateTokenFromPayload($payload);
        $tempTokenExpiry = date("Y-m-d H:i:s", time() + 604800);
        $insertSql = "INSERT INTO `user_auth_tokens`(`user_id`, `temp_token`, `temp_token_expiry`) VALUES ('$userId','$tempToken','$tempTokenExpiry')";
        $insertResult = mysqli_query($conn, $insertSql);

        if (!$insertResult) {
            $response = [
                'success' => false,
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($response);
            exit;
        }

        $response = [
            'success' => true,
            'status' => 200,
            'message' => 'Please select a role to continue.',
            'data' => array_merge($responseData, [
                'next_screen' => 'selectRole',
                'tempToken' => $tempToken
            ]),
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($response);
        exit;
    }

    $response = [
        'success' => false,
        'status' => 403,
        'message' => 'Authentication denied.',
        'userType' => $userType
    ];
    header("HTTP/1.0 403 Forbidden");
    echo json_encode($response);
    exit;
}

