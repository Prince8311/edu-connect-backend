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

if ($requestMethod === 'POST') {
    require "../../../_db-connect.php";
    global $conn;
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (empty($inputData)) {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $adminSql = "SELECT i.inst_id FROM admin_users a JOIN institutions i ON a.id = i.admin_id WHERE a.id = '$userId' LIMIT 1";
    $adminResult = mysqli_query($conn, $adminSql);

    if (!$adminResult || mysqli_num_rows($adminResult) === 0) {
        echo json_encode([
            "status" => 401,
            "message" => "Invalid token or institute not found"
        ]);
        exit;
    }

    $state = mysqli_real_escape_string($conn, $inputData['state']);
    $city = mysqli_real_escape_string($conn, $inputData['city']);

    $checkSql = "SELECT * FROM `state_cities` WHERE `state`='$state' AND `city`='$city'";
    $checkResult = mysqli_query($conn, $checkSql);

    if (mysqli_num_rows($checkResult) > 0) {
        $data = [
            'status' => 400,
            'message' => 'City already exists'
        ];
        header("HTTP/1.0 400 City exists");
        echo json_encode($data);
        exit;
    }

    $sql = "INSERT INTO `state_cities`(`state`, `city`) VALUES ('$state','$city')";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $data = [
            'status' => 200,
            'message' => 'City added successfully.'
        ];
        header("HTTP/1.0 200 Ok");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . $error
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
