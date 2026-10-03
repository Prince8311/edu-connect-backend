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
$intent = strtolower(trim((string) ($_GET['intent'] ?? '')));
if (!in_array($intent, ['add', 'update'], true)) {
    $respond(400, "Invalid 'intent'. Allowed values are add or update.");
}

$inputData = json_decode(file_get_contents("php://input"), true);
if (!is_array($inputData) || empty($inputData)) {
    $respond(400, 'Empty request data');
}

if ($intent === 'update') {
    foreach (['id', 'roomId', 'bedNo'] as $field) {
        if (!array_key_exists($field, $inputData) || trim((string) $inputData[$field]) === '') {
            $respond(400, "$field is required for update intent.");
        }
    }

    $id = mysqli_real_escape_string($conn, trim((string) $inputData['id']));
    $roomId = mysqli_real_escape_string($conn, trim((string) $inputData['roomId']));
    $bedNo = mysqli_real_escape_string($conn, trim((string) $inputData['bedNo']));

    mysqli_begin_transaction($conn);

    $residentResult = mysqli_query($conn, "SELECT `id` FROM `hostel_residents` WHERE `id`='$id' AND `inst_id`='$instituteId' LIMIT 1 FOR UPDATE");
    if (!$residentResult) {
        mysqli_rollback($conn);
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }
    if (mysqli_num_rows($residentResult) === 0) {
        mysqli_rollback($conn);
        $respond(404, 'Resident not found.');
    }

    $bedCheckResult = mysqli_query($conn, "SELECT `id` FROM `hostel_residents` WHERE `inst_id`='$instituteId' AND `room_id`='$roomId' AND `bed_no`='$bedNo' AND `id`!='$id' LIMIT 1 FOR UPDATE");
    if (!$bedCheckResult) {
        mysqli_rollback($conn);
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }
    if (mysqli_num_rows($bedCheckResult) > 0) {
        mysqli_rollback($conn);
        $respond(400, 'This bed is already occupied. Update not possible.');
    }

    $updateResult = mysqli_query($conn, "UPDATE `hostel_residents` SET `room_id`='$roomId', `bed_no`='$bedNo' WHERE `id`='$id' AND `inst_id`='$instituteId'");
    if (!$updateResult) {
        mysqli_rollback($conn);
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        $respond(500, 'Database error: ' . mysqli_error($conn));
    }

    $respond(200, 'Resident updated successfully.');
}

foreach (['name', 'userId', 'userType', 'roomId', 'bedNo', 'foodPreference', 'status'] as $field) {
    if (!array_key_exists($field, $inputData)) {
        $respond(400, "$field is required.");
    }
}

$name = mysqli_real_escape_string($conn, (string) $inputData['name']);
$userId = mysqli_real_escape_string($conn, (string) $inputData['userId']);
$userType = mysqli_real_escape_string($conn, (string) $inputData['userType']);
$roomId = mysqli_real_escape_string($conn, (string) $inputData['roomId']);
$bedNo = mysqli_real_escape_string($conn, (string) $inputData['bedNo']);
$foodPreference = mysqli_real_escape_string($conn, (string) $inputData['foodPreference']);
$status = mysqli_real_escape_string($conn, (string) $inputData['status']);

if (!in_array($userType, ['Student', 'Teacher', 'Staff'], true)) {
    $respond(400, 'Invalid user type.');
}
if (!in_array($foodPreference, ['Veg', 'Non-Veg'], true)) {
    $respond(400, 'Invalid food preference.');
}

$checkResult = mysqli_query($conn, "SELECT `id` FROM `hostel_residents` WHERE `inst_id`='$instituteId' AND `name`='$name' AND `user_id`='$userId' AND `user_type`='$userType' LIMIT 1");
if (!$checkResult) {
    $respond(500, 'Database error: ' . mysqli_error($conn));
}
if (mysqli_num_rows($checkResult) > 0) {
    $respond(400, 'This resident already exists.');
}

$bedCheckResult = mysqli_query($conn, "SELECT `id` FROM `hostel_residents` WHERE `inst_id`='$instituteId' AND `room_id`='$roomId' AND `bed_no`='$bedNo' LIMIT 1");
if (!$bedCheckResult) {
    $respond(500, 'Database error: ' . mysqli_error($conn));
}
if (mysqli_num_rows($bedCheckResult) > 0) {
    $respond(400, 'This bed is already occupied.');
}

$insertResult = mysqli_query($conn, "INSERT INTO `hostel_residents`(`inst_id`, `user_id`, `user_type`, `room_id`, `bed_no`, `food_preference`, `status`) VALUES ('$instituteId','$userId','$userType','$roomId','$bedNo','$foodPreference','$status')");
if (!$insertResult) {
    $respond(500, 'Database error: ' . mysqli_error($conn));
}

$respond(200, 'Resident added successfully.');
