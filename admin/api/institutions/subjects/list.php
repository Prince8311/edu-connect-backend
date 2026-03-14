<?php

require "../../../../utils/headers.php";
require "../../../../utils/middleware.php";

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
    require "../../../../_db-connect.php";
    global $conn;
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    $adminSql = "SELECT i.inst_id FROM admin_users a JOIN institutions i ON a.id = i.admin_id WHERE a.id = '$userId' LIMIT 1";
    $adminResult = mysqli_query($conn, $adminSql);

    if (!$adminResult || mysqli_num_rows($adminResult) === 0) {
        echo json_encode([
            "status" => 401,
            "message" => "Invalid token or institute not found"
        ]);
        exit;
    }

    $adminData = mysqli_fetch_assoc($adminResult);
    $instituteId = $adminData['inst_id'];

    $limit = 12;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0
        ? (int) $_GET['page']
        : 1;
    $offset = ($page - 1) * $limit;
    
    // -----------------------
    // SEARCH CONDITION
    // -----------------------
    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
    $searchCondition = "";
    if (!empty($search)) {
        $searchCondition = " AND subject_name LIKE '%$search%'";
    }

    // -----------------------
    // COUNT QUERY
    // -----------------------
    $countSql = "SELECT COUNT(*) AS total FROM `institution_subjects` WHERE `inst_id` = '$instituteId' $searchCondition";
    $countResult  = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $totalSubjects = (int) $countRow['total'];

    // -----------------------
    // DATA QUERY (with LIMIT)
    // -----------------------
    $sql = "SELECT `id`, `inst_id`, `subject_name` FROM `institution_subjects` WHERE `inst_id` = '$instituteId' $searchCondition ORDER BY id ASC LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $subjects = mysqli_fetch_all($result, MYSQLI_ASSOC);

        $data = [
            'status' => 200,
            'message' => 'All subjects fetched.',
            'totalCount' => $totalSubjects,
            'currentPage' => $page,
            'subjects' => $subjects
        ];
        header("HTTP/1.0 200 subjects");
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
