<?php
require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));

    $respond = function ($status, $message) {
        http_response_code($status);
        echo json_encode([
            'status' => $status,
            'message' => $message
        ]);
        exit;
    };

    if (!in_array($intent, ['add', 'update'], true)) {
        $respond(400, "Invalid 'intent'. Allowed values are add or update.");
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $respond(400, 'A valid JSON payload is required.');
    }

    $isPositiveInteger = function ($value) {
        return (is_int($value) || is_string($value))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    };

    foreach (['userId', 'routeId', 'stopageId'] as $field) {
        if (!$isPositiveInteger($input[$field] ?? null)) {
            $respond(400, "$field must be a positive integer.");
        }
    }

    if (!is_string($input['userType'] ?? null)) {
        $respond(400, 'userType is required.');
    }

    $allowedTypes = [
        'student' => 'Student',
        'teacher' => 'Teacher',
        'staff' => 'Staff'
    ];
    $typeKey = strtolower(trim($input['userType']));
    if (!isset($allowedTypes[$typeKey])) {
        $respond(400, 'userType must be Student, Teacher, or Staff.');
    }

    $instId = (string) $instituteId;
    $userId = (int) $input['userId'];
    $routeId = (int) $input['routeId'];
    $stopageId = (int) $input['stopageId'];
    $userType = $allowedTypes[$typeKey];
    $transactionStarted = false;

    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Could not start transaction.');
        }
        $transactionStarted = true;

        $passengerStatement = mysqli_prepare(
            $conn,
            'SELECT `user_id` FROM `transport_passengers` WHERE `inst_id` = ? AND `user_id` = ? AND `type` = ? LIMIT 1 FOR UPDATE'
        );
        if (!$passengerStatement) {
            throw new RuntimeException('Could not prepare passenger lookup.');
        }
        mysqli_stmt_bind_param($passengerStatement, 'sis', $instId, $userId, $userType);
        if (!mysqli_stmt_execute($passengerStatement)) {
            throw new RuntimeException('Could not check passenger.');
        }
        mysqli_stmt_store_result($passengerStatement);
        $passengerExists = mysqli_stmt_num_rows($passengerStatement) > 0;
        mysqli_stmt_close($passengerStatement);

        if ($intent === 'add' && $passengerExists) {
            mysqli_rollback($conn);
            $transactionStarted = false;
            $respond(409, 'Passenger is already assigned to transport.');
        }
        if ($intent === 'update' && !$passengerExists) {
            mysqli_rollback($conn);
            $transactionStarted = false;
            $respond(404, 'Passenger transport assignment not found.');
        }

        $routeStatement = mysqli_prepare(
            $conn,
            'SELECT `assigned_vehicle_id` FROM `transport_routes` WHERE `id` = ? AND `inst_id` = ? LIMIT 1'
        );
        if (!$routeStatement) {
            throw new RuntimeException('Could not prepare route lookup.');
        }
        mysqli_stmt_bind_param($routeStatement, 'is', $routeId, $instId);
        if (!mysqli_stmt_execute($routeStatement)) {
            throw new RuntimeException('Could not find route.');
        }
        mysqli_stmt_bind_result($routeStatement, $assignedVehicleId);
        if (!mysqli_stmt_fetch($routeStatement)) {
            mysqli_stmt_close($routeStatement);
            mysqli_rollback($conn);
            $transactionStarted = false;
            $respond(404, 'Route not found for this institute.');
        }
        mysqli_stmt_close($routeStatement);

        if ($assignedVehicleId === null || !$isPositiveInteger($assignedVehicleId)) {
            mysqli_rollback($conn);
            $transactionStarted = false;
            $respond(422, 'The selected route does not have an assigned vehicle.');
        }
        $assignedVehicleId = (int) $assignedVehicleId;

        if ($intent === 'add') {
            $saveStatement = mysqli_prepare(
                $conn,
                'INSERT INTO `transport_passengers` (`inst_id`, `user_id`, `type`, `stopage`, `route`, `assigned_vehicle`) VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$saveStatement) {
                throw new RuntimeException('Could not prepare passenger insert.');
            }
            mysqli_stmt_bind_param(
                $saveStatement,
                'sisiii',
                $instId,
                $userId,
                $userType,
                $stopageId,
                $routeId,
                $assignedVehicleId
            );
        } else {
            $saveStatement = mysqli_prepare(
                $conn,
                'UPDATE `transport_passengers` SET `stopage` = ?, `route` = ?, `assigned_vehicle` = ? WHERE `inst_id` = ? AND `user_id` = ? AND `type` = ?'
            );
            if (!$saveStatement) {
                throw new RuntimeException('Could not prepare passenger update.');
            }
            mysqli_stmt_bind_param(
                $saveStatement,
                'iiisis',
                $stopageId,
                $routeId,
                $assignedVehicleId,
                $instId,
                $userId,
                $userType
            );
        }

        if (!mysqli_stmt_execute($saveStatement)) {
            mysqli_stmt_close($saveStatement);
            throw new RuntimeException('Could not save passenger assignment.');
        }
        mysqli_stmt_close($saveStatement);

        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Could not commit transaction.');
        }
        $transactionStarted = false;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            mysqli_rollback($conn);
        }
        error_log('Transport passenger save failed: ' . $error->getMessage());
        $respond(500, 'Unable to save passenger due to a database error.');
    }

    $respond(
        200,
        $intent === 'add'
            ? 'Passenger assigned to transport successfully.'
            : 'Passenger transport assignment updated successfully.'
    );
} else {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ]);
}
