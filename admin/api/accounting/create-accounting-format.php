<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    $data = [
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ];
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode($data);
    exit;
}

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    if (empty($instituteId)) {
        $data = [
            'status' => 422,
            'message' => 'Institute ID is missing from authentication'
        ];

        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }

    $respond = function ($status, $message, $extra = []) {
        $statusTexts = [
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error'
        ];
        header("HTTP/1.0 $status " . ($statusTexts[$status] ?? ''));
        echo json_encode(array_merge([
            'status' => $status,
            'message' => $message
        ], $extra));
        exit;
    };

    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));
    if (!in_array($intent, ['add', 'update'], true)) {
        $respond(400, "Invalid 'intent'. Allowed values are add or update.");
    }

    $inputData = json_decode(file_get_contents('php://input'), true);
    if (!is_array($inputData)) {
        $respond(400, 'Invalid or empty request data.');
    }

    $name = trim((string) ($inputData['name'] ?? ''));
    $format = strtolower(trim((string) ($inputData['format'] ?? '')));

    if ($name === '') {
        $respond(400, 'name is required.');
    }
    if (!in_array($format, ['event', 'retailer'], true)) {
        $respond(400, "Invalid 'format'. Allowed values are event or retailer.");
    }

    $id = filter_var($inputData['id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);
    if ($intent === 'update' && $id === false) {
        $respond(400, 'A valid id is required for update intent.');
    }

    $table = $format === 'event' ? 'accounting_event' : 'accounting_retailer';
    $nameColumn = $format === 'event' ? 'event' : 'retailer';
    $defaultStatus = $format === 'event' ? 'Active' : 'Not Started';

    if ($intent === 'add') {
        $sql = "INSERT INTO `$table` (`inst_id`, `$nameColumn`, `status`) VALUES (?, ?, ?)";
        $statement = mysqli_prepare($conn, $sql);
        if (!$statement) {
            $respond(500, 'Database error: ' . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($statement, 'sss', $instituteId, $name, $defaultStatus);
        if (!mysqli_stmt_execute($statement)) {
            $error = mysqli_stmt_error($statement);
            mysqli_stmt_close($statement);
            $respond(500, 'Database error: ' . $error);
        }

        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($statement);
        $respond(200, ucfirst($format) . ' added successfully.', ['id' => $newId]);
    }

    $sql = "UPDATE `$table` SET `$nameColumn` = ? WHERE `id` = ? AND `inst_id` = ?";
    $statement = mysqli_prepare($conn, $sql);
    if (!$statement) {
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($statement, 'sis', $name, $id, $instituteId);
    if (!mysqli_stmt_execute($statement)) {
        $error = mysqli_stmt_error($statement);
        mysqli_stmt_close($statement);
        $respond(500, 'Database error: ' . $error);
    }

    $affectedRows = mysqli_stmt_affected_rows($statement);
    mysqli_stmt_close($statement);

    if ($affectedRows === 0) {
        $checkSql = "SELECT `id` FROM `$table` WHERE `id` = ? AND `inst_id` = ? LIMIT 1";
        $checkStatement = mysqli_prepare($conn, $checkSql);
        if (!$checkStatement) {
            $respond(500, 'Database error: ' . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($checkStatement, 'is', $id, $instituteId);
        if (!mysqli_stmt_execute($checkStatement)) {
            $error = mysqli_stmt_error($checkStatement);
            mysqli_stmt_close($checkStatement);
            $respond(500, 'Database error: ' . $error);
        }

        mysqli_stmt_store_result($checkStatement);
        $exists = mysqli_stmt_num_rows($checkStatement) > 0;
        mysqli_stmt_close($checkStatement);

        if (!$exists) {
            $respond(404, ucfirst($format) . ' not found.');
        }
    }

    $respond(200, ucfirst($format) . ' updated successfully.');
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod .
            ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
