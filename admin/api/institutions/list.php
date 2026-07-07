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

    $isForm = isset($_GET['isForm']) && $_GET['isForm'] === 'true';

    $limit = 12;
    $page = (isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0)
        ? (int)$_GET['page']
        : 1;

    $offset = ($page - 1) * $limit;

    // -----------------------
    // SEARCH
    // -----------------------
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $searchCondition = "";

    if (!empty($search)) {
        $search = mysqli_real_escape_string($conn, $search);

        $searchCondition = " AND (
            inst_name LIKE '%$search%' OR
            inst_id LIKE '%$search%' OR
            phone LIKE '%$search%' OR
            email LIKE '%$search%'
        )";
    }

    // -----------------------
    // FORM MODE
    // -----------------------
    if ($isForm) {
        $sql = "SELECT inst_id, inst_name, phone, email, image FROM institutions WHERE status = 1 $searchCondition ORDER BY inst_name ASC";
        $result = mysqli_query($conn, $sql);

        if ($result) {
            $institutions = mysqli_fetch_all($result, MYSQLI_ASSOC);

            header("HTTP/1.0 200 OK");
            echo json_encode([
                "status" => 200,
                "message" => "Institutions fetched successfully.",
                "institutions" => $institutions
            ]);
        } else {

            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode([
                "status" => 500,
                "message" => "Database error: " . mysqli_error($conn)
            ]);
        }

        exit;
    }

    // -----------------------
    // COUNT
    // -----------------------
    $countSql = "SELECT COUNT(*) AS total FROM institutions WHERE 1=1 $searchCondition";
    $countResult = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $totalInstitutions = (int)$countRow['total'];

    // -----------------------
    // PAGINATED DATA
    // -----------------------
    $sql = "SELECT * FROM institutions WHERE 1=1 $searchCondition ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $institutions = mysqli_fetch_all($result, MYSQLI_ASSOC);

        header("HTTP/1.0 200 OK");
        echo json_encode([
            "status" => 200,
            "message" => "Institutions fetched successfully.",
            "totalCount" => $totalInstitutions,
            "currentPage" => $page,
            "institutions" => $institutions
        ]);
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Database error: " . mysqli_error($conn)
        ]);
    }

} else {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        "status" => 405,
        "message" => $requestMethod . " Method Not Allowed"
    ]);
}