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

if ($requestMethod === 'POST') {
    require "../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

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

    $sessionName = mysqli_real_escape_string($conn, $inputData['sessionName']);
    $startDate = mysqli_real_escape_string($conn, $inputData['startDate']);
    $endDate = mysqli_real_escape_string($conn, $inputData['endDate']);
    $status = mysqli_real_escape_string($conn, $inputData['status']);

    $checkSql = "SELECT * FROM `academic_sessions` WHERE `inst_id`='$instituteId' AND `sesssion_name`='$sessionName'";
    $checkResult = mysqli_query($conn, $checkSql);

    if (mysqli_num_rows($checkResult) === 1) {
        echo json_encode([
            "status" => 401,
            "message" => "This session already created."
        ]);
        exit;
    }

    $insertSql = "INSERT INTO `academic_sessions`(`inst_id`, `sesssion_name`, `start_date`, `end_date`, `status`) VALUES ('$instituteId','$sessionName','$startDate','$endDate','$status')";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult) {
        $data = [
            'status' => 200,
            'message' => 'Session created successfully.'
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
