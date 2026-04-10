<?php

require "../../../utils/headers.php";
require "../../../utils/middleware.php";

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
    $isState = filter_var($inputData['isState'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $successMessage = '';

    if ($isState) {
        $sql = "DELETE FROM `state_cities` WHERE `state`='$name'";
        $successMessage = 'State deleted successfully.';
    } else {
        $sql = "DELETE FROM `state_cities` WHERE `city`='$name'";
        $successMessage = 'City deleted successfully.';
    }

    $result = mysqli_query($conn, $sql);

    if ($result) {
        $data = [
            'status' => 200,
            'message' => $successMessage
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
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
