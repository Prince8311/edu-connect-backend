<?php

require __DIR__ . "/../../utils/headers.php";

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../_db-connect.php";
    global $conn;

    foreach (['device_id', 'device_token', 'biometric_type'] as $field) {
        if (!isset($_GET[$field]) || !is_string($_GET[$field]) || trim($_GET[$field]) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'status' => 400, 'message' => $field . ' must be a non-empty string query parameter.']);
            exit;
        }
    }
    if (!in_array($_GET['biometric_type'], ['fingerPrint', 'faceId'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 400, 'message' => 'biometric_type must be fingerPrint or faceId.']);
        exit;
    }

    require_once __DIR__ . '/../../utils/user-login-helper.php';
    header('Cache-Control: no-store');
    $deviceId = trim($_GET['device_id']);
    $deviceTokenHash = hash('sha256', $_GET['device_token']);
    $column = $_GET['biometric_type'] === 'fingerPrint' ? 'finger_print_enabled' : 'face_id_enabled';

    try {
        // Match the same accounts as biometric login, without issuing any tokens.
        $statement = mysqli_prepare($conn, "SELECT DISTINCT u.`id`, u.`name`, u.`profile_image`, u.`user_type` FROM `user_devices` d INNER JOIN `users` u ON u.`id` = d.`user_id` AND u.`inst_id` = d.`inst_id` WHERE d.`device_id` = ? AND d.`device_token` = ? AND d.`$column` = 1 ORDER BY u.`id`");
        if (!$statement || !mysqli_stmt_bind_param($statement, 'ss', $deviceId, $deviceTokenHash)
            || !mysqli_stmt_execute($statement)) {
            throw new RuntimeException('Unable to look up biometric accounts.');
        }
        $result = mysqli_stmt_get_result($statement);
        if (!$result) {
            throw new RuntimeException('Unable to read biometric accounts.');
        }
        $users = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_stmt_close($statement);

        if (count($users) === 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'status' => 401, 'message' => 'Invalid device credentials or biometric setup is not enabled.']);
            exit;
        }

        foreach ($users as &$user) {
            $user['user_type'] = normalizeUserRoles((string) $user['user_type']);
        }
        unset($user);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'status' => 200,
            'message' => 'Biometric users retrieved successfully.',
            'data' => $users,
        ]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['success' => false, 'status' => 500, 'message' => 'Unable to retrieve biometric users. Please try again.']);
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
