<?php

require __DIR__ . "/../../utils/headers.php";

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../_db-connect.php";
    global $conn;

    $inputData = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($inputData)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 400, 'message' => 'A valid JSON object is required.']);
        exit;
    }
    foreach (['device_id', 'device_token', 'biometric_type'] as $field) {
        if (!isset($inputData[$field]) || !is_string($inputData[$field]) || trim($inputData[$field]) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'status' => 400, 'message' => $field . ' must be a non-empty string.']);
            exit;
        }
    }
    if (!in_array($inputData['biometric_type'], ['fingerPrint', 'faceId'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 400, 'message' => 'biometric_type must be fingerPrint or faceId.']);
        exit;
    }
    $selectedUserId = null;
    if (array_key_exists('user_id', $inputData)) {
        if ((!is_int($inputData['user_id']) && !is_string($inputData['user_id']))
            || !preg_match('/^[1-9][0-9]*$/D', (string) $inputData['user_id'])
        ) {
            http_response_code(400);
            echo json_encode(['success' => false, 'status' => 400, 'message' => 'user_id must be a positive integer.']);
            exit;
        }
        $selectedUserId = (string) $inputData['user_id'];
    }

    require_once __DIR__ . '/../../utils/user-login-helper.php';
    $deviceId = trim($inputData['device_id']);
    $deviceTokenHash = hash('sha256', $inputData['device_token']);
    $column = $inputData['biometric_type'] === 'fingerPrint' ? 'finger_print_enabled' : 'face_id_enabled';

    try {
        // Only accounts matching the secret and enabled biometric can be selected.
        // DISTINCT prevents duplicate device registrations from duplicating an account.
        $statement = mysqli_prepare($conn, "SELECT DISTINCT u.`id`, u.`inst_id`, u.`name`, u.`email`, u.`phone`, u.`profile_image`, u.`user_type`, u.`status` FROM `user_devices` d INNER JOIN `users` u ON u.`id` = d.`user_id` AND u.`inst_id` = d.`inst_id` WHERE d.`device_id` = ? AND d.`device_token` = ? AND d.`$column` = 1");
        if (
            !$statement || !mysqli_stmt_bind_param($statement, 'ss', $deviceId, $deviceTokenHash)
            || !mysqli_stmt_execute($statement)
        ) {
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

        $userChoose = count($users) > 1;
        if ($userChoose && $selectedUserId === null) {
            $choices = array_map(static function (array $user): array {
                return [
                    'user_id' => $user['id'],
                    'inst_id' => $user['inst_id'],
                    'name' => $user['name'],
                    'profile_image' => $user['profile_image'],
                    'type' => $user['user_type'],
                ];
            }, $users);
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'status' => 200,
                'message' => 'Choose user',
                'data' => ['userChoose' => true, 'users' => $choices],
            ]);
            exit;
        }

        $user = null;
        foreach ($users as $candidate) {
            if ($selectedUserId === null || (string) $candidate['id'] === $selectedUserId) {
                $user = $candidate;
                break;
            }
        }
        if ($user === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'status' => 401, 'message' => 'The selected user does not match this biometric setup.']);
            exit;
        }
        if ((int) $user['status'] !== 1) {
            http_response_code(403);
            echo json_encode(['success' => false, 'status' => 403, 'message' => 'Your account is currently deactivated. Please contact your institution for assistance.']);
            exit;
        }

        $payload = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'profile_image' => $user['profile_image'],
            'type' => $user['user_type'],
        ];
        respondAfterSuccessfulAuthentication($conn, (int) $user['id'], $payload, $user['user_type'], ['userChoose' => $userChoose]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['success' => false, 'status' => 500, 'message' => 'Unable to complete biometric login. Please try again.']);
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
