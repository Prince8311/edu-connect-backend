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

    foreach (['device_id', 'device_token', 'biometric_type'] as $field) {
        if (!isset($inputData[$field]) || !is_string($inputData[$field]) || trim($inputData[$field]) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'status' => 400, 'message' => $field . ' must be a non-empty string.']);
            exit;
        }
    }

    $biometricType = $inputData['biometric_type'];
    if (!in_array($biometricType, ['fingerPrint', 'faceId'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'status' => 400, 'message' => 'biometric_type must be fingerPrint or faceId.']);
        exit;
    }

    $deviceId = trim($inputData['device_id']);
    $deviceTokenHash = hash('sha256', $inputData['device_token']);
    $transactionStarted = false;

    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Unable to start biometric reset.');
        }
        $transactionStarted = true;

        // Lock the matching row until the reset is committed.
        $statement = mysqli_prepare($conn, 'SELECT `id`, `finger_print_enabled`, `face_id_enabled` FROM `user_devices` WHERE `inst_id` = ? AND `user_id` = ? AND `device_id` = ? AND `device_token` = ? LIMIT 1 FOR UPDATE');
        if (!$statement || !mysqli_stmt_bind_param($statement, 'ssss', $instId, $userId, $deviceId, $deviceTokenHash)
            || !mysqli_stmt_execute($statement)
            || !mysqli_stmt_bind_result($statement, $rowId, $fingerPrintEnabled, $faceIdEnabled)) {
            throw new RuntimeException('Unable to look up device.');
        }
        $deviceFound = mysqli_stmt_fetch($statement);
        mysqli_stmt_close($statement);
        if ($deviceFound === false) {
            throw new RuntimeException('Unable to read device.');
        }
        if ($deviceFound !== true) {
            mysqli_rollback($conn);
            $transactionStarted = false;
            http_response_code(404);
            echo json_encode(['success' => false, 'status' => 404, 'message' => 'No matching biometric setup found.']);
            exit;
        }

        $fingerPrintEnabled = (int) $fingerPrintEnabled;
        $faceIdEnabled = (int) $faceIdEnabled;
        if ($biometricType === 'fingerPrint') {
            $fingerPrintEnabled = 0;
        } else {
            $faceIdEnabled = 0;
        }

        $deviceRemoved = $fingerPrintEnabled === 0 && $faceIdEnabled === 0;
        if ($deviceRemoved) {
            $statement = mysqli_prepare($conn, 'DELETE FROM `user_devices` WHERE `id` = ? AND `inst_id` = ? AND `user_id` = ? AND `device_id` = ? AND `device_token` = ?');
        } else {
            // The column is chosen only from the validated biometric types.
            $column = $biometricType === 'fingerPrint' ? 'finger_print_enabled' : 'face_id_enabled';
            $statement = mysqli_prepare($conn, "UPDATE `user_devices` SET `$column` = 0 WHERE `id` = ? AND `inst_id` = ? AND `user_id` = ? AND `device_id` = ? AND `device_token` = ?");
        }
        if (!$statement || !mysqli_stmt_bind_param($statement, 'sssss', $rowId, $instId, $userId, $deviceId, $deviceTokenHash)
            || !mysqli_stmt_execute($statement)) {
            throw new RuntimeException('Unable to reset device.');
        }
        mysqli_stmt_close($statement);
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Unable to commit biometric reset.');
        }
        $transactionStarted = false;

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'status' => 200,
            'message' => 'Biometric setup reset successfully.',
            'data' => [
                'device_removed' => $deviceRemoved,
                'finger_print_enabled' => $fingerPrintEnabled,
                'face_id_enabled' => $faceIdEnabled,
            ],
        ]);
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                mysqli_rollback($conn);
            } catch (Throwable $rollbackException) {
                // Preserve the JSON error response if the connection has failed.
            }
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'status' => 500, 'message' => 'Unable to reset biometric setup. Please try again.']);
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
