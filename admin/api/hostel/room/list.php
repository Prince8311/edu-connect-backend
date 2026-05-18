<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

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
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    // -----------------------
    // PAGINATION
    // -----------------------
    $limit = 12;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
    $showAll = isset($_GET['showAll']) && $_GET['showAll'] === 'true';
    $offset = ($page - 1) * $limit;

    // -----------------------
    // SEARCH
    // -----------------------
    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
    $searchCondition = "";

    if (!empty($search)) {
        $searchCondition .= "AND (hr.room_no LIKE '%$search%' OR hb.name LIKE '%$search%' OR hr.type LIKE '%$search%')";
    }

    // -----------------------
    // BUILDING FILTER
    // -----------------------
    $buildingId = isset($_GET['building_id']) && is_numeric($_GET['building_id']) ? (int) $_GET['building_id'] : null;
    $buildingCondition = "";

    if (!empty($buildingId)) {
        $buildingCondition = " AND hr.building_id = '$buildingId'";
    }

    // -----------------------
    // CATEGORY FILTER
    // Required by frontend and must be an enum
    // -----------------------
    $category = isset($_GET['category']) ? mysqli_real_escape_string($conn, $_GET['category']) : '';
    $allowedCategories = ['Living Room', 'Sick Room'];
    $categoryCondition = "";

    if (empty($category)) {
        $data = [
            'status' => 400,
            'message' => 'Category is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if (!in_array($category, $allowedCategories)) {
        $data = [
            'status' => 400,
            'message' => 'Invalid room category.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $categoryCondition = " AND hr.category = '$category'";

    // -----------------------
    // ACTIVE STATUS FILTER
    // ONLY FOR showAll
    // -----------------------
    $statusCondition = "";

    if ($showAll) {
        $statusCondition = " AND hr.status = 1";
    }

    // -----------------------
    // COUNT QUERY
    // -----------------------
    $countSql = "SELECT COUNT(*) AS total FROM hostel_rooms hr LEFT JOIN hostel_buildings hb ON hr.building_id = hb.id WHERE hr.inst_id = '$instituteId' $searchCondition $buildingCondition $categoryCondition $statusCondition";
    $countResult = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $totalRooms = (int) $countRow['total'];

    // -----------------------
    // DATA QUERY
    // -----------------------
    $baseQuery = "SELECT hr.*, hb.name AS building_name FROM hostel_rooms hr LEFT JOIN hostel_buildings hb ON hr.building_id = hb.id WHERE hr.inst_id = '$instituteId' $searchCondition $buildingCondition $categoryCondition $statusCondition ORDER BY hr.id ASC";

    if ($showAll) {
        $sql = $baseQuery;
    } else {
        $sql = $baseQuery . " LIMIT $limit OFFSET $offset";
    }

    $result = mysqli_query($conn, $sql);

    if ($result) {
        $rooms = mysqli_fetch_all($result, MYSQLI_ASSOC);

        // -----------------------
        // STATUS => BOOLEAN
        // -----------------------
        $rooms = array_map(function ($item) {
            $item['status'] = $item['status'] == 1;
            return $item;
        }, $rooms);

        // -----------------------
        // RESPONSE
        // -----------------------
        if ($showAll) {
            $data = [
                'status' => 200,
                'message' => 'All active rooms fetched.',
                'rooms' => $rooms
            ];
        } else {
            $data = [
                'status' => 200,
                'message' => 'All rooms fetched.',
                'totalCount' => $totalRooms,
                'currentPage' => $page,
                'rooms' => $rooms
            ];
        }

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
