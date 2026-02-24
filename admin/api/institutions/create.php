<?php

require "../../../utils/headers.php";
require "../../../utils/middleware.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
    require "../../../_db-connect.php";
    global $conn;

    require "../../../PHPMailer/Exception.php";
    require "../../../PHPMailer/PHPMailer.php";
    require "../../../PHPMailer/SMTP.php";

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

    $institutionName = mysqli_real_escape_string($conn, $inputData['institutionName']);
    $phone = mysqli_real_escape_string($conn, $inputData['phone']);
    $email = mysqli_real_escape_string($conn, $inputData['email']);

    $data = [
        'status' => 200,
        'message' => 'Institution added successfully.'
    ];
    header("HTTP/1.0 200 OK");
    echo json_encode($data);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
