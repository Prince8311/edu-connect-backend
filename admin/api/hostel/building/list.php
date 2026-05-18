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
        $searchCondition = " AND name LIKE '%$search%'";
    }

    // -----------------------
    // SHOW ONLY ACTIVE FOR showAll
    // -----------------------
    $statusCondition = "";

    if ($showAll) {
        $statusCondition = " AND status = 1";
    }

    // -----------------------
    // COUNT QUERY
    // -----------------------
    $countSql = "SELECT COUNT(*) AS total FROM hostel_buildings WHERE inst_id = '$instituteId' $searchCondition";
    $countResult = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $totalBuildings = (int) $countRow['total'];

    // -----------------------
    // DATA QUERY
    // -----------------------
    if ($showAll) {
        $sql = "SELECT * FROM hostel_buildings WHERE inst_id = '$instituteId' $searchCondition $statusCondition ORDER BY id ASC";
    } else {
        $sql = "SELECT * FROM hostel_buildings WHERE inst_id = '$instituteId' $searchCondition ORDER BY id ASC LIMIT $limit OFFSET $offset";
    }

    $result = mysqli_query($conn, $sql);

    if ($result) {
        $buildings = mysqli_fetch_all($result, MYSQLI_ASSOC);

        // -----------------------
        // STATUS => BOOLEAN
        // -----------------------
        $buildings = array_map(function ($item) {
            $item['status'] = $item['status'] == 1;
            return $item;
        }, $buildings);

        // -----------------------
        // RESPONSE
        // -----------------------
        if ($showAll) {
            $buildings = array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'building_name' => $item['name'],
                    'total_floors' => $item['total_floors']
                ];
            }, $buildings);

            $data = [
                'status' => 200,
                'message' => 'All active buildings fetched.',
                'buildings' => $buildings
            ];
        } else {
            $data = [
                'status' => 200,
                'message' => 'All buildings fetched.',
                'totalCount' => $totalBuildings,
                'currentPage' => $page,
                'buildings' => $buildings
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
