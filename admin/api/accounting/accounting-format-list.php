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

if ($requestMethod === 'GET') {
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

    $respond = function ($status, $message, $responseData = null) {
        $statusTexts = [
            200 => 'OK',
            400 => 'Bad Request',
            500 => 'Internal Server Error'
        ];
        header("HTTP/1.0 $status " . ($statusTexts[$status] ?? ''));

        $response = [
            'status' => $status,
            'message' => $message
        ];
        if ($responseData !== null) {
            $response['data'] = $responseData;
        }

        echo json_encode($response);
        exit;
    };

    $type = strtolower(trim((string) ($_GET['type'] ?? '')));
    if (!in_array($type, ['event', 'retailer'], true)) {
        $respond(400, "Invalid 'type'. Allowed values are event or retailer.");
    }

    $table = $type === 'event' ? 'accounting_event' : 'accounting_retailer';
    $nameColumn = $type === 'event' ? 'event' : 'retailer';
    $sql = "SELECT `id`, `inst_id`, `$nameColumn`, `status` FROM `$table` WHERE `inst_id` = ? ORDER BY `id` DESC";

    $statement = mysqli_prepare($conn, $sql);
    if (!$statement) {
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($statement, 's', $instituteId);
    if (!mysqli_stmt_execute($statement)) {
        $error = mysqli_stmt_error($statement);
        mysqli_stmt_close($statement);
        $respond(500, 'Database error: ' . $error);
    }

    $result = mysqli_stmt_get_result($statement);
    if ($result === false) {
        $error = mysqli_stmt_error($statement);
        mysqli_stmt_close($statement);
        $respond(500, 'Database error: ' . $error);
    }

    $list = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($statement);

    $respond(200, ucfirst($type) . ' list fetched successfully.', $list);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod .
            ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
