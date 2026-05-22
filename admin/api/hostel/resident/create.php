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

    $name = mysqli_real_escape_string($conn, $inputData['name']);
    $userId = mysqli_real_escape_string($conn, $inputData['userId']);
    $userType = mysqli_real_escape_string($conn, $inputData['userType']);
    $roomId = mysqli_real_escape_string($conn, $inputData['roomId']);
    $classSection = isset($inputData['classSection']) ? mysqli_real_escape_string($conn, $inputData['classSection']) : null;
    $role = isset($inputData['role']) ? mysqli_real_escape_string($conn, $inputData['role']) : null;
    $foodPreference = mysqli_real_escape_string($conn, $inputData['foodPreference']);
    $status = mysqli_real_escape_string($conn, $inputData['status']);

    if (empty($classSection) && empty($role)) {
        $data = [
            'status' => 400,
            'message' => 'Either classSection or role must be provided.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $allowedUserTypes = ['Student', 'Staff'];
    if (!in_array($userType, $allowedUserTypes)) {
        $data = [
            'status' => 400,
            'message' => "Invalid user type."
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $allowedFoodPreference = ['Veg', 'Non-Veg'];
    if (!in_array($foodPreference, $allowedFoodPreference)) {
        $data = [
            'status' => 400,
            'message' => "Invalid food preference."
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $checkSql = "SELECT * FROM `hostel_residents` WHERE `inst_id`='$instituteId' AND `name`='$name' AND `user_id`='$userId' AND `user_type`='$userType'";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) === 1) {
        $data = [
            'status' => 400,
            'message' => 'This resident already exists.'
        ];
        header("HTTP/1.0 400 Already exists");
        echo json_encode($data);
        exit;
    }

    $classSectionValue = $classSection !== null ? "'$classSection'" : "NULL";
    $roleValue = $role !== null ? "'$role'" : "NULL";
    $insertSql = "INSERT INTO `hostel_residents`(`inst_id`, `name`, `user_id`, `user_type`, `room_id`, `class_section`, `role`, `food_preference`, `status`) VALUES ('$instituteId','$name','$userId','$userType','$roomId',$classSectionValue,$roleValue,'$foodPreference','$status')";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult) {
        $data = [
            'status' => 200,
            'message' => 'Resident added successfully.'
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
