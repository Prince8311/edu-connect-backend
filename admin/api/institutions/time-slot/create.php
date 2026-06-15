<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

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
    $start = mysqli_real_escape_string($conn, $inputData['start']);
    $end = mysqli_real_escape_string($conn, $inputData['end']);

    $checkSql = "SELECT * FROM `time_slots` WHERE `inst_id`='$instituteId' AND `name`='$name' AND `start`='$start' AND `end`='$end'";
    $checkResult = mysqli_query($conn, $checkSql);
    if (mysqli_num_rows($checkResult) > 0) {
        $data = [
            'status' => 409,
            'message' => 'Time slot already exists.'
        ];
        header("HTTP/1.0 409 Conflict");
        echo json_encode($data);
        exit;
    }

    // Validate start and end formats and ordering
    $newStartTs = strtotime($start);
    $newEndTs = strtotime($end);
    if ($newStartTs === false || $newEndTs === false) {
        $data = [
            'status' => 400,
            'message' => 'Invalid start or end time format.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }
    if ($newStartTs >= $newEndTs) {
        $data = [
            'status' => 400,
            'message' => 'Start time must be before end time.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    // Check for overlapping slots for this institute
    $overlapSql = "SELECT * FROM `time_slots` WHERE `inst_id`='$instituteId'";
    $overlapResult = mysqli_query($conn, $overlapSql);
    while ($row = mysqli_fetch_assoc($overlapResult)) {
        $existStart = $row['start'];
        $existEnd = $row['end'];
        $existStartTs = strtotime($existStart);
        $existEndTs = strtotime($existEnd);
        // If parsing fails for existing DB value, skip that row
        if ($existStartTs === false || $existEndTs === false) continue;

        // Overlap occurs when newStart < existEnd AND newEnd > existStart
        if ($newStartTs < $existEndTs && $newEndTs > $existStartTs) {
            $data = [
                'status' => 409,
                'message' => 'Time slot overlaps with an existing slot: ' . $existStart . ' - ' . $existEnd
            ];
            header("HTTP/1.0 409 Conflict");
            echo json_encode($data);
            exit;
        }
    }

    $insertSql = "INSERT INTO `time_slots` (`inst_id`, `name`, `start`, `end`) VALUES ('$instituteId', '$name', '$start', '$end')";
    $insertResult = mysqli_query($conn, $insertSql);
    if ($insertResult) {
        $data = [
            'status' => 200,
            'message' => 'Time slot created successfully.'
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 500,
            'message' => 'Failed to create time slot'
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
