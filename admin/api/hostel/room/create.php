<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

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
    require __DIR__ . "/../../../../_db-connect.php";
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

    $buildingId = mysqli_real_escape_string($conn, $inputData['buildingId']);
    $floorNo = mysqli_real_escape_string($conn, $inputData['floorNo']);
    $roomNo = mysqli_real_escape_string($conn, $inputData['roomNo']);
    $bedCount = mysqli_real_escape_string($conn, $inputData['bedCount']);
    $category = mysqli_real_escape_string($conn, $inputData['category']);
    $type = mysqli_real_escape_string($conn, $inputData['type']);
    $status = (isset($inputData['status']) && $inputData['status'] === true) ? 1 : 0;

    $allowedCategories = ['Living Room', 'Sick Room'];
    if (!in_array($category, $allowedCategories)) {
        $data = [
            'status' => 400,
            'message' => "Invalid room category."
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $allowedTypes = ['Ac', 'Non-Ac'];
    if (!in_array($type, $allowedTypes)) {
        $data = [
            'status' => 400,
            'message' => "Invalid room type."
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $checkSql = "SELECT * FROM `hostel_rooms` WHERE `inst_id`='$instituteId' AND `building_id`='$buildingId' AND `room_no`='$roomNo'";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) === 1) {
        $data = [
            'status' => 400,
            'message' => 'This room already exists.'
        ];
        header("HTTP/1.0 400 Already exists");
        echo json_encode($data);
        exit;
    }

    $insertSql = "INSERT INTO `hostel_rooms`(`inst_id`, `building_id`, `floor_no`, `room_no`, `bed_count`, `category`, `type`, `status`) VALUES ('$instituteId','$buildingId','$floorNo','$roomNo','$bedCount','$category','$type','$status')";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult) {
        $data = [
            'status' => 200,
            'message' => 'Room added successfully.'
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
