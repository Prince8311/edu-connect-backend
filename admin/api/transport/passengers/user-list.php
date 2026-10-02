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
    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $searchLike = '%' . $search . '%';

    $sql = "SELECT `id`, `name`, `image`, `enroll_id`, `type`, `directory`, `type_order`, `record_order`
            FROM (
            SELECT au.`id`, au.`name`, au.`image`, s.`staff_id` AS `enroll_id`,
                   'Staff' AS `type`, 'admin' AS `directory`,
                   1 AS `type_order`, s.`id` AS `record_order`
            FROM `staffs` s
            INNER JOIN `admin_users` au
                ON au.`id` = s.`admin_id`
                AND au.`inst_id` = s.`inst_id`
            WHERE s.`inst_id` = ?

            UNION ALL

            SELECT u.`id`, u.`name`, u.`profile_image` AS `image`,
                   t.`staff_id` AS `enroll_id`, 'Teacher' AS `type`,
                   'user' AS `directory`, 2 AS `type_order`, t.`id` AS `record_order`
            FROM `teachers` t
            INNER JOIN `users` u
                ON u.`id` = t.`user_id`
                AND u.`inst_id` = t.`inst_id`
            WHERE t.`inst_id` = ?

            UNION ALL

            SELECT u.`id`, u.`name`, u.`profile_image` AS `image`,
                   s.`enrollment_id` AS `enroll_id`, 'Student' AS `type`,
                   'user' AS `directory`, 3 AS `type_order`, s.`id` AS `record_order`
            FROM `students` s
            INNER JOIN `users` u
                ON u.`id` = s.`user_id`
                AND u.`inst_id` = s.`inst_id`
            WHERE s.`inst_id` = ?

            ) AS `passenger_users`
            WHERE (? = ''
                OR `name` LIKE ?
                OR CAST(`enroll_id` AS CHAR) LIKE ?)
            ORDER BY `type_order` ASC, `record_order` DESC";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare user list query.'
        ]);
        exit;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'ssssss',
        $instituteId,
        $instituteId,
        $instituteId,
        $search,
        $searchLike,
        $searchLike
    );

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch user list.'
        ]);
        exit;
    }

    $result = mysqli_stmt_get_result($stmt);
    $users = [];

    while ($row = mysqli_fetch_assoc($result)) {
        unset($row['type_order'], $row['record_order']);
        $users[] = $row;
    }

    mysqli_stmt_close($stmt);

    header("HTTP/1.0 200 OK");
    echo json_encode([
        'status' => 200,
        'message' => 'User list fetched successfully.',
        'users' => $users
    ]);
} else {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ]);
}
