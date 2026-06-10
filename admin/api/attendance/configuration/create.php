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

    $classes = $inputData['classes'];
    if (!is_array($classes)) {
        $data = [
            'status' => 400,
            'message' => 'Invalid classes data.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }
    $type = mysqli_real_escape_string($conn, $inputData['type']);
    $allowedTypes = ['date_wise', 'period_wise'];
    if (!in_array($type, $allowedTypes)) {
        $data = [
            'status' => 400,
            'message' => 'Invalid attendance type.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    // Normalize and validate classes array
    $cleanClasses = [];
    foreach ($classes as $c) {
        $c = trim($c);
        if ($c === '') continue;
        if (!is_numeric($c)) continue;
        $cleanClasses[] = intval($c);
    }
    $cleanClasses = array_values(array_unique($cleanClasses));
    if (empty($cleanClasses)) {
        $data = [
            'status' => 400,
            'message' => 'No valid class ids provided.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    // Check existing classes already configured for this institute
    $existingClasses = [];
    $instEscaped = mysqli_real_escape_string($conn, $instituteId);
    $checkSql = "SELECT `classes` FROM `institution_attendance_settings` WHERE `inst_id` = '" . $instEscaped . "'";
    $res = mysqli_query($conn, $checkSql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            if (!empty($row['classes'])) {
                $parts = array_map('trim', explode(',', $row['classes']));
                foreach ($parts as $p) {
                    if ($p === '') continue;
                    if (!is_numeric($p)) continue;
                    $existingClasses[] = intval($p);
                }
            }
        }
    }
    $existingClasses = array_values(array_unique($existingClasses));

    $intersect = array_values(array_intersect($cleanClasses, $existingClasses));
    if (!empty($intersect)) {
        $data = [
            'status' => 409,
            'message' => 'Some classes are already configured for this institution.',
            'existing_classes' => $intersect
        ];
        header("HTTP/1.0 409 Conflict");
        echo json_encode($data);
        exit;
    }

    // Save new attendance settings
    $classesStr = implode(',', $cleanClasses);
    $attendanceTypeEscaped = mysqli_real_escape_string($conn, $type);
    $classesEscaped = mysqli_real_escape_string($conn, $classesStr);

    $insertSql = "INSERT INTO `institution_attendance_settings` (`inst_id`, `attendance_type`, `classes`) VALUES ('" . $instEscaped . "', '" . $attendanceTypeEscaped . "', '" . $classesEscaped . "')";
    if (mysqli_query($conn, $insertSql)) {
        $data = [
            'status' => 201,
            'message' => 'Attendance configuration saved successfully.',
            'id' => mysqli_insert_id($conn)
        ];
        header("HTTP/1.0 201 Created");
        echo json_encode($data);
        exit;
    } else {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
