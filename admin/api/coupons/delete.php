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

    $deleteSql = "DELETE FROM `coupons` WHERE `id`='$couponId'";
    $deleteResult = mysqli_query($conn, $deleteSql);

    if ($deleteResult) {
        header("HTTP/1.0 200 OK");
        echo json_encode([
            "status" => 200,
            "message" => "Coupon deleted successfully."
        ]);
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Failed to delete coupon."
        ]);
    }
} else {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        "status" => 405,
        "message" => $requestMethod . " Method Not Allowed"
    ]);
}
