<?php

require "../../../../../utils/headers.php";
require "../../../../../utils/middleware.php";

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
    require "../../../../../_db-connect.php";
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

    $levelName = mysqli_real_escape_string($conn, $inputData['levelName']);
    $checkSql = "SELECT * FROM `academic_levels` WHERE `inst_id`='$instituteId' AND `level_name`='$levelName'";
    $checkResult = mysqli_query($conn, $checkSql);

    if (mysqli_num_rows($checkResult) === 1) {
        echo json_encode([
            "status" => 401,
            "message" => "This academic level already created."
        ]);
        exit;
    }

    $insertSql = "INSERT INTO `academic_levels`(`inst_id`, `level_name`) VALUES ('$instituteId','$levelName')";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult) {
        $data = [
            'status' => 200,
            'message' => 'Academic level created successfully.'
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
