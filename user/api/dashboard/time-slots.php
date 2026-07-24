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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = mysqli_real_escape_string($conn, (string) ($authResult['inst_id'] ?? ''));

    if ($instituteId === '') {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'success' => false,
            'status' => 400,
            'message' => 'Institute information is missing for the authenticated user.'
        ]);
        exit;
    }

    $sql = "SELECT `id`, `inst_id`, `name`, `start`, `end`
            FROM `time_slots`
            WHERE `inst_id` = '$instituteId'
            ORDER BY COALESCE(
                STR_TO_DATE(`start`, '%h:%i %p'),
                STR_TO_DATE(`start`, '%l:%i %p')
            ) ASC";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'success' => false,
            'status' => 500,
            'message' => 'Failed to fetch time slots.'
        ]);
        exit;
    }

    $timeSlots = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $timeSlots[] = $row;
    }

    header("HTTP/1.0 200 OK");
    echo json_encode([
        'success' => true,
        'status' => 200,
        'message' => 'Time slots fetched successfully',
        'data' => $timeSlots
    ]);
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
