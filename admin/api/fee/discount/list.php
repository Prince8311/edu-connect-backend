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
    if (empty($instituteId)) {
        $data = [
            'status' => 422,
            'message' => 'Institute ID is missing from authentication'
        ];

        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }

    $instIdEsc = mysqli_real_escape_string($conn, (string)$instituteId);
    $limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 10;
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $offset = ($page - 1) * $limit;

    $countSql = "SELECT
        COUNT(CASE WHEN `name` NOT IN ('Staff Child', 'Advance Pay') OR `name` IS NULL THEN 1 END) AS total,
        MAX(CASE WHEN `name` = 'Staff Child' AND `status` = 1 THEN 1 ELSE 0 END) AS staff_child_discount,
        MAX(CASE WHEN `name` = 'Advance Pay' AND `status` = 1 THEN 1 ELSE 0 END) AS advance_pay_discount
        FROM `discounts` WHERE `inst_id`='$instIdEsc'";
    $countResult = mysqli_query($conn, $countSql);
    if ($countResult === false) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ]);
        exit;
    }
    $totalRow = mysqli_fetch_assoc($countResult);
    $totalDiscounts = (int)$totalRow['total'];

    $sql = "SELECT `id`, `name`, `unit`, `type`, `amount`, `discount_limit`, `fee_type` FROM `discounts` WHERE `inst_id`='$instIdEsc' AND (`name` NOT IN ('Staff Child', 'Advance Pay') OR `name` IS NULL) ORDER BY `id` DESC LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $sql);

    if ($result === false) {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    $discounts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $discounts[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'unit' => $row['unit'],
            'type' => $row['type'],
            'amount' => $row['amount'],
            'discount_limit' => $row['discount_limit'],
            'fee_type' => $row['fee_type']
        ];
    }

    $data = [
        'status' => 200,
        'message' => 'Discount list retrieved successfully',
        'totalCount' => $totalDiscounts,
        'currentPage' => $page,
        'staffChildDiscount' => (bool)$totalRow['staff_child_discount'],
        'advancePayDiscount' => (bool)$totalRow['advance_pay_discount'],
        'data' => $discounts
    ];
    header("HTTP/1.0 200 OK");
    echo json_encode($data);
    exit;
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod .
            ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
