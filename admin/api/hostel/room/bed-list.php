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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    if (!isset($_GET['buildingId'])) {
        $data = [
            'status' => 400,
            'message' => 'Building ID required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if (!isset($_GET['floorNo'])) {
        $data = [
            'status' => 400,
            'message' => 'Floor number required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if (!isset($_GET['roomId'])) {
        $data = [
            'status' => 400,
            'message' => 'Room ID required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $buildingId = mysqli_real_escape_string($conn, $_GET['buildingId']);
    $floorNo = mysqli_real_escape_string($conn, $_GET['floorNo']);
    $roomId = mysqli_real_escape_string($conn, $_GET['roomId']);

    $roomSql = "SELECT bed_count FROM hostel_rooms WHERE id = '$roomId' AND inst_id = '$instituteId' AND building_id = '$buildingId' AND floor_no = '$floorNo' LIMIT 1";
    $roomResult = mysqli_query($conn, $roomSql);
    if (!$roomResult || mysqli_num_rows($roomResult) === 0) {
        $data = [
            'status' => 404,
            'message' => 'Room not found.'
        ];
        header("HTTP/1.0 404 Not Found");
        echo json_encode($data);
        exit;
    }
    $roomRow = mysqli_fetch_assoc($roomResult);
    $bedCount = (int) $roomRow['bed_count'];

    $allBeds = [];
    for ($i = 1; $i <= $bedCount; $i++) {
        $allBeds[] = str_pad($i, 2, '0', STR_PAD_LEFT);
    }

    $occupiedBeds = [];
    $bedSql = "SELECT bed_no FROM hostel_residents WHERE room_id = '$roomId' AND inst_id = '$instituteId'";
    $bedResult = mysqli_query($conn, $bedSql);
    if ($bedResult) {
        while ($row = mysqli_fetch_assoc($bedResult)) {
            if (!empty($row['bed_no'])) {
                $occupiedBeds[] = $row['bed_no'];
            }
        }
    }

    $availableBeds = array_values(array_diff($allBeds, $occupiedBeds));

    $data = [
        'status' => 200,
        'message' => 'Available beds fetched.',
        'availableBeds' => $availableBeds
    ];
    header("HTTP/1.0 200 OK");
    echo json_encode($data);
    exit;
} else {
    $data = [
        'status' => 405,
        'message' => 'Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
    exit;
}
