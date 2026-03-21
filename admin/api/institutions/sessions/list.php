<?php

require "../../../../utils/headers.php";
require "../../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'GET') {
    require "../../../../_db-connect.php";
    global $conn;
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

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

    $sql = "SELECT * FROM `academic_sessions` WHERE `inst_id`='$instituteId'";
    if (isset($_GET['status']) && trim($_GET['status']) !== '') {
        $sessionStatus = mysqli_real_escape_string($conn, $_GET['status']);
        $sql .= " AND `status`='$sessionStatus'";
    }
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $sessions = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $data = [
            'status' => 200,
            'message' => 'Sessions fetched.',
            'data' => $sessions
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
