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

    $couponId = isset($_GET['couponId']) ? trim($_GET['couponId']) : '';

    if ($couponId === '' || !ctype_digit($couponId)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "couponId is required and must be a valid integer."
        ]);
        exit;
    }

    $couponId = mysqli_real_escape_string($conn, $couponId);

    $typeResult = mysqli_query($conn, "SELECT type FROM coupons WHERE id='$couponId'");

    if (!$typeResult || mysqli_num_rows($typeResult) === 0) {
        header("HTTP/1.0 404 Not Found");
        echo json_encode([
            "status" => 404,
            "message" => "Coupon not found."
        ]);
        exit;
    }

    $couponRow = mysqli_fetch_assoc($typeResult);
    $type = strtolower($couponRow['type']);

    if ($type === 'general') {
        $sql = "SELECT
                    code,
                    type,
                    bill_amount_range,
                    target_bill_amount,
                    offer_type,
                    offer_value,
                    offer_unit,
                    offer_limit,
                    validity_type,
                    count_type,
                    count_value,
                    validity_date,
                    status
                FROM coupons
                WHERE id='$couponId' AND type='general'";
    } else {
        $sql = "SELECT
                    c.code,
                    c.type,
                    c.bill_amount_range,
                    c.target_bill_amount,
                    c.offer_type,
                    c.offer_value,
                    c.offer_unit,
                    c.offer_limit,
                    c.validity_type,
                    c.count_type,
                    c.count_value,
                    c.validity_date,
                    c.status,
                    i.inst_id,
                    i.inst_name,
                    i.phone,
                    i.email
                FROM coupons c
                LEFT JOIN institutions i
                    ON c.inst_id = i.inst_id
                WHERE c.id='$couponId' AND c.type='private'";
    }

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => mysqli_error($conn)
        ]);
        exit;
    }

    if (mysqli_num_rows($result) === 0) {
        header("HTTP/1.0 404 Not Found");
        echo json_encode([
            "status" => 404,
            "message" => "Coupon not found."
        ]);
        exit;
    }

    $row = mysqli_fetch_assoc($result);

    if (!empty($row['type'])) {
        $row['type'] = ucfirst($row['type']);
    }

    if (!empty($row['bill_amount_range'])) {
        $row['bill_amount_range'] = ucfirst($row['bill_amount_range']);
    }

    if (!empty($row['offer_type'])) {
        $row['offer_type'] = ucfirst($row['offer_type']);
    }

    if (!empty($row['offer_unit'])) {
        $row['offer_unit'] = ucfirst($row['offer_unit']);
    }

    if (!empty($row['validity_type'])) {
        $row['validity_type'] = ucfirst($row['validity_type']);
    }

    if (!empty($row['count_type'])) {
        $row['count_type'] = ucwords(str_replace('_', ' ', $row['count_type']));
    }

    $row['status'] = (bool)$row['status'];

    if ($type === 'private') {
        $institution = [
            'inst_id' => $row['inst_id'],
            'inst_name' => $row['inst_name'],
            'phone' => $row['phone'],
            'email' => $row['email']
        ];

        unset($row['inst_id'], $row['inst_name'], $row['phone'], $row['email']);
        $row['institution'] = $institution;
    }

    header("HTTP/1.0 200 OK");
    echo json_encode([
        "status" => 200,
        "message" => "Coupon fetched successfully.",
        "data" => [$row]
    ]);
} else {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ]);
}
