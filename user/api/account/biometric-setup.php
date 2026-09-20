<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = userAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $userId = (string) $authResult['userId'];
    $instId = (string) $authResult['inst_id'];
    
    $inputData = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($inputData)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 400, 'message' => 'A valid JSON object is required.']);
        exit;
    }

    foreach (['device_id', 'device_name', 'platform', 'device_token', 'biometric_type'] as $field) {
        if (!isset($inputData[$field]) || !is_string($inputData[$field]) || trim($inputData[$field]) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'status' => 400, 'message' => $field . ' must be a non-empty string.']);
            exit;
        }
    }

    if (!isset($inputData['password']) || !is_string($inputData['password']) || $inputData['password'] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 400, 'message' => 'password must be a non-empty string.']);
        exit;
    }

    $biometricType = $inputData['biometric_type'];
    if (!in_array($biometricType, ['fingerPrint', 'faceId'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 400, 'message' => 'biometric_type must be fingerPrint or faceId.']);
        exit;
    }

    $deviceId = trim($inputData['device_id']);
    $deviceName = trim($inputData['device_name']);
    $platform = trim($inputData['platform']);
    $fingerPrintEnabled = $biometricType === 'fingerPrint' ? 1 : 0;
    $faceIdEnabled = $biometricType === 'faceId' ? 1 : 0;
    // The client supplies a cryptographically random device secret, not a push notification token.
    // Hash the original bytes so future authentication can hash and compare the same secret.
    $deviceTokenHash = hash('sha256', $inputData['device_token']);

    try {
        $statement = mysqli_prepare($conn, 'SELECT `password` FROM `users` WHERE `id` = ? AND `inst_id` = ? LIMIT 1');
        if (!$statement || !mysqli_stmt_bind_param($statement, 'ss', $userId, $instId)
            || !mysqli_stmt_execute($statement) || !mysqli_stmt_bind_result($statement, $savedPasswordHash)) {
            throw new RuntimeException('Unable to look up user password.');
        }
        $userFound = mysqli_stmt_fetch($statement);
        mysqli_stmt_close($statement);
        if ($userFound === false) {
            throw new RuntimeException('Unable to read user password.');
        }

        // Verify the original password bytes against the stored password hash.
        if ($userFound !== true || !password_verify($inputData['password'], (string) $savedPasswordHash)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'status' => 401, 'message' => 'Incorrect password. Please try again.']);
            exit;
        }

        $statement = mysqli_prepare($conn, 'SELECT 1 FROM `user_devices` WHERE `inst_id` = ? AND `user_id` = ? AND `device_id` = ? LIMIT 1');
        if (!$statement || !mysqli_stmt_bind_param($statement, 'sss', $instId, $userId, $deviceId)
            || !mysqli_stmt_execute($statement) || !mysqli_stmt_store_result($statement)) {
            throw new RuntimeException('Unable to look up device.');
        }
        $deviceExists = mysqli_stmt_num_rows($statement) > 0;
        mysqli_stmt_close($statement);

        if ($deviceExists) {
            $statement = mysqli_prepare($conn, 'UPDATE `user_devices` SET `device_name` = ?, `platform` = ?, `finger_print_enabled` = ?, `face_id_enabled` = ?, `device_token` = ? WHERE `inst_id` = ? AND `user_id` = ? AND `device_id` = ?');
            if (!$statement || !mysqli_stmt_bind_param($statement, 'ssiissss', $deviceName, $platform, $fingerPrintEnabled, $faceIdEnabled, $deviceTokenHash, $instId, $userId, $deviceId)) {
                throw new RuntimeException('Unable to prepare device update.');
            }
        } else {
            $statement = mysqli_prepare($conn, 'INSERT INTO `user_devices` (`inst_id`, `user_id`, `device_id`, `device_name`, `platform`, `finger_print_enabled`, `face_id_enabled`, `device_token`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$statement || !mysqli_stmt_bind_param($statement, 'sssssiis', $instId, $userId, $deviceId, $deviceName, $platform, $fingerPrintEnabled, $faceIdEnabled, $deviceTokenHash)) {
                throw new RuntimeException('Unable to prepare device insert.');
            }
        }

        if (!mysqli_stmt_execute($statement)) {
            throw new RuntimeException('Unable to save device.');
        }
        mysqli_stmt_close($statement);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'status' => 200,
            'message' => 'Biometric setup saved successfully.',
        ]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['success' => false, 'status' => 500, 'message' => 'Unable to save biometric setup. Please try again.']);
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
