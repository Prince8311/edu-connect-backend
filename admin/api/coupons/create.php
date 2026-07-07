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

    $inputData = json_decode(file_get_contents('php://input'), true);

    if (empty($inputData)) {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $code               = trim($inputData['code'] ?? '');
    $type               = $inputData['type'] ?? '';
    $instituteId        = $inputData['inst_id'] ?? null;
    $billAmountRange    = $inputData['bill_amount_range'] ?? '';
    $targetBillAmount   = $inputData['target_bill_amount'] ?? null;
    $offerType          = $inputData['offer_type'] ?? '';
    $offerValue         = $inputData['offer_value'] ?? null;
    $offerUnit          = $inputData['offer_unit'] ?? '';
    $offerLimit         = $inputData['offer_limit'] ?? null;
    $validityType       = $inputData['validity_type'] ?? '';
    $countType          = $inputData['count_type'] ?? '';
    $countValue         = $inputData['count_value'] ?? null;
    $validityDate       = $inputData['validity_date'] ?? null;
    $status             = $inputData['status'] ?? 0;

    if (empty($code) || empty($type) || empty($offerType) || empty($offerUnit) || empty($validityType) || empty($countType)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "Required fields are missing."
        ]);
        exit;
    }

    $checkSql = "SELECT * FROM `coupons` WHERE `code`='$code'";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) > 0) {
        header("HTTP/1.0 409 Conflict");
        echo json_encode([
            "status" => 409,
            "message" => "Coupon code already exists."
        ]);
        exit;
    }

    $insertSql = "INSERT INTO `coupons`( `code`, `type`, `inst_id`, `bill_amount_range`, `target_bill_amount`, `offer_type`, `offer_value`, `offer_unit`, `offer_limit`, `validity_type`, `count_type`, `count_value`, `validity_date`, `status`) VALUES ('$code', '$type', $instituteId, $billAmountRange, $targetBillAmount, $offerType, $offerValue, $offerUnit, $offerLimit, $validityType, $countType, $countValue, $validityDate, $status)";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult) {
        header("HTTP/1.0 200 OK");
        echo json_encode([
            "status" => 200,
            "message" => "Coupon created successfully."
        ]);
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Failed to create coupon."
        ]);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
