<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = userAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status'  => $authResult['status'],
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

    $sql = "SELECT `class` FROM `academic_class_sections` WHERE `inst_id` = '$instituteId'";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'success' => false,
            'status' => 500,
            'message' => 'Failed to fetch classes.'
        ]);
        exit;
    }
    $responseData = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $responseData[] = $row['class'];
    }
    $response = [
        'success' => true,
        'status' => 200,
        'message' => 'Classes fetched successfully',
        'data' => $responseData
    ];
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
