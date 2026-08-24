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

if ($requestMethod === 'POST') {
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

    $intent = strtolower(trim($_GET['intent'] ?? 'add'));
    if (!in_array($intent, ['add', 'update'], true)) {
        $data = [
            'status' => 400,
            'message' => 'Invalid intent. Allowed values: add, update.'
        ];

        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $inputData = json_decode(file_get_contents("php://input"), true);
    if (empty($inputData)) {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $name = mysqli_real_escape_string($conn, $inputData['name'] ?? '');
    $amount = mysqli_real_escape_string($conn, $inputData['amount'] ?? '');
    $feeType = mysqli_real_escape_string($conn, $inputData['feeType'] ?? '');
    $id = (int)($inputData['id'] ?? 0);

    if ($name === '' || $amount === '' || $feeType === '') {
        $data = [
            'status' => 400,
            'message' => 'Name, amount, and fee type are required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if (!is_numeric($inputData['amount'])) {
        $data = [
            'status' => 400,
            'message' => 'Amount must be numeric.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if ($intent === 'update' && $id <= 0) {
        $data = [
            'status' => 400,
            'message' => 'id is required for update.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $instIdEsc = mysqli_real_escape_string($conn, (string)$instituteId);

    if ($intent === 'add') {
        $sql = "INSERT INTO `discounts` (`inst_id`, `name`, `amount`, `fee_type`) VALUES ('$instIdEsc', '$name', '$amount', '$feeType')";
    } else {
        $sql = "UPDATE `discounts` SET `name`='$name', `amount`='$amount', `fee_type`='$feeType' WHERE `id`='$id' AND `inst_id`='$instIdEsc'";
    }

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    if ($intent === 'update' && mysqli_affected_rows($conn) === 0) {
        $checkSql = "SELECT `id` FROM `discounts` WHERE `id`='$id' AND `inst_id`='$instIdEsc' LIMIT 1";
        $checkResult = mysqli_query($conn, $checkSql);

        if ($checkResult === false) {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        if (mysqli_num_rows($checkResult) === 0) {
            $data = [
                'status' => 404,
                'message' => 'Discount not found.'
            ];
            header("HTTP/1.0 404 Not Found");
            echo json_encode($data);
            exit;
        }
    }

    $data = [
        'status' => 200,
        'message' => $intent === 'add'
            ? 'Discount created successfully.'
            : 'Discount updated successfully.'
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
