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

if ($requestMethod !== 'GET') {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ]);
    exit;
}

require __DIR__ . "/../../../../_db-connect.php";
global $conn;

$respond = function ($status, $message, $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message,
    ], $extra));
    exit;
};

$instituteId = mysqli_real_escape_string($conn, (string) $authResult['inst_id']);
$limit = 12;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;
$offset = ($page - 1) * $limit;

$countSql = "SELECT COUNT(*) AS `total`
    FROM `transport_passengers`
    WHERE `inst_id` = '$instituteId'";
$countResult = mysqli_query($conn, $countSql);
if (!$countResult) {
    $respond(500, 'Database error: ' . mysqli_error($conn));
}
$totalPassengers = (int) mysqli_fetch_assoc($countResult)['total'];

$sql = "SELECT
        tp.`id`,
        tp.`user_id`,
        tp.`type`,
        CASE
            WHEN tp.`type` = 'Staff' THEN au.`name`
            WHEN tp.`type` = 'Teacher' THEN tu.`name`
            ELSE su.`name`
        END AS `passenger_name`,
        CASE
            WHEN tp.`type` = 'Staff' THEN au.`image`
            WHEN tp.`type` = 'Teacher' THEN tu.`profile_image`
            ELSE su.`profile_image`
        END AS `passenger_image`,
        CASE
            WHEN tp.`type` = 'Staff' THEN au.`phone`
            WHEN tp.`type` = 'Teacher' THEN tu.`phone`
            ELSE su.`phone`
        END AS `passenger_phone`,
        CASE
            WHEN tp.`type` = 'Staff' THEN st.`staff_id`
            WHEN tp.`type` = 'Teacher' THEN t.`staff_id`
            ELSE s.`enrollment_id`
        END AS `enroll_id`,
        tr.`name` AS `route_name`,
        ts.`name` AS `stopage_name`,
        tv.`name` AS `vehicle_name`,
        tv.`number` AS `vehicle_number`
    FROM `transport_passengers` tp
    LEFT JOIN `students` s
        ON tp.`type` = 'Student'
        AND s.`user_id` = tp.`user_id`
        AND s.`inst_id` = tp.`inst_id`
    LEFT JOIN `users` su
        ON tp.`type` = 'Student'
        AND su.`id` = tp.`user_id`
        AND su.`inst_id` = tp.`inst_id`
    LEFT JOIN `teachers` t
        ON tp.`type` = 'Teacher'
        AND t.`user_id` = tp.`user_id`
        AND t.`inst_id` = tp.`inst_id`
    LEFT JOIN `users` tu
        ON tp.`type` = 'Teacher'
        AND tu.`id` = tp.`user_id`
        AND tu.`inst_id` = tp.`inst_id`
    LEFT JOIN `staffs` st
        ON tp.`type` = 'Staff'
        AND st.`admin_id` = tp.`user_id`
        AND st.`inst_id` = tp.`inst_id`
    LEFT JOIN `admin_users` au
        ON tp.`type` = 'Staff'
        AND au.`id` = tp.`user_id`
        AND au.`inst_id` = tp.`inst_id`
    LEFT JOIN `transport_stopages` ts
        ON ts.`id` = tp.`stopage`
        AND ts.`inst_id` = tp.`inst_id`
    LEFT JOIN `transport_routes` tr
        ON tr.`id` = tp.`route`
        AND tr.`inst_id` = tp.`inst_id`
    LEFT JOIN `transport_vehicles` tv
        ON tv.`id` = tr.`assigned_vehicle_id`
        AND tv.`inst_id` = tp.`inst_id`
    WHERE tp.`inst_id` = '$instituteId'
    ORDER BY tp.`id` DESC
    LIMIT $limit OFFSET $offset";

$result = mysqli_query($conn, $sql);
if (!$result) {
    $respond(500, 'Database error: ' . mysqli_error($conn));
}

$passengers = [];
while ($row = mysqli_fetch_assoc($result)) {
    $passengers[] = [
        'id' => $row['id'],
        'name' => $row['passenger_name'],
        'image' => $row['passenger_image'],
        'phone' => $row['passenger_phone'],
        'directory' => $row['type'] === 'Staff' ? 'admin' : 'user',
        'user_type' => $row['type'],
        'enroll_id' => $row['enroll_id'],
        'transport_details' => [
            'route' => $row['route_name'],
            'stopage' => $row['stopage_name'],
            'vehicle' => $row['vehicle_name'],
            'vehicle_number' => $row['vehicle_number'],
        ],
    ];
}

$respond(200, 'Passengers fetched successfully.', [
    'totalCount' => $totalPassengers,
    'currentPage' => $page,
    'passengers' => $passengers,
]);
