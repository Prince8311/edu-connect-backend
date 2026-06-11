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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $attendanceMap = [];

    $sql = "SELECT attendance_type, classes FROM institution_attendance_settings WHERE inst_id = '$instituteId'";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $attendanceType = $row['attendance_type'];
            $classesStr = $row['classes'];

            if (empty($classesStr)) continue;

            $parts = explode(',', $classesStr);
            foreach ($parts as $class) {
                $class = trim($class);
                if ($class === '') continue;
                $attendanceMap[$class] = $attendanceType;
            }
        }

        $attendanceList = [];
        foreach ($attendanceMap as $class => $type) {
            $attendanceList[] = [
                'class' => $class,
                'attendance_type' => $type
            ];
        }

        $data = [
            'status' => 200,
            'message' => 'Attendance settings fetched.',
            'data' => $attendanceList
        ];

        header("HTTP/1.0 200 OK");
        echo json_encode($data);
        exit;
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ]);
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
