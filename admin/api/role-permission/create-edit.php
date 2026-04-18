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
    $userType = $authResult['user_type'];
    $instituteId = $authResult['inst_id'];
    $instIdValue = is_null($instituteId) ? "NULL" : "'" . mysqli_real_escape_string($conn, $instituteId) . "'";

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

    $roleName = mysqli_real_escape_string($conn, $inputData['role_name']);
    $permissions = mysqli_real_escape_string($conn, $inputData['permissions']);
    $status = (isset($inputData['status']) && $inputData['status'] === true) ? 1 : 0;
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
