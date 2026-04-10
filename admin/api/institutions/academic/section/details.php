<?php

require "../../../../../utils/headers.php";
require "../../../../../utils/middleware.php";

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
    require "../../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    if (!isset($_GET['class']) && !isset($_GET['section'])) {
        $data = [
            'status' => 400,
            'message' => 'Class & section is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $class = mysqli_real_escape_string($conn, $_GET['class']);
    $section = mysqli_real_escape_string($conn, $_GET['section']);

} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
