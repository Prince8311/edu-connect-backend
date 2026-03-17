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
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    if (!isset($_GET['class']) && !isset($_GET['section'])) {
        $data = [
            'status' => 400,
            'message' => 'Class & section is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }

    $class = mysqli_real_escape_string($conn, $_GET['class']);
    $section = mysqli_real_escape_string($conn, $_GET['section']);
    
    $adminSql = "SELECT i.inst_id FROM admin_users a JOIN institutions i ON a.id = i.admin_id WHERE a.id = '$userId' LIMIT 1";
    $adminResult = mysqli_query($conn, $adminSql);

    if (!$adminResult || mysqli_num_rows($adminResult) === 0) {
        echo json_encode([
            "status" => 401,
            "message" => "Invalid token or institute not found"
        ]);
        exit;
    }

    $adminData = mysqli_fetch_assoc($adminResult);
    $instituteId = $adminData['inst_id'];

} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
