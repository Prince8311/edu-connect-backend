<?php

require __DIR__ . "/../../utils/headers.php";

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../_db-connect.php";
    global $conn;

    function generateTokenFromPayload(array $payload): string
    {
        $jsonPayload = json_encode($payload);
        $randomBytes = random_bytes(64);
        $tokenData = $jsonPayload . '|' . bin2hex($randomBytes);

        return base64_encode($tokenData);
    }

    function extractPayloadFromToken(string $token): ?array
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }

        $tokenParts = explode('|', $decoded, 2);
        if (count($tokenParts) !== 2 || empty($tokenParts[0])) {
            return null;
        }

        $payload = json_decode($tokenParts[0], true);
        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (empty($inputData)) {
        $response = [
            'success' => false,
            'status' => 400,
            'message' => 'Bad Request: No input data provided.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($response);
        exit;
    }

    $tempToken = isset($inputData['tempToken']) ? trim($inputData['tempToken']) : '';
    $role = isset($inputData['role']) ? strtolower(trim($inputData['role'])) : '';

    if ($tempToken === '') {
        $response = [
            'success' => false,
            'status' => 400,
            'message' => 'Token is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($response);
        exit;
    }

    if ($role !== 'teacher' && $role !== 'guardian') {
        $response = [
            'success' => false,
            'status' => 403,
            'message' => 'Role is not allowed.'
        ];
        header("HTTP/1.0 403 Forbidden");
        echo json_encode($response);
        exit;
    }

    $escapedTempToken = mysqli_real_escape_string($conn, $tempToken);
    $tokenSql = "SELECT `user_id`, `temp_token_expiry` FROM `user_auth_tokens` WHERE `temp_token`='$escapedTempToken' LIMIT 1";
    $tokenResult = mysqli_query($conn, $tokenSql);

    if (!$tokenResult) {
        $response = [
            'success' => false,
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($response);
        exit;
    }

    if (mysqli_num_rows($tokenResult) !== 1) {
        $response = [
            'success' => false,
            'status' => 401,
            'message' => 'Invalid token.'
        ];
        header("HTTP/1.0 401 Unauthorized");
        echo json_encode($response);
        exit;
    }

    $tokenRow = mysqli_fetch_assoc($tokenResult);
    $tempTokenExpiry = $tokenRow['temp_token_expiry'];

    if ($tempTokenExpiry === null || time() > strtotime($tempTokenExpiry)) {
        $response = [
            'success' => false,
            'status' => 401,
            'message' => 'Session has expired. Please login again.'
        ];
        header("HTTP/1.0 401 Unauthorized");
        echo json_encode($response);
        exit;
    }

    $payload = extractPayloadFromToken($tempToken);
    if ($payload === null) {
        $response = [
            'success' => false,
            'status' => 401,
            'message' => 'Invalid token payload.'
        ];
        header("HTTP/1.0 401 Unauthorized");
        echo json_encode($response);
        exit;
    }

    $payload['type'] = $role;

    if ($role === 'teacher') {
        $authToken = generateTokenFromPayload($payload);
        $authTokenExpiry = date("Y-m-d H:i:s", time() + 86400);
        $escapedRole = mysqli_real_escape_string($conn, $role);
        $escapedAuthToken = mysqli_real_escape_string($conn, $authToken);
        $updateSql = "UPDATE `user_auth_tokens` SET `user_type`='$escapedRole', `temp_token`=NULL, `temp_token_expiry`=NULL, `auth_token`='$escapedAuthToken', `expires_at`='$authTokenExpiry' WHERE `temp_token`='$escapedTempToken'";
        $updateResult = mysqli_query($conn, $updateSql);

        if (!$updateResult) {
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
            'message' => 'Welcome back! You have successfully logged in.',
            'data' => [
                'next_screen' => 'home',
                'user' => $payload,
                'authToken' => $authToken
            ],
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($response);
        exit;
    }

    $newTempToken = generateTokenFromPayload($payload);
    $newTempTokenExpiry = date("Y-m-d H:i:s", time() + 604800);
    $escapedRole = mysqli_real_escape_string($conn, $role);
    $escapedNewTempToken = mysqli_real_escape_string($conn, $newTempToken);
    $updateSql = "UPDATE `user_auth_tokens` SET `user_type`='$escapedRole', `temp_token`='$escapedNewTempToken', `temp_token_expiry`='$newTempTokenExpiry', `auth_token`=NULL, `expires_at`=NULL WHERE `temp_token`='$escapedTempToken'";
    $updateResult = mysqli_query($conn, $updateSql);

    if (!$updateResult) {
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
        'message' => 'Role selected successfully. Please select a student to continue.',
        'data' => [
            'next_screen' => 'selectStudent',
            'tempToken' => $newTempToken
        ],
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
