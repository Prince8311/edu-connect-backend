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

    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0
        ? (int)$_GET['page']
        : 1;
    $offset = ($page - 1) * $limit;

    $countSql = "SELECT COUNT(*) as total FROM `transport_vehicles` WHERE `inst_id`='$instituteId'";
    $countResult = mysqli_query($conn, $countSql);
    $totalRow = mysqli_fetch_assoc($countResult);
    $totalVehicles = (int)$totalRow['total'];

    $sql = "SELECT `id`, `name`, `number`, `type`, `capacity` FROM `transport_vehicles` WHERE `inst_id`='$instituteId' ORDER BY `id` DESC LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $vehicles = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $vehicles[] = $row;
        }

        $data = [
            'status' => 200,
            'message' => 'Vehicle list fetched successfully.',
            'totalCount' => $totalVehicles,
            'currentPage' => $page,
            'vehicles' => $vehicles
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
