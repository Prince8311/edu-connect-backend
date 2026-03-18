<?php

require "../../../utils/headers.php";
require "../../../utils/middleware.php";

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
    require "../../../_db-connect.php";
    global $conn;
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    if (!isset($_GET['name'])) {
        $data = [
            'status' => 400,
            'message' => 'State name is missing.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }

    $stateName = mysqli_real_escape_string($conn, $_GET['name']);
    $adminSql = "SELECT i.inst_id FROM admin_users a JOIN institutions i ON a.id = i.admin_id WHERE a.id = '$userId' LIMIT 1";
    $adminResult = mysqli_query($conn, $adminSql);

    if (!$adminResult || mysqli_num_rows($adminResult) === 0) {
        echo json_encode([
            "status" => 401,
            "message" => "Invalid token or institute not found"
        ]);
        exit;
    }

    $sql = "SELECT `city` FROM `state_cities` WHERE `state`='$stateName'";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $cities = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $data = [
            'status' => 200,
            'message' => 'Cities fetched successfully.',
            'cities' => $cities,
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
