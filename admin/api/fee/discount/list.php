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
    $sql = "SELECT `id`, `name`, `amount`, `fee_type` FROM `discounts` WHERE `inst_id`='$instIdEsc' ORDER BY `id` DESC";
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
            'amount' => $row['amount'],
            'fee_type' => $row['fee_type']
        ];
    }

    $data = [
        'status' => 200,
        'message' => 'Discount list retrieved successfully',
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
