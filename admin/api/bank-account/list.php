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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = mysqli_real_escape_string($conn, (string)($authResult['inst_id'] ?? ''));

    if ($instituteId === '') {
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode([
            'status' => 422,
            'message' => 'Institute ID is missing from authentication.'
        ]);
        exit;
    }

    $isFormParam = strtolower(trim((string)($_GET['isForm'] ?? 'false')));
    $isForm = in_array($isFormParam, ['true', '1', 'yes'], true);
    $search = trim((string)($_GET['search'] ?? ''));
    $searchCondition = '';

    if ($search !== '') {
        $searchEsc = mysqli_real_escape_string($conn, $search);

        if ($isForm) {
            $searchCondition = " AND (`account_name` LIKE '%$searchEsc%' OR `account_no` LIKE '%$searchEsc%')";
        } else {
            $searchCondition = " AND (`account_name` LIKE '%$searchEsc%' OR `account_no` LIKE '%$searchEsc%' OR `beneficiary_name` LIKE '%$searchEsc%')";
        }
    }

    if ($isForm) {
        $sql = "SELECT `id`, `account_name`, `account_no`, `ifsc_code` FROM `institution_bank_accounts` WHERE `inst_id` = '$instituteId' AND `status` = 1 $searchCondition ORDER BY `id` DESC";
        $result = mysqli_query($conn, $sql);

        if (!$result) {
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode([
                'status' => 500,
                'message' => mysqli_error($conn)
            ]);
            exit;
        }

        $accounts = [];

        while ($row = mysqli_fetch_assoc($result)) {
            $accounts[] = $row;
        }

        header("HTTP/1.0 200 OK");
        echo json_encode([
            'status' => 200,
            'message' => 'Bank accounts fetched successfully.',
            'data' => $accounts
        ]);
        exit;
    }

    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && (int)$_GET['limit'] > 0 ? min(100, (int)$_GET['limit']) : 10;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $limit;

    $countSql = "SELECT COUNT(*) AS total FROM `institution_bank_accounts` WHERE `inst_id` = '$instituteId' $searchCondition";
    $countResult = mysqli_query($conn, $countSql);

    if (!$countResult) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => mysqli_error($conn)
        ]);
        exit;
    }

    $countRow = mysqli_fetch_assoc($countResult);
    $totalCount = (int)($countRow['total'] ?? 0);

    $sql = "SELECT `id`, `inst_id`, `account_name`, `account_no`, `beneficiary_name`, `ifsc_code`, `cancelled_cheque`, `status` FROM `institution_bank_accounts` WHERE `inst_id` = '$instituteId' $searchCondition ORDER BY `id` DESC LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => mysqli_error($conn)
        ]);
        exit;
    }

    $accounts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['status'] = (bool)((int)$row['status']);
        $accounts[] = $row;
    }

    header("HTTP/1.0 200 OK");
    echo json_encode([
        'status' => 200,
        'message' => 'Bank accounts fetched successfully.',
        'totalCount' => $totalCount,
        'currentPage' => $page,
        'data' => $accounts
    ]);
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