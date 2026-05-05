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

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    if (!isset($_GET['staffType'])) {
        $data = [
            'status' => 400,
            'message' => 'Staff type required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $staffType = mysqli_real_escape_string($conn, $_GET['staffType']);

    if (!in_array($staffType, ['teaching', 'non-teaching'], true)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "Invalid staffType: $staffType"
        ]);
        exit;
    }

    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0
        ? (int)$_GET['page']
        : 1;
    $offset = ($page - 1) * $limit;

    $staffs = [];

    if ($staffType === 'teaching') {
        $countQuery = "
            SELECT COUNT(*) as total 
            FROM users u
            JOIN teachers t ON t.user_id = u.id AND t.inst_id = '$instituteId'
            WHERE u.user_type = 'teacher'
        ";
        $query = "
            SELECT 
                u.id, 
                u.name, 
                u.profile_image, 
                u.email, 
                u.phone, 
                u.user_type, 
                u.status, 
                t.staff_id, 
                t.created_at,
                sfv.value AS subject
            FROM users u
            JOIN teachers t ON t.user_id = u.id AND t.inst_id = '$instituteId'
            LEFT JOIN staff_field_values sfv ON sfv.staff_id = t.id AND sfv.field_name = 'Subject' AND sfv.staff_type = 'teaching' AND sfv.inst_id = '$instituteId'
            WHERE u.user_type = 'teacher'
            ORDER BY u.id DESC
            LIMIT $limit OFFSET $offset
        ";
    } else {
        $countQuery = "
            SELECT COUNT(*) as total 
            FROM admin_users au
            JOIN staffs s ON s.admin_id = au.id AND s.inst_id = '$instituteId'
            WHERE au.user_type = 'inst_admin'
        ";
        $query = "
            SELECT 
                au.id, 
                au.name, 
                au.image, 
                au.email, 
                au.phone, 
                au.status, 
                au.user_type, 
                au.user_role, 
                s.staff_id, 
                s.created_at
            FROM admin_users au
            JOIN staffs s ON s.admin_id = au.id AND s.inst_id = '$instituteId'
            WHERE au.user_type = 'inst_admin'
            ORDER BY au.id DESC
            LIMIT $limit OFFSET $offset
        ";
    }

    $countResult = mysqli_query($conn, $countQuery);
    if (!$countResult) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Database count query failed"
        ]);
        exit;
    }
    $totalRow = mysqli_fetch_assoc($countResult);
    $totalStaffs = (int)$totalRow['total'];

    $result = mysqli_query($conn, $query);

    if (!$result) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Database query failed"
        ]);
        exit;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $staffs[] = $row;
    }

    header("HTTP/1.0 200 OK");
    echo json_encode([
        "status" => 200,
        "message" => "Staffs fetched successfully",
        "totalCount" => $totalStaffs,
        "currentPage" => $page,
        "staffs" => $staffs
    ]);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
