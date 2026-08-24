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
    if (empty($instituteId)) {
        $data = ['status' => 422, 'message' => 'Institute ID is missing from authentication'];
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

    $inputData = json_decode($_POST['inputs'], true);
    if (!is_array($inputData)) {
        $data = [
            'status' => 400,
            'message' => 'Invalid inputs payload'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $accountId = mysqli_real_escape_string($conn, $inputData['accountId'] ?? '');
    $classSections = mysqli_real_escape_string($conn, $inputData['classSections'] ?? '');
    $feeType = mysqli_real_escape_string($conn, $inputData['feeType'] ?? '');
    $id = (int)($inputData['id'] ?? 0);

    if ($accountId === '' || $classSections === '' || $feeType === '') {
        $data = [
            'status' => 400,
            'message' => 'Account, class, section, and fee type are required.'
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
        $sql = "INSERT INTO `split_bank_accounts` (`inst_id`, `account_id`, `class_section`, `fee_type`) VALUES ('$instIdEsc', '$accountId', '$classSections', '$feeType')";
    } else {
        $sql = "UPDATE `split_bank_accounts` SET `account_id`='$accountId', `class_section`='$classSections', `fee_type`='$feeType' WHERE `id`='$id' AND `inst_id`='$instIdEsc'";
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
        $checkSql = "SELECT `id` FROM `split_bank_accounts` WHERE `id`='$id' AND `inst_id`='$instIdEsc' LIMIT 1";
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
                'message' => 'Split bank account not found.'
            ];
            header("HTTP/1.0 404 Not Found");
            echo json_encode($data);
            exit;
        }
    }

    $data = [
        'status' => 200,
        'message' => $intent === 'add'
            ? 'Split bank account created successfully.'
            : 'Split bank account updated successfully.'
    ];
    header("HTTP/1.0 200 OK");
    echo json_encode($data);
    exit;
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
