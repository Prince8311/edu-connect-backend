<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

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
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    // accept type either as query param or in payload
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload) {
        $data = ['status' => 400, 'message' => 'Invalid JSON payload'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : (isset($payload['type']) ? strtolower(trim($payload['type'])) : null);
    if ($type !== 'week' && $type !== 'day') {
        $data = ['status' => 400, 'message' => "Missing or invalid type parameter (week|day)"];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $class = isset($payload['class']) ? $payload['class'] : null;
    $section = isset($payload['section']) ? $payload['section'] : null;
    if (!$class || !$section) {
        $data = ['status' => 400, 'message' => 'class and section are required in payload'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    // normalize inst id to string (many tables use string ids like ADN887)
    $inst = (string)$instituteId;

    if ($type === 'week') {
        $newStatus = 1; // set status 1 for week
        $updateStmt = $conn->prepare("UPDATE time_table SET status = ?, `classroom_id` = CONCAT(TRIM(`class`), '-', TRIM(`section`), '-', UPPER(LEFT(TRIM(`day`), 1)), '-', UPPER(LEFT(TRIM(`subject`), 1))) WHERE inst_id = ? AND `class` = ? AND `section` = ? AND status = 0");
        if (!$updateStmt) {
            $data = ['status' => 500, 'message' => 'Failed to prepare update statement'];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }
        $updateStmt->bind_param('isss', $newStatus, $inst, $class, $section);
        $updateStmt->execute();
        $affected = $updateStmt->affected_rows;

        $data = ['status' => 200, 'message' => 'Time table saved permenantly', 'updated' => $affected];
        echo json_encode($data);
        exit;
    }

    // type === 'day'
    $dayRaw = isset($payload['day']) ? $payload['day'] : (isset($_GET['day']) ? $_GET['day'] : null);
    if (empty($dayRaw)) {
        $data = ['status' => 400, 'message' => 'Missing day for type=day'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    // map common full day names to short codes used in table
    $dayMap = [
        'monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed', 'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun'
    ];
    $dayKey = strtolower(trim($dayRaw));
    if (!isset($dayMap[$dayKey])) {
        // allow short forms directly (Mon/Tue) as well
        $short = ucfirst(substr($dayKey, 0, 3));
        if (!in_array($short, $dayMap)) {
            $data = ['status' => 400, 'message' => 'Invalid day value'];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }
        $dayShort = $short;
    } else {
        $dayShort = $dayMap[$dayKey];
    }

    $newStatus = 1; // set status 1 for day
    $dayLower = strtolower($dayShort);

    // determine full day name for response message
    $shortToFull = ['Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'];
    if (isset($dayMap[$dayKey])) {
        $dayFullName = ucfirst($dayKey);
    } elseif (isset($shortToFull[$dayShort])) {
        $dayFullName = $shortToFull[$dayShort];
    } else {
        $dayFullName = ucfirst($dayShort);
    }
    $updateStmt = $conn->prepare("UPDATE time_table SET status = ?, `classroom_id` = CONCAT(TRIM(`class`), '-', TRIM(`section`), '-', UPPER(LEFT(TRIM(`day`), 1)), '-', UPPER(LEFT(TRIM(`subject`), 1))) WHERE inst_id = ? AND `class` = ? AND `section` = ? AND TRIM(LOWER(day)) = ? AND status = 0");
    if (!$updateStmt) {
        $data = ['status' => 500, 'message' => 'Failed to prepare day update statement'];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }
    $updateStmt->bind_param('issss', $newStatus, $inst, $class, $section, $dayLower);
    $updateStmt->execute();
    $affected = $updateStmt->affected_rows;

    $data = ['status' => 200, 'message' => $dayFullName . ' time table saved permenantly', 'updated' => $affected];
    echo json_encode($data);
    exit;
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
