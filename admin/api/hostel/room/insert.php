<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode(['status' => $authResult['status'], 'message' => $authResult['message']]);
    exit;
}

if ($requestMethod !== 'POST') {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode(['status' => 405, 'message' => $requestMethod . ' Method Not Allowed']);
    exit;
}

require __DIR__ . "/../../../../_db-connect.php";
global $conn;

$respond = function ($status, $message) {
    $statusTexts = [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 500 => 'Internal Server Error'];
    header("HTTP/1.0 $status " . ($statusTexts[$status] ?? ''));
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
};

$instituteId = mysqli_real_escape_string($conn, (string) $authResult['inst_id']);
$rawIntent = $_GET['intent'] ?? null;
if ($rawIntent === null || trim((string) $rawIntent) === '') {
    $respond(400, 'intent is required.');
}

$intent = strtolower(trim((string) $rawIntent));
if (!in_array($intent, ['add', 'update'], true)) {
    $respond(400, "Invalid 'intent'. Allowed values are add or update.");
}

$inputData = json_decode(file_get_contents("php://input"), true);
if (!is_array($inputData) || empty($inputData)) {
    $respond(400, 'Empty request data');
}

$requiredFields = $intent === 'add'
    ? ['buildingId', 'floorNo', 'roomNo', 'bedCount', 'category', 'type']
    : ['id', 'floorNo', 'bedCount', 'category', 'type'];

foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $inputData)) {
        $respond(400, "$field is required.");
    }
}

$buildingId = mysqli_real_escape_string($conn, (string) ($inputData['buildingId'] ?? ''));
$floorNo = mysqli_real_escape_string($conn, (string) $inputData['floorNo']);
$roomNo = mysqli_real_escape_string($conn, (string) ($inputData['roomNo'] ?? ''));
$bedCount = mysqli_real_escape_string($conn, (string) $inputData['bedCount']);
$category = mysqli_real_escape_string($conn, (string) $inputData['category']);
$type = mysqli_real_escape_string($conn, (string) $inputData['type']);
$status = (isset($inputData['status']) && $inputData['status'] === true) ? 1 : 0;

$allowedCategories = ['Living Room', 'Sick Room'];
if (!in_array($category, $allowedCategories, true)) {
    $respond(400, 'Invalid room category.');
}

$allowedTypes = ['Ac', 'Non-Ac'];
if (!in_array($type, $allowedTypes, true)) {
    $respond(400, 'Invalid room type.');
}

if ($intent === 'add') {
    $checkSql = "SELECT `id` FROM `hostel_rooms` WHERE `inst_id`='$instituteId' AND `building_id`='$buildingId' AND `room_no`='$roomNo' LIMIT 1";
    $checkResult = mysqli_query($conn, $checkSql);
    if (!$checkResult) {
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }
    if (mysqli_num_rows($checkResult) > 0) {
        $respond(400, 'This room already exists.');
    }

    $insertSql = "INSERT INTO `hostel_rooms`(`inst_id`, `building_id`, `floor_no`, `room_no`, `bed_count`, `category`, `type`, `status`) VALUES ('$instituteId','$buildingId','$floorNo','$roomNo','$bedCount','$category','$type','$status')";
    if (!mysqli_query($conn, $insertSql)) {
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }
    $respond(200, 'Room added successfully.');
}

$id = mysqli_real_escape_string($conn, trim((string) ($inputData['id'] ?? '')));
if ($id === '') {
    $respond(400, 'id is required for update intent.');
}

mysqli_begin_transaction($conn);

$currentSql = "SELECT `id`, `bed_count` FROM `hostel_rooms` WHERE `inst_id`='$instituteId' AND `id`='$id' LIMIT 1 FOR UPDATE";
$currentResult = mysqli_query($conn, $currentSql);
if (!$currentResult) {
    mysqli_rollback($conn);
    $respond(500, 'Database error: ' . mysqli_error($conn));
}
if (mysqli_num_rows($currentResult) === 0) {
    mysqli_rollback($conn);
    $respond(404, 'Room not found.');
}
$currentRoom = mysqli_fetch_assoc($currentResult);

$newBedCount = (int) $bedCount;
$currentBedCount = (int) $currentRoom['bed_count'];
if ($newBedCount < $currentBedCount) {
    $bedCheckSql = "SELECT `id` FROM `hostel_residents` WHERE `inst_id`='$instituteId' AND `room_id`='$id' AND `bed_no` > $newBedCount LIMIT 1 FOR UPDATE";
    $bedCheckResult = mysqli_query($conn, $bedCheckSql);
    if (!$bedCheckResult) {
        mysqli_rollback($conn);
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }
    if (mysqli_num_rows($bedCheckResult) > 0) {
        mysqli_rollback($conn);
        $respond(400, 'Bed count cannot be decreased because a bed being removed is occupied.');
    }
}

$statusUpdate = array_key_exists('status', $inputData) ? ", `status`='$status'" : '';
$updateSql = "UPDATE `hostel_rooms` SET `floor_no`='$floorNo', `bed_count`='$bedCount', `category`='$category', `type`='$type'$statusUpdate WHERE `inst_id`='$instituteId' AND `id`='$id'";
if (!mysqli_query($conn, $updateSql)) {
    mysqli_rollback($conn);
    $respond(500, 'Database error: ' . mysqli_error($conn));
}
if (!mysqli_commit($conn)) {
    mysqli_rollback($conn);
    $respond(500, 'Database error: ' . mysqli_error($conn));
}

$respond(200, 'Room updated successfully.');
