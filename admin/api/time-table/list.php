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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    if (empty($instituteId)) {
        $data = ['status' => 422, 'message' => 'Institute ID is missing from authentication'];
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }

    $class = isset($_GET['class']) ? $_GET['class'] : null;
    $section = isset($_GET['section']) ? $_GET['section'] : null;

    if (!$class || !$section) {
        $data = ['status' => 400, 'message' => 'class and section are required'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    // Map short day names to full names
    $dayMap = [
        'Sun' => 'Sunday',
        'Mon' => 'Monday',
        'Tue' => 'Tuesday',
        'Wed' => 'Wednesday',
        'Thu' => 'Thursday',
        'Fri' => 'Friday',
        'Sat' => 'Saturday'
    ];

    // Fetch timetable with teacher names, joined from teachers and users
    $query = "SELECT tt.id, tt.day, tt.period, tt.time, tt.subject, tt.teacher, u.name as teacher_name FROM time_table tt LEFT JOIN teachers t ON tt.teacher = t.id AND tt.inst_id = t.inst_id LEFT JOIN users u ON t.user_id = u.id WHERE tt.inst_id = ? AND tt.day IN (SELECT DISTINCT day FROM time_table WHERE inst_id = ?) ORDER BY FIELD(tt.day, 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'), STR_TO_DATE(CONCAT('2000-01-01 ', SUBSTRING_INDEX(tt.time, ' - ', 1)), '%Y-%m-%d %h:%i %p')";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $instituteId, $instituteId);
    $stmt->execute();
    $res = $stmt->get_result();

    $timetableData = [];
    while ($row = $res->fetch_assoc()) {
        $shortDay = $row['day'];
        $fullDay = isset($dayMap[$shortDay]) ? $dayMap[$shortDay] : $shortDay;

        if (!isset($timetableData[$fullDay])) {
            $timetableData[$fullDay] = [];
        }

        $schedule = [
            'id' => $row['id'],
            'time' => $row['time'],
            'subject' => $row['subject'],
            'teacher' => $row['teacher'] === 'N/A' ? 'N/A' : ($row['teacher_name'] ?? 'N/A')
        ];

        $timetableData[$fullDay][] = $schedule;
    }

    // Format response: array of objects with day and schedule
    $result = [];
    $dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    foreach ($dayOrder as $day) {
        if (isset($timetableData[$day])) {
            $result[] = [
                'day' => $day,
                'schedule' => $timetableData[$day]
            ];
        }
    }

    if (count($result) === 0) {
        $data = ['status' => 404, 'message' => 'No timetable data found for this class/section'];
        header("HTTP/1.0 404 Not Found");
        echo json_encode($data);
        exit;
    }

    $data = ['status' => 200, 'message' => 'Timetable retrieved successfully', 'data' => $result];
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
