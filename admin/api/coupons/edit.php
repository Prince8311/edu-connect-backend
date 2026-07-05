<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

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

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $inputData = json_decode(file_get_contents('php://input'), true);

    if (empty($inputData)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "Empty request data"
        ]);
        exit;
    }

    $couponId = $inputData['couponId'] ?? null;

    if (empty($couponId)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "couponId is required."
        ]);
        exit;
    }

    $checkSql = "SELECT * FROM `coupons` WHERE `id`='$couponId'";
    $checkResult = mysqli_query($conn, $checkSql);

    if (!$checkResult || mysqli_num_rows($checkResult) == 0) {
        header("HTTP/1.0 404 Not Found");
        echo json_encode([
            "status" => 404,
            "message" => "Coupon not found."
        ]);
        exit;
    }

    $allowedFields = [
        "type",
        "bill_amount_range",
        "target_bill_amount",
        "offer_type",
        "offer_value",
        "offer_unit",
        "offer_limit",
        "validity_type",
        "count_type",
        "count_value",
        "validity_date",
        "status"
    ];

    $updateFields = [];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $inputData) && $inputData[$field] !== null) {
            $value = mysqli_real_escape_string($conn, $inputData[$field]);
            if (is_numeric($inputData[$field])) {
                $updateFields[] = "`$field`=$value";
            } else {
                $updateFields[] = "`$field`='$value'";
            }
        }
    }

    if (empty($updateFields)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "No fields to update."
        ]);
        exit;
    }

    $updateSql = "UPDATE `coupons`
                  SET " . implode(", ", $updateFields) . "
                  WHERE `id`='$couponId'";
    $updateResult = mysqli_query($conn, $updateSql);

    if ($updateResult) {
        header("HTTP/1.0 200 OK");
        echo json_encode([
            "status" => 200,
            "message" => "Coupon updated successfully."
        ]);

    } else {

        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Failed to update coupon.",
            "error" => mysqli_error($conn)
        ]);
    }

} else {

    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        "status" => 405,
        "message" => $requestMethod . " Method Not Allowed"
    ]);
}