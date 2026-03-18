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
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    $adminSql = "SELECT * FROM `admin_users` WHERE `id` = '$userId' LIMIT 1";;
    $adminResult = mysqli_query($conn, $adminSql);

    if (!$adminResult || mysqli_num_rows($adminResult) === 0) {
        echo json_encode([
            "status" => 401,
            "message" => "Invalid token or institute not found"
        ]);
        exit;
    }

    $limit = 10;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0
        ? (int)$_GET['page']
        : 1;
    $offset = ($page - 1) * $limit;

    // First, get total distinct states
    $totalResult = mysqli_query($conn, "SELECT COUNT(DISTINCT state) AS total FROM state_cities");
    $totalRow = mysqli_fetch_assoc($totalResult);
    $totalStates = (int)$totalRow['total'];

    // Fetch paginated distinct states
    $statesSql = "SELECT DISTINCT state FROM state_cities ORDER BY state ASC LIMIT $limit OFFSET $offset";
    $statesResult = mysqli_query($conn, $statesSql);
    $states = [];
    while ($row = mysqli_fetch_assoc($statesResult)) {
        $states[] = $row['state'];
    }

    // Fetch cities for selected states
    $statesInClause = "'" . implode("','", array_map('mysqli_real_escape_string', array_fill(0, count($states), $conn), $states)) . "'";
    $citiesSql = "SELECT state, city AS city FROM state_cities WHERE state IN ($statesInClause) ORDER BY state, city";
    $citiesResult = mysqli_query($conn, $citiesSql);

    $grouped = [];

    while ($row = mysqli_fetch_assoc($citiesResult)) {
        $state = $row['state'];
        $city = $row['city'];

        if (!isset($grouped[$state])) {
            $grouped[$state] = [
                "state" => $state,
                "cities" => []
            ];
        }

        $grouped[$state]["cities"][] = $city;
    }

    $responseData = array_values($grouped);

    $data = [
        'status' => 200,
        'message' => 'States and cities fetched successfully.',
        'totalCount' => $totalStates,
        'currentPage' => $page,
        'stateCities' => $responseData
    ];

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
