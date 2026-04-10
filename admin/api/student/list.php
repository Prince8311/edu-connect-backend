<?php

require "../../../utils/headers.php";
require "../../../utils/middleware.php";

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
    require "../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    if (!isset($_GET['levelId'])) {
        $data = [
            'status' => 400,
            'message' => 'Academic level id required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $levelId = mysqli_real_escape_string($conn, $_GET['levelId']);

    $classFilter   = isset($_GET['class']) ? mysqli_real_escape_string($conn, $_GET['class']) : null;
    $sectionFilter = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : null;

    if ($sectionFilter && !$classFilter) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "Class is required when section is provided"
        ]);
        exit;
    }

    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0
        ? (int)$_GET['page']
        : 1;
    $offset = ($page - 1) * $limit;

    $conditions = [
        "acs.level_id = '$levelId'",
        "acs.inst_id = '$instituteId'",
        "s.inst_id = '$instituteId'"
    ];

    if ($classFilter) {
        $conditions[] = "acs.class = '$classFilter'";
    }

    if ($sectionFilter) {
        $conditions[] = "acs.sections = '$sectionFilter'";
    }

    $where = implode(" AND ", $conditions);

    $countSql = "SELECT COUNT(DISTINCT s.id) as total FROM academic_class_sections acs JOIN student_field_values sfv ON acs.id = sfv.section_id JOIN students s ON s.id = sfv.student_id WHERE $where";
    $countResult = mysqli_query($conn, $countSql);
    $totalRow = mysqli_fetch_assoc($countResult);
    $totalStudents = (int)$totalRow['total'];

    $sql = "SELECT s.id, s.enrollment_id, s.status, u.profile_image, MAX(CASE WHEN sfv.field_name = 'First Name' THEN sfv.value END) AS first_name, MAX(CASE WHEN sfv.field_name = 'Middle Name' THEN sfv.value END) AS middle_name, MAX(CASE WHEN sfv.field_name = 'Last Name' THEN sfv.value END) AS last_name, MAX(CASE WHEN sfv.field_name = 'Contact No.' THEN sfv.value END) AS contact_no, MAX(CASE WHEN sfv.field_name = 'Class / Standard' THEN sfv.value END) AS class, MAX(CASE WHEN sfv.field_name = 'Section' THEN sfv.value END) AS section FROM academic_class_sections acs JOIN student_field_values sfv ON acs.id = sfv.section_id JOIN students s ON s.id = sfv.student_id JOIN users u ON u.id = s.user_id WHERE $where GROUP BY s.id, s.enrollment_id, s.status, u.profile_image ORDER BY s.id DESC LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $students = [];
        while ($row = mysqli_fetch_assoc($result)) {

            $fullName = trim(
                ($row['first_name'] ?? '') . ' ' .
                    ($row['middle_name'] ?? '') . ' ' .
                    ($row['last_name'] ?? '')
            );

            $students[] = [
                "id" => $row['id'],
                "enrollment_id" => $row['enrollment_id'],
                "status" => (bool)$row['status'],
                "profile_image" => $row['profile_image'],
                "first_name" => $row['first_name'],
                "middle_name" => $row['middle_name'],
                "last_name" => $row['last_name'],
                "name" => $fullName,
                "contact_no" => $row['contact_no'],
                "class" => $row['class'],
                "section" => $row['section']
            ];
        }
        $data = [
            'status' => 200,
            'message' => 'Student list fetched successfully',
            'totalCount' => $totalStudents,
            'currentPage' => $page,
            'students' => $students
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
