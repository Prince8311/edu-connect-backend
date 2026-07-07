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

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;

    $inputData = json_decode(file_get_contents('php://input'), true);

    if (empty($inputData)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "Empty request data."
        ]);
        exit;
    }

    // ----------------------------
    // Get & sanitize input
    // ----------------------------
    $code = mysqli_real_escape_string($conn, trim($inputData['code'] ?? ''));
    $type = mysqli_real_escape_string($conn, trim($inputData['type'] ?? ''));

    $instituteId = $inputData['inst_id'] ?? null;

    $billAmountRange = mysqli_real_escape_string(
        $conn,
        trim($inputData['bill_amount_range'] ?? '')
    );

    $targetBillAmount = $inputData['target_bill_amount'] ?? null;

    $offerType = mysqli_real_escape_string(
        $conn,
        trim($inputData['offer_type'] ?? '')
    );

    $offerValue = trim($inputData['offer_value'] ?? '');

    $offerUnit = mysqli_real_escape_string(
        $conn,
        trim($inputData['offer_unit'] ?? '')
    );

    $offerLimit = $inputData['offer_limit'] ?? null;

    $validityType = mysqli_real_escape_string(
        $conn,
        trim($inputData['validity_type'] ?? '')
    );

    $countType = trim($inputData['count_type'] ?? '');
    $countValue = $inputData['count_value'] ?? null;

    $validityDate = trim($inputData['validity_date'] ?? '');

    $status = !empty($inputData['status']) ? 1 : 0;

    // ----------------------------
    // Validation
    // ----------------------------
    if (empty($code) || empty($type) || empty($offerType) || empty($offerUnit) || empty($validityType)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "Required fields are missing."
        ]);
        exit;
    }

    // ----------------------------
    // Check duplicate coupon code
    // ----------------------------
    $checkSql = "SELECT id FROM coupons WHERE code='$code'";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) > 0) {
        header("HTTP/1.0 409 Conflict");
        echo json_encode([
            "status" => 409,
            "message" => "Coupon code already exists."
        ]);
        exit;
    }

    // ----------------------------
    // Convert nullable values
    // ----------------------------
    $instIdValue = ($instituteId === null || $instituteId === '')
        ? "NULL"
        : "'" . mysqli_real_escape_string($conn, $instituteId) . "'";

    $targetBillAmountValue = ($targetBillAmount === null || $targetBillAmount === '')
        ? "NULL"
        : "'" . mysqli_real_escape_string($conn, $targetBillAmount) . "'";

    $offerLimitValue = ($offerLimit === null || $offerLimit === '')
        ? "NULL"
        : "'" . mysqli_real_escape_string($conn, $offerLimit) . "'";

    $countTypeValue = ($countType === '')
        ? "NULL"
        : "'" . mysqli_real_escape_string($conn, $countType) . "'";

    $countValueValue = ($countValue === null || $countValue === '')
        ? "NULL"
        : "'" . mysqli_real_escape_string($conn, $countValue) . "'";

    $validityDateValue = ($validityDate === '')
        ? "NULL"
        : "'" . mysqli_real_escape_string($conn, $validityDate) . "'";

    // ----------------------------
    // Insert Coupon
    // ----------------------------
    $insertSql = "INSERT INTO coupons (code, type, inst_id, bill_amount_range,
            target_bill_amount, offer_type, offer_value, offer_unit, offer_limit, validity_type, count_type, count_value, validity_date, status) VALUES ('$code','$type',$instIdValue,'$billAmountRange',$targetBillAmountValue,'$offerType','$offerValue','$offerUnit',$offerLimitValue,'$validityType',$countTypeValue,$countValueValue,$validityDateValue,$status)";
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
            "message" => "Database error: " . mysqli_error($conn),
        ]);
    }
} else {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        "status" => 405,
        "message" => $requestMethod . " Method Not Allowed"
    ]);
}
