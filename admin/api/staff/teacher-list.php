<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

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
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $subject = isset($_GET['subject'])
        ? mysqli_real_escape_string($conn, trim($_GET['subject']))
        : '';

    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0
        ? (int)$_GET['page']
        : 1;
    $offset = ($page - 1) * $limit;

    $subjectCondition = "";
    if (!empty($subject)) {
        $subjectCondition = " AND sfv.value = '$subject' ";
    }

    $countQuery = "SELECT COUNT(DISTINCT t.id) AS total FROM teachers t LEFT JOIN staff_field_values sfv ON sfv.staff_id = t.id AND sfv.staff_type = 'teacher' AND sfv.field_name = 'Subject' AND sfv.inst_id = '$instituteId' WHERE t.inst_id = '$instituteId' $subjectCondition";
    $countResult = mysqli_query($conn, $countQuery);

    if (!$countResult) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Count query failed"
        ]);
        exit;
    }

    $countRow = mysqli_fetch_assoc($countResult);
    $totalTeachers = (int)$countRow['total'];

    $query = "SELECT t.id, t.user_id, t.staff_id, t.class_teacher, t.created_at, u.name, u.profile_image, u.email, u.phone, u.status, sfv.value AS subject FROM teachers t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN staff_field_values sfv ON sfv.staff_id = t.id AND sfv.staff_type = 'teaching' AND sfv.field_name = 'Subject' AND sfv.inst_id = '$instituteId' WHERE t.inst_id = '$instituteId' $subjectCondition ORDER BY t.id DESC LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $query);

    if (!$result) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Database query failed"
        ]);
        exit;
    }

    $teachers = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $row['status'] = isset($row['status'])
            ? (bool)$row['status']
            : false;
        $teachers[] = $row;
    }

    header("HTTP/1.0 200 OK");
    echo json_encode([
        "status" => 200,
        "message" => "Teachers fetched successfully",
        "totalCount" => $totalTeachers,
        "currentPage" => $page,
        "teachers" => $teachers
    ]);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
