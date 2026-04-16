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

    if (isset($_POST['inputs']) && isset($_FILES['image'])) {
        $inputData = json_decode($_POST['inputs'], true);
        $name = mysqli_real_escape_string($conn, $inputData['name']);
        $role = mysqli_real_escape_string($conn, $inputData['role']);
        $phone = mysqli_real_escape_string($conn, $inputData['phone']);
        $email = mysqli_real_escape_string($conn, $inputData['email']);
    } else {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
