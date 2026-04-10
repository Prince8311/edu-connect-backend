<?php

require "../../../../utils/headers.php";
require "../../../../utils/middleware.php";

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

    $section = mysqli_real_escape_string($conn, $inputData['section']);
    $sectionType = mysqli_real_escape_string($conn, $inputData['sectionType']);

    $checkSql = "SELECT * FROM `student_form_sections` WHERE `inst_id`='$instituteId' AND `form_section`='$section'";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) === 1) {
        $data = [
            'status' => 400,
            'message' => 'This section already created.'
        ];
        header("HTTP/1.0 400 Already exists");
        echo json_encode($data);
        exit;
    }

    $insertSql = "INSERT INTO `student_form_sections`(`inst_id`, `form_section`, `section_type`) VALUES ('$instituteId','$section','$sectionType')";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult) {
        $data = [
            'status' => 200,
            'message' => 'Section created successfully.'
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
