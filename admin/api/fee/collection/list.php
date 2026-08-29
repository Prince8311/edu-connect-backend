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
    if (empty($instituteId)) {
        $data = [
            'status' => 422,
            'message' => 'Institute ID is missing from authentication'
        ];
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }

    if (!isset($_GET['levelId'])) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'status' => 400,
            'message' => 'Academic level id required.'
        ]);
        exit;
    }

    $levelId = trim((string)$_GET['levelId']);

    if ($levelId === '' || !ctype_digit($levelId) || (int)$levelId <= 0) {
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode([
            'status' => 422,
            'message' => 'Academic level id must be a positive integer.'
        ]);
        exit;
    }

    $sql = "SELECT `id`, `class`, `sections`
            FROM `academic_class_sections`
            WHERE `inst_id` = ? AND `level_id` = ?
            ORDER BY `class` ASC";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare class and section query.'
        ]);
        exit;
    }

    $levelId = (int)$levelId;
    mysqli_stmt_bind_param($stmt, 'si', $instituteId, $levelId);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch classes and sections.'
        ]);
        exit;
    }

    $result = mysqli_stmt_get_result($stmt);
    $classes = [];
    $classSectionIndexes = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $sections = [];

        if (!empty($row['sections'])) {
            $sectionNames = array_values(array_filter(
                array_map('trim', explode(',', $row['sections'])),
                static function ($section) {
                    return $section !== '';
                }
            ));

            foreach ($sectionNames as $sectionName) {
                $sections[] = [
                    'section' => $sectionName,
                    'students' => []
                ];
            }
        }

        $classIndex = count($classes);
        $classes[] = [
            'id' => (int)$row['id'],
            'class' => $row['class'],
            'sections' => $sections
        ];

        foreach ($sections as $sectionIndex => $section) {
            $classSectionIndexes[(string)$row['class']][$section['section']] = [
                'class_index' => $classIndex,
                'section_index' => $sectionIndex
            ];
        }
    }

    mysqli_stmt_close($stmt);

    $studentSql = "SELECT
                        s.`id` AS `student_id`,
                        s.`enrollment_id`,
                        MAX(CASE WHEN sfv.`field_name` = 'First Name' THEN sfv.`value` END) AS `first_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Middle Name' THEN sfv.`value` END) AS `middle_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Last Name' THEN sfv.`value` END) AS `last_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Contact No.' THEN sfv.`value` END) AS `contact_no`,
                        MAX(CASE WHEN sfv.`field_name` = 'Date of Admission' THEN sfv.`value` END) AS `date_of_admission`,
                        class_field.`value` AS `class_name`,
                        section_field.`value` AS `section_name`
                    FROM `students` s
                    INNER JOIN `student_field_values` class_field
                        ON class_field.`student_id` = s.`id`
                        AND class_field.`inst_id` = s.`inst_id`
                        AND class_field.`field_name` = 'Class / Standard'
                    INNER JOIN `student_field_values` section_field
                        ON section_field.`student_id` = s.`id`
                        AND section_field.`inst_id` = s.`inst_id`
                        AND section_field.`section_id` = class_field.`section_id`
                        AND section_field.`field_name` = 'Section'
                    LEFT JOIN `student_field_values` sfv
                        ON sfv.`student_id` = s.`id`
                        AND sfv.`inst_id` = s.`inst_id`
                        AND sfv.`section_id` = class_field.`section_id`
                        AND sfv.`field_name` IN (
                            'First Name',
                            'Middle Name',
                            'Last Name',
                            'Contact No.',
                            'Date of Admission'
                        )
                    WHERE s.`inst_id` = ?
                        AND s.`status` = 1
                    GROUP BY
                        s.`id`,
                        s.`enrollment_id`,
                        class_field.`value`,
                        section_field.`value`";
    $studentStmt = mysqli_prepare($conn, $studentSql);

    if (!$studentStmt) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare student query.'
        ]);
        exit;
    }

    mysqli_stmt_bind_param($studentStmt, 's', $instituteId);

    if (!mysqli_stmt_execute($studentStmt)) {
        mysqli_stmt_close($studentStmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch students.'
        ]);
        exit;
    }

    $studentResult = mysqli_stmt_get_result($studentStmt);

    while ($student = mysqli_fetch_assoc($studentResult)) {
        $className = trim((string)$student['class_name']);
        $sectionName = trim((string)$student['section_name']);

        if (!isset($classSectionIndexes[$className][$sectionName])) {
            continue;
        }

        $indexes = $classSectionIndexes[$className][$sectionName];
        $classes[$indexes['class_index']]['sections'][$indexes['section_index']]['students'][] = [
            'student_id' => (int)$student['student_id'],
            'enrollment_id' => $student['enrollment_id'],
            'first_name' => $student['first_name'],
            'middle_name' => $student['middle_name'],
            'last_name' => $student['last_name'],
            'contact_no' => $student['contact_no'],
            'date_of_admission' => $student['date_of_admission']
        ];
    }

    mysqli_stmt_close($studentStmt);

    header("HTTP/1.0 200 OK");
    echo json_encode([
        'status' => 200,
        'message' => 'Students fetched.',
        'classes' => $classes
    ]);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod .
            ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
