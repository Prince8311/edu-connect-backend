<?php

require __DIR__ . "/../../../../../utils/headers.php";
require __DIR__ . "/../../../../../utils/middleware.php";

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
    require __DIR__ . "/../../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    $isForm = isset($_GET['isForm']) && $_GET['isForm'] === 'true';

    if (!$isForm && !isset($_GET['levelId'])) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'status' => 400,
            'message' => 'Academic level id required.'
        ]);
        exit;
    }

    $levelId = isset($_GET['levelId']) ? mysqli_real_escape_string($conn, $_GET['levelId']) : null;

    /**
     * ---------------------------------------
     * Fetch all class teachers
     * ---------------------------------------
     */
    $teacherMap = [];

    $teacherSql = "SELECT t.class_teacher, u.name FROM teachers t LEFT JOIN users u ON u.id = t.user_id WHERE t.inst_id = '$instituteId'";
    $teacherResult = mysqli_query($conn, $teacherSql);

    if ($teacherResult) {
        while ($teacherRow = mysqli_fetch_assoc($teacherResult)) {
            $teacherMap[$teacherRow['class_teacher']] = $teacherRow['name'];
        }
    }

    /**
     * ---------------------------------------
     * Fetch student counts grouped by class+section
     * ---------------------------------------
     */
    $studentCountMap = [];

    $studentSql = "SELECT classField.value AS class_name, sectionField.value AS section_name, COUNT(DISTINCT classField.student_id) AS total_students FROM student_field_values classField INNER JOIN student_field_values sectionField ON classField.student_id = sectionField.student_id AND classField.inst_id = sectionField.inst_id WHERE classField.inst_id = '$instituteId' AND classField.field_name = 'Class / Standard' AND sectionField.field_name = 'Section' GROUP BY classField.value, sectionField.value";
    $studentResult = mysqli_query($conn, $studentSql);

    if ($studentResult) {
        while ($studentRow = mysqli_fetch_assoc($studentResult)) {
            $key = $studentRow['class_name'] . "|" . $studentRow['section_name'];
            $studentCountMap[$key] = (int)$studentRow['total_students'];
        }
    }

    /**
     * ---------------------------------------
     * Fetch classes
     * ---------------------------------------
     */
    $sql = "SELECT id, level_id, class, sections FROM academic_class_sections WHERE inst_id = '$instituteId'";

    if (!$isForm) {
        $sql .= " AND level_id = '$levelId'";
    }

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ]);
        exit;
    }

    $classes = [];

    while ($row = mysqli_fetch_assoc($result)) {
        if ($isForm) {
            preg_match('/\d+/', $row['class'], $matches);
            $classes[] = $matches[0] ?? null;
            continue;
        }

        $className = $row['class'];
        preg_match('/\d+/', $className, $matches);
        $classNumber = $matches[0] ?? '';
        $classSections = [];

        if (!empty($row['sections'])) {
            $sections = explode(',', $row['sections']);

            foreach ($sections as $section) {
                $section = trim($section);
                $studentCountKey = $className . "|" . $section;
                $teacherKey = $classNumber . "-" . $section;

                $classSections[] = [
                    "class" => $className,
                    "section" => $section,
                    "student_count" => $studentCountMap[$studentCountKey] ?? 0,
                    "class_teacher" => $teacherMap[$teacherKey] ?? null
                ];
            }
        }

        $allSections = [];

        if (!empty($row['sections'])) {
            $allSections = array_map('trim', explode(',', $row['sections']));
        }

        // Compute total students for the whole class by summing section counts
        $classTotal = 0;
        if (!empty($classSections)) {
            foreach ($classSections as $cs) {
                $classTotal += isset($cs['student_count']) ? (int)$cs['student_count'] : 0;
            }
        }

        $classes[] = [
            "class" => $className,
            "all_sections" => $allSections,
            "total_students" => $classTotal,
            "sections" => $classSections
        ];
    }

    if ($isForm) {
        $data = [
            'status' => 200,
            'message' => 'Classes fetched for form.',
            'data' => $classes
        ];
    } else {
        $data = [
            'status' => 200,
            'message' => 'Classes fetched.',
            'classes' => $classes
        ];
    }

    header("HTTP/1.0 200 OK");
    echo json_encode($data);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
