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
    $studentClass = null;
    $studentSection = null;
    $teacherClassRoom = null;

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
        $classTeacherRaw = isset($teacherRow['class_teacher']) ? trim((string) $teacherRow['class_teacher']) : '';
        if ($classTeacherRaw !== '') {
            $classTeacherParts = preg_split('/\s*-\s*/', $classTeacherRaw, 2);
            if (is_array($classTeacherParts) && count($classTeacherParts) === 2) {
                $teacherClassRoom = trim((string) $classTeacherParts[0]) . ' - ' . trim((string) $classTeacherParts[1]);
            } else {
                $teacherClassRoom = $classTeacherRaw;
            }
        }

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

        $studentClass = $classStandard;
        $studentSection = $section;

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

    if ($intent === 'weekly') {
        $shortDayToFull = [
            'Sun' => 'Sunday',
            'Mon' => 'Monday',
            'Tue' => 'Tuesday',
            'Wed' => 'Wednesday',
            'Thu' => 'Thursday',
            'Fri' => 'Friday',
            'Sat' => 'Saturday',
        ];

        $teacherNameByTeacherId = [];
        if ($userType === 'student' || $userType === 'guardian') {
            $teacherIds = [];
            foreach ($scheduledClasses as $row) {
                $teacherIdRaw = isset($row['teacher']) ? trim((string) $row['teacher']) : '';
                if ($teacherIdRaw !== '' && strtolower($teacherIdRaw) !== 'n/a') {
                    $teacherIds[$teacherIdRaw] = true;
                }
            }

            if (!empty($teacherIds)) {
                $escapedTeacherIds = [];
                foreach (array_keys($teacherIds) as $teacherId) {
                    $escapedTeacherIds[] = "'" . mysqli_real_escape_string($conn, $teacherId) . "'";
                }

                $teacherLookupSql = "SELECT t.`id` AS `teacher_id`, u.`name` AS `teacher_name`
                    FROM `teachers` t
                    LEFT JOIN `users` u ON u.`id` = t.`user_id`
                    WHERE t.`inst_id` = '$instituteId' AND t.`id` IN (" . implode(',', $escapedTeacherIds) . ")";
                $teacherLookupResult = mysqli_query($conn, $teacherLookupSql);

                if ($teacherLookupResult) {
                    while ($teacherRow = mysqli_fetch_assoc($teacherLookupResult)) {
                        $teacherKey = (string) $teacherRow['teacher_id'];
                        $teacherNameByTeacherId[$teacherKey] = isset($teacherRow['teacher_name'])
                            ? trim((string) $teacherRow['teacher_name'])
                            : '';
                    }
                }
            }
        }

        $groupedByDay = [];
        foreach ($scheduledClasses as $row) {
            $shortDay = isset($row['day']) ? trim((string) $row['day']) : '';
            $fullDay = isset($shortDayToFull[$shortDay]) ? $shortDayToFull[$shortDay] : $shortDay;

            if (!isset($groupedByDay[$fullDay])) {
                $groupedByDay[$fullDay] = [
                    'day' => $fullDay,
                    'classes' => []
                ];
            }

            $classItem = [
                'id' => $row['id'],
                'period' => $row['period'],
                'time' => $row['time'],
                'subject' => $row['subject'],
            ];

            if ($userType === 'teacher') {
                $classItem['class'] = $row['class'];
                $classItem['section'] = $row['section'];
            }

            if ($userType === 'student' || $userType === 'guardian') {
                $teacherIdRaw = isset($row['teacher']) ? trim((string) $row['teacher']) : '';
                $classItem['teacher'] = $teacherNameByTeacherId[$teacherIdRaw] ?? null;
            }

            $groupedByDay[$fullDay]['classes'][] = $classItem;
        }

        $dayOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $weeklyGroupedClasses = [];
        foreach ($dayOrder as $dayName) {
            if (isset($groupedByDay[$dayName])) {
                $weeklyGroupedClasses[] = $groupedByDay[$dayName];
            }
        }

        foreach ($groupedByDay as $dayName => $dayData) {
            if (!in_array($dayName, $dayOrder, true)) {
                $weeklyGroupedClasses[] = $dayData;
            }
        }

        $scheduledClasses = $weeklyGroupedClasses;
    }

    $responseData = [
        'success' => true,
        'status' => 200,
        'message' => 'Scheduled classes fetched successfully.',
        'data' => [
            'scheduled_classes' => $scheduledClasses
        ]
    ];

    if ($userType === 'student' || $userType === 'guardian') {
        $responseData['data']['class_room'] = trim((string) $studentClass) . ' - ' . trim((string) $studentSection);
    }

    if ($userType === 'teacher') {
        $responseData['data']['class_teacher'] = $teacherClassRoom;
    }

    if ($intent === 'today') {
        $responseData['data']['today_day_name'] = $todayDayName;
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
