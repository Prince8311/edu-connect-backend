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
    $intent = isset($_GET['intent']) ? strtolower(trim($_GET['intent'])) : 'initial';

    if (!$class || !$section) {
        $data = ['status' => 400, 'message' => 'class and section are required'];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if ($intent !== 'initial' && $intent !== 'final') {
        $data = ['status' => 400, 'message' => 'Invalid intent parameter'];
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

    $periodTimings = [];
    $weekDays = [];

    if ($intent === 'final') {
        $slotsQuery = "SELECT id, name, start, end FROM time_slots WHERE inst_id = ? ORDER BY start";
        $slotsStmt = $conn->prepare($slotsQuery);
        if ($slotsStmt) {
            $slotsStmt->bind_param('s', $instituteId);
            $slotsStmt->execute();
            $slotsRes = $slotsStmt->get_result();
            while ($slotRow = $slotsRes->fetch_assoc()) {
                $periodTimings[] = [
                    'id' => $slotRow['id'],
                    'name' => $slotRow['name'],
                    'time' => trim($slotRow['start'] . ' - ' . $slotRow['end'])
                ];
            }
        }
    }

    // Fetch timetable with teacher names, joined from teachers and users
    $query = "SELECT tt.id, tt.day, tt.period, tt.time, tt.subject, tt.teacher, tt.status, u.name as teacher_name
        FROM time_table tt
        LEFT JOIN teachers t ON tt.teacher = t.id AND tt.inst_id = t.inst_id
        LEFT JOIN users u ON t.user_id = u.id
        WHERE tt.inst_id = ? AND tt.`class` = ? AND tt.`section` = ?
        ORDER BY FIELD(tt.day, 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'),
                 STR_TO_DATE(CONCAT('2000-01-01 ', SUBSTRING_INDEX(tt.time, ' - ', 1)), '%Y-%m-%d %h:%i %p')";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('sss', $instituteId, $class, $section);
    $stmt->execute();
    $res = $stmt->get_result();

    $timetableData = [];
    $dayAllSaved = []; // track if all rows for a day are saved (status==1)
    while ($row = $res->fetch_assoc()) {
        $shortDay = $row['day'];
        $fullDay = isset($dayMap[$shortDay]) ? $dayMap[$shortDay] : $shortDay;

        if (!isset($timetableData[$fullDay])) {
            $timetableData[$fullDay] = [];
            $dayAllSaved[$fullDay] = true;
        }

        if ($intent === 'final') {
            $schedule = [
                'subject' => $row['subject'],
                'teacher' => $row['teacher'] === 'N/A' ? 'N/A' : ($row['teacher_name'] ?? 'N/A')
            ];
        } else {
            $schedule = [
                'id' => $row['id'],
                'time' => $row['time'],
                'period' => $row['period'],
                'subject' => $row['subject'],
                'teacher' => $row['teacher'] === 'N/A' ? 'N/A' : ($row['teacher_name'] ?? 'N/A')
            ];
        }

        $timetableData[$fullDay][] = $schedule;
        // status may be '0' or '1' or int
        if (!isset($row['status']) || (string)$row['status'] === '0' || (int)$row['status'] === 0) {
            $dayAllSaved[$fullDay] = false;
        }
    }

    $payloadData = ['fullDays' => [], 'halfDays' => [], 'repeats' => []];
    $payloadStmt = $conn->prepare("SELECT full_days, half_days, repeats FROM time_table_payload WHERE inst_id = ? AND `class` = ? AND `section` = ? LIMIT 1");
    if ($payloadStmt) {
        $payloadStmt->bind_param('iss', $instituteId, $class, $section);
        $payloadStmt->execute();
        $payloadRes = $payloadStmt->get_result();
        if ($payloadRes && $payloadRow = $payloadRes->fetch_assoc()) {
            $payloadData['fullDays'] = json_decode($payloadRow['full_days'], true) ?: [];
            $payloadData['halfDays'] = json_decode($payloadRow['half_days'], true) ?: [];
            $payloadData['repeats'] = json_decode($payloadRow['repeats'], true) ?: [];
        }
    }

    if ($intent === 'final') {
        $weekDays = array_merge($payloadData['fullDays'], $payloadData['halfDays']);
    }

    // Format response: array of objects with day and schedule
    $result = [];
    $dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    foreach ($dayOrder as $day) {
        if (isset($timetableData[$day])) {
            $result[] = [
                'day' => $day,
                'schedule' => $timetableData[$day],
                'saved' => isset($dayAllSaved[$day]) ? (bool)$dayAllSaved[$day] : false
            ];
        }
    }

    // overall saved: true only if every day's saved is true
    $allSaved = true;
    foreach ($result as $d) { if (empty($d['saved'])) { $allSaved = false; break; } }

    $data = [
        'status' => 200,
        'message' => 'Timetable retrieved successfully',
        'data' => $result,
        'all_saved' => $allSaved,
        'payload' => $payloadData
    ];

    if ($intent === 'final') {
        $data['periodTimings'] = $periodTimings;
        $data['weekDays'] = $weekDays;
    }

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
