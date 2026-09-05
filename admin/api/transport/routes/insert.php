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
    $intent = strtolower(trim($_GET['intent'] ?? 'add'));

    if (!in_array($intent, ['add', 'update'], true)) {
        $data = [
            'status' => 400,
            'message' => 'Invalid intent. Allowed values: add, update.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $respond = function ($status, $message) {
        http_response_code($status);
        echo json_encode(['status' => $status, 'message' => $message]);
        exit;
    };
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input)) {
        $respond(400, 'A non-empty JSON object is required.');
    }
    if (empty($instituteId)) {
        $respond(422, 'Institute ID is missing from authentication.');
    }
    $fields = ['routeName', 'vehicleId', 'staffs', 'startTime', 'endTime', 'stopages'];
    foreach ($fields as $field) {
        if ($intent === 'add' && !array_key_exists($field, $input)) {
            $respond(400, "$field is required for add.");
        }
    }
    $validId = function ($value) {
        return (is_int($value) || is_string($value))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    };
    if ($intent === 'update' && !$validId($input['id'] ?? null)) {
        $respond(400, 'A positive route id is required for update.');
    }
    if ($intent === 'update' && !array_intersect($fields, array_keys($input))) {
        $respond(400, 'At least one field must be supplied for update.');
    }
    if (
        array_key_exists('routeName', $input)
        && (!is_string($input['routeName']) || trim($input['routeName']) === '')
    ) {
        $respond(400, 'routeName must be a non-empty string.');
    }
    if (array_key_exists('vehicleId', $input) && !$validId($input['vehicleId'])) {
        $respond(400, 'vehicleId must be a positive integer.');
    }
    if (
        array_key_exists('staffs', $input)
        && (!is_string($input['staffs']) || !preg_match('/^\d+(?:,\d+)*$/D', $input['staffs']))
    ) {
        $respond(400, 'staffs must be a comma-separated string of staff IDs.');
    }
    $validTime = function ($value) {
        return is_string($value) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value);
    };
    foreach (['startTime', 'endTime'] as $field) {
        if (array_key_exists($field, $input) && !$validTime($input[$field])) {
            $respond(400, "$field must use HH:MM or HH:MM:SS format.");
        }
    }
    $stopageIds = [];
    if (array_key_exists('stopages', $input)) {
        if (!is_array($input['stopages']) || array_values($input['stopages']) !== $input['stopages']) {
            $respond(400, 'stopages must be an array.');
        }
        foreach ($input['stopages'] as $stopage) {
            if (!is_array($stopage) || !$validId($stopage['id'] ?? null)) {
                $respond(400, 'Each stopage must contain a positive id.');
            }
            $stopageId = (int) $stopage['id'];
            if (in_array($stopageId, $stopageIds, true)) {
                $respond(400, 'Duplicate stopage IDs are not allowed.');
            }
            $stopageIds[] = $stopageId;
            foreach (['pickup_time', 'drop_time'] as $field) {
                if ($intent === 'add' && !array_key_exists($field, $stopage)) {
                    $respond(400, "Each stopage must contain $field for add.");
                }
                if (array_key_exists($field, $stopage) && !$validTime($stopage[$field])) {
                    $respond(400, "$field must use HH:MM or HH:MM:SS format.");
                }
            }
        }
    }
    $quote = function ($value) use ($conn) {
        return "'" . mysqli_real_escape_string($conn, (string) $value) . "'";
    };
    $query = function ($sql) use ($conn) {
        $result = mysqli_query($conn, $sql);
        if ($result === false) {
            throw new RuntimeException('Database query failed.');
        }
        return $result;
    };
    $instSql = $quote($instituteId);
    $transactionStarted = false;
    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Could not start transaction.');
        }
        $transactionStarted = true;
        if ($intent === 'update') {
            $route = $query('SELECT `id` FROM `transport_routes` WHERE `id`=' . $quote($input['id']) . ' LIMIT 1 FOR UPDATE');
            if (mysqli_num_rows($route) === 0) {
                throw new RuntimeException('Route not found.', 404);
            }
        }
        $updates = [];
        foreach (['startTime' => 'start_time', 'endTime' => 'end_time'] as $field => $column) {
            if (array_key_exists($field, $input)) {
                $updates[] = "`$column`=" . $quote($input[$field]);
            }
        }
        if ($updates) {
            $institution = $query("SELECT `id` FROM `institutions` WHERE `inst_id`=$instSql FOR UPDATE");
            if (mysqli_num_rows($institution) === 0) {
                throw new RuntimeException('Institution not found.', 404);
            }
            $query('UPDATE `institutions` SET ' . implode(', ', $updates) . " WHERE `inst_id`=$instSql");
        }
        foreach ($input['stopages'] ?? [] as $stopage) {
            $where = '`id`=' . $quote($stopage['id']) . " AND `inst_id`=$instSql";
            $existing = $query("SELECT `id` FROM `transport_stopages` WHERE $where LIMIT 1 FOR UPDATE");
            if (mysqli_num_rows($existing) === 0) {
                throw new RuntimeException('Stopage ' . $stopage['id'] . ' not found for this institute.', 404);
            }
            $updates = [];
            foreach (['pickup_time', 'drop_time'] as $field) {
                if (array_key_exists($field, $stopage)) {
                    $updates[] = "`$field`=" . $quote($stopage[$field]);
                }
            }
            if ($updates) {
                $query('UPDATE `transport_stopages` SET ' . implode(', ', $updates) . " WHERE $where");
            }
        }
        $routeValues = [];
        foreach (['routeName' => 'name', 'vehicleId' => 'assigned_vehicle_id', 'staffs' => 'staffs'] as $field => $column) {
            if (array_key_exists($field, $input)) {
                $routeValues[$column] = $quote($input[$field]);
            }
        }
        if (array_key_exists('stopages', $input)) {
            $routeValues['stopages'] = $quote(implode(',', $stopageIds));
        }
        if ($intent === 'add') {
            $query('INSERT INTO `transport_routes` (`' . implode('`, `', array_keys($routeValues))
                . '`) VALUES (' . implode(', ', $routeValues) . ')');
        } elseif ($routeValues) {
            $updates = [];
            foreach ($routeValues as $column => $value) {
                $updates[] = "`$column`=$value";
            }
            $query('UPDATE `transport_routes` SET ' . implode(', ', $updates) . ' WHERE `id`=' . $quote($input['id']));
        }
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Could not commit transaction.');
        }
        $transactionStarted = false;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            mysqli_rollback($conn);
        }
        $status = $error->getCode() === 404 ? 404 : 500;
        $respond($status, $status === 404 ? $error->getMessage() : 'Unable to save route due to a database error.');
    }
    $respond(200, $intent === 'add' ? 'Route created successfully.' : 'Route updated successfully.');
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
