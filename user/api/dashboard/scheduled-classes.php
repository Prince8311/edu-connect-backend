<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = userAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = mysqli_real_escape_string($conn, (string) ($authResult['inst_id'] ?? ''));
    $userId = mysqli_real_escape_string($conn, (string) ($authResult['userId'] ?? ''));
    $userType = strtolower(trim((string) ($authResult['user_type'] ?? '')));
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));

    if ($instituteId === '') {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'success' => false,
            'status' => 400,
            'message' => 'Institute information is missing for the authenticated user.'
        ]);
        exit;
    }

    if ($intent !== 'today' && $intent !== 'weekly') {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'success' => false,
            'status' => 400,
            'message' => "Invalid intent. Allowed values are 'today' or 'weekly'."
        ]);
        exit;
    }

    $todayDayName = date('l');
    $dayNameToShort = [
        'Sunday' => 'Sun',
        'Monday' => 'Mon',
        'Tuesday' => 'Tue',
        'Wednesday' => 'Wed',
        'Thursday' => 'Thu',
        'Friday' => 'Fri',
        'Saturday' => 'Sat',
    ];
    $todayShortDay = $dayNameToShort[$todayDayName] ?? substr($todayDayName, 0, 3);

    $scheduledClasses = [];

    if ($userType === 'teacher') {
        $teacherStmt = $conn->prepare("SELECT `id`, `class_teacher` FROM `teachers` WHERE `inst_id` = ? AND `user_id` = ? LIMIT 1");
        if (!$teacherStmt) {
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to prepare teacher lookup query.'
            ]);
            exit;
        }

        $teacherStmt->bind_param('ss', $instituteId, $userId);
        $teacherStmt->execute();
        $teacherResult = $teacherStmt->get_result();
        $teacherRow = $teacherResult ? $teacherResult->fetch_assoc() : null;
        $teacherStmt->close();

        if (!$teacherRow) {
            header("HTTP/1.0 404 Not Found");
            echo json_encode([
                'success' => false,
                'status' => 404,
                'message' => 'Teacher profile not found for this user.'
            ]);
            exit;
        }

        $teacherId = (string) $teacherRow['id'];

        $timeTableSql = "SELECT `id`, `inst_id`, `class`, `section`, `day`, `period`, `time`, `subject`, `teacher`, `status`
            FROM `time_table`
            WHERE `inst_id` = ? AND `teacher` = ? AND `status` = 1";
        $paramTypes = 'ss';
        $paramValues = [$instituteId, $teacherId];

        if ($intent === 'today') {
            $timeTableSql .= " AND TRIM(LOWER(`day`)) = ?";
            $paramTypes .= 's';
            $paramValues[] = strtolower($todayShortDay);
        }

        $timeTableSql .= " ORDER BY FIELD(`day`, 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'), STR_TO_DATE(SUBSTRING_INDEX(`time`, ' - ', 1), '%h:%i %p') ASC";

        $timeTableStmt = $conn->prepare($timeTableSql);
        if (!$timeTableStmt) {
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to prepare timetable query for teacher.'
            ]);
            exit;
        }

        $timeTableStmt->bind_param($paramTypes, ...$paramValues);
        $timeTableStmt->execute();
        $timeTableResult = $timeTableStmt->get_result();

        while ($timeTableResult && $row = $timeTableResult->fetch_assoc()) {
            $scheduledClasses[] = $row;
        }
        $timeTableStmt->close();
    } elseif ($userType === 'student' || $userType === 'guardian') {
        $studentId = null;

        if ($userType === 'student') {
            $studentStmt = $conn->prepare("SELECT `id` FROM `students` WHERE `inst_id` = ? AND `user_id` = ? LIMIT 1");
            if (!$studentStmt) {
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode([
                    'success' => false,
                    'status' => 500,
                    'message' => 'Failed to prepare student lookup query.'
                ]);
                exit;
            }

            $studentStmt->bind_param('ss', $instituteId, $userId);
            $studentStmt->execute();
            $studentResult = $studentStmt->get_result();
            $studentRow = $studentResult ? $studentResult->fetch_assoc() : null;
            $studentStmt->close();

            if (!$studentRow) {
                header("HTTP/1.0 404 Not Found");
                echo json_encode([
                    'success' => false,
                    'status' => 404,
                    'message' => 'Student profile not found for this user.'
                ]);
                exit;
            }

            $studentId = (string) $studentRow['id'];
        } else {
            $authStudentId = isset($authResult['student_id']) ? (string) $authResult['student_id'] : '';
            if ($authStudentId === '') {
                header("HTTP/1.0 400 Bad Request");
                echo json_encode([
                    'success' => false,
                    'status' => 400,
                    'message' => 'Guardian student context is missing. Please select a student and login again.'
                ]);
                exit;
            }
            $studentId = mysqli_real_escape_string($conn, $authStudentId);
        }

        if ($userType === 'guardian') {
            $fieldSql = "SELECT `field_name`, `value` FROM `student_field_values` WHERE `inst_id` = ? AND `student_id` = ? AND `field_name` IN ('Class / Standard', 'Section')";
        } else {
            $fieldSql = "SELECT `field_name`, `value` FROM `student_field_values` WHERE `inst_id` = ? AND `student_id` = ? AND `section_id` = '1' AND `field_name` IN ('Class / Standard', 'Section')";
        }

        $fieldStmt = $conn->prepare($fieldSql);
        if (!$fieldStmt) {
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to prepare student field lookup query.'
            ]);
            exit;
        }

        $fieldStmt->bind_param('ss', $instituteId, $studentId);
        $fieldStmt->execute();
        $fieldResult = $fieldStmt->get_result();

        $classStandard = '';
        $section = '';
        while ($fieldResult && $fieldRow = $fieldResult->fetch_assoc()) {
            if ($fieldRow['field_name'] === 'Class / Standard') {
                $classStandard = trim((string) $fieldRow['value']);
            }
            if ($fieldRow['field_name'] === 'Section') {
                $section = trim((string) $fieldRow['value']);
            }
        }
        $fieldStmt->close();

        if ($classStandard === '' || $section === '') {
            header("HTTP/1.0 404 Not Found");
            echo json_encode([
                'success' => false,
                'status' => 404,
                'message' => 'Class or section is not configured for this student.'
            ]);
            exit;
        }

        $timeTableSql = "SELECT `id`, `inst_id`, `class`, `section`, `day`, `period`, `time`, `subject`, `teacher`, `status`
            FROM `time_table`
            WHERE `inst_id` = ? AND `class` = ? AND `section` = ? AND `status` = 1";
        $paramTypes = 'sss';
        $paramValues = [$instituteId, $classStandard, $section];

        if ($intent === 'today') {
            $timeTableSql .= " AND TRIM(LOWER(`day`)) = ?";
            $paramTypes .= 's';
            $paramValues[] = strtolower($todayShortDay);
        }

        $timeTableSql .= " ORDER BY FIELD(`day`, 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'), STR_TO_DATE(SUBSTRING_INDEX(`time`, ' - ', 1), '%h:%i %p') ASC";

        $timeTableStmt = $conn->prepare($timeTableSql);
        if (!$timeTableStmt) {
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode([
                'success' => false,
                'status' => 500,
                'message' => 'Failed to prepare timetable query for student.'
            ]);
            exit;
        }

        $timeTableStmt->bind_param($paramTypes, ...$paramValues);
        $timeTableStmt->execute();
        $timeTableResult = $timeTableStmt->get_result();

        while ($timeTableResult && $row = $timeTableResult->fetch_assoc()) {
            $scheduledClasses[] = $row;
        }
        $timeTableStmt->close();
    } else {
        header("HTTP/1.0 403 Forbidden");
        echo json_encode([
            'success' => false,
            'status' => 403,
            'message' => 'This user role is not allowed to access scheduled classes.'
        ]);
        exit;
    }

    $responseData = [
        'success' => true,
        'status' => 200,
        'message' => 'Scheduled classes fetched successfully.',
        'data' => [
            'scheduledClasses' => $scheduledClasses
        ]
    ];

    if ($intent === 'today') {
        $responseData['data']['todayDayName'] = $todayDayName;
    }

    header("HTTP/1.0 200 OK");
    echo json_encode($responseData);
    exit;
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
