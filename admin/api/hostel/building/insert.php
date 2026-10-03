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

foreach (['name', 'totalFloors', 'livingRoom', 'sickRoom'] as $field) {
    if (!array_key_exists($field, $inputData)) {
        $respond(400, "$field is required.");
    }
}

$name = mysqli_real_escape_string($conn, (string) $inputData['name']);
$totalFloors = mysqli_real_escape_string($conn, (string) $inputData['totalFloors']);
$livingRoom = mysqli_real_escape_string($conn, (string) $inputData['livingRoom']);
$sickRoom = mysqli_real_escape_string($conn, (string) $inputData['sickRoom']);
$status = (isset($inputData['status']) && $inputData['status'] === true) ? 1 : 0;

if ($intent === 'add') {
    $checkSql = "SELECT `id` FROM `hostel_buildings` WHERE `inst_id`='$instituteId' AND `name`='$name' LIMIT 1";
    $checkResult = mysqli_query($conn, $checkSql);
    if (!$checkResult) {
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }
    if (mysqli_num_rows($checkResult) > 0) {
        $respond(400, 'This building already exists.');
    }

    $insertSql = "INSERT INTO `hostel_buildings`(`inst_id`, `name`, `total_floors`, `living_rooms`, `sick_rooms`, `status`) VALUES ('$instituteId','$name','$totalFloors','$livingRoom','$sickRoom','$status')";
    if (!mysqli_query($conn, $insertSql)) {
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }
    $respond(200, 'Building added successfully.');
}

$id = mysqli_real_escape_string($conn, trim((string) ($inputData['id'] ?? '')));
if ($id === '') {
    $respond(400, 'id is required for update intent.');
}

mysqli_begin_transaction($conn);

$currentSql = "SELECT `id`, `total_floors`, `living_rooms`, `sick_rooms` FROM `hostel_buildings` WHERE `inst_id`='$instituteId' AND `id`='$id' LIMIT 1 FOR UPDATE";
$currentResult = mysqli_query($conn, $currentSql);
if (!$currentResult) {
    mysqli_rollback($conn);
    $respond(500, 'Database error: ' . mysqli_error($conn));
}
if (mysqli_num_rows($currentResult) === 0) {
    mysqli_rollback($conn);
    $respond(404, 'Building not found.');
}
$currentBuilding = mysqli_fetch_assoc($currentResult);

$nameCheckSql = "SELECT `id` FROM `hostel_buildings` WHERE `inst_id`='$instituteId' AND `name`='$name' AND `id`!='$id' LIMIT 1";
$nameCheckResult = mysqli_query($conn, $nameCheckSql);
if (!$nameCheckResult) {
    mysqli_rollback($conn);
    $respond(500, 'Database error: ' . mysqli_error($conn));
}
if (mysqli_num_rows($nameCheckResult) > 0) {
    mysqli_rollback($conn);
    $respond(400, 'This building already exists.');
}

$newTotalFloors = (int) $totalFloors;
$currentTotalFloors = (int) $currentBuilding['total_floors'];
if ($newTotalFloors < $currentTotalFloors) {
    $floorCheckSql = "SELECT `id` FROM `hostel_rooms` WHERE `inst_id`='$instituteId' AND `building_id`='$id' AND `floor_no` > $newTotalFloors LIMIT 1";
    $floorCheckResult = mysqli_query($conn, $floorCheckSql);
    if (!$floorCheckResult) {
        mysqli_rollback($conn);
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }
    if (mysqli_num_rows($floorCheckResult) > 0) {
        mysqli_rollback($conn);
        $respond(400, 'Total floors cannot be decreased because a room exists on a floor above the new total.');
    }
}

$checkExistingRoomCount = function ($newCount, $currentCount, $category) use ($conn, $instituteId, $id, $respond) {
    $newCount = (int) $newCount;
    $currentCount = (int) $currentCount;
    if ($newCount >= $currentCount) {
        return;
    }

    $escapedCategory = mysqli_real_escape_string($conn, $category);
    $roomCheckSql = "SELECT COUNT(*) AS `total` FROM `hostel_rooms` WHERE `inst_id`='$instituteId' AND `building_id`='$id' AND `category`='$escapedCategory'";
    $roomCheckResult = mysqli_query($conn, $roomCheckSql);
    if (!$roomCheckResult) {
        mysqli_rollback($conn);
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }

    $roomCount = (int) mysqli_fetch_assoc($roomCheckResult)['total'];
    if ($roomCount > $newCount) {
        mysqli_rollback($conn);
        $respond(400, "$category count cannot be decreased to $newCount because $roomCount rooms already exist.");
    }
};

$checkExistingRoomCount($livingRoom, $currentBuilding['living_rooms'], 'Living Room');
$checkExistingRoomCount($sickRoom, $currentBuilding['sick_rooms'], 'Sick Room');

$updateSql = "UPDATE `hostel_buildings` SET `name`='$name', `total_floors`='$totalFloors', `living_rooms`='$livingRoom', `sick_rooms`='$sickRoom', `status`='$status' WHERE `inst_id`='$instituteId' AND `id`='$id'";
if (!mysqli_query($conn, $updateSql)) {
    mysqli_rollback($conn);
    $respond(500, 'Database error: ' . mysqli_error($conn));
}
if (!mysqli_commit($conn)) {
    mysqli_rollback($conn);
    $respond(500, 'Database error: ' . mysqli_error($conn));
}

$respond(200, 'Building updated successfully.');
