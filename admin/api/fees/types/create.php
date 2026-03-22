<?php

require "../../../../utils/headers.php";
require "../../../../utils/middleware.php";

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
    require "../../../../_db-connect.php";
    global $conn;
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

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

    $types = mysqli_real_escape_string($conn, $inputData['types']);
    $adminSql = "SELECT i.inst_id FROM admin_users a JOIN institutions i ON a.id = i.admin_id WHERE a.id = '$userId' LIMIT 1";
    $adminResult = mysqli_query($conn, $adminSql);

    if (!$adminResult || mysqli_num_rows($adminResult) === 0) {
        echo json_encode([
            "status" => 401,
            "message" => "Invalid token or institute not found"
        ]);
        exit;
    }

    $adminData = mysqli_fetch_assoc($adminResult);
    $instituteId = $adminData['inst_id'];

    $checkSql = "SELECT * FROM `fees_types` WHERE `inst_id`='$instituteId'";
    $checkResult = mysqli_query($conn, $checkSql);

    if (!$checkResult) {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error.'
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    if (mysqli_num_rows($checkResult) === 1) {
        $updateSql = "UPDATE `fees_types` SET `types`='$types' WHERE `inst_id`='$instituteId'";
        $updateResult = mysqli_query($conn, $updateSql);

        if ($updateResult) {
            $data = [
                'status' => 200,
                'message' => 'Fees types updated successfully.'
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
        $insertSql = "INSERT INTO `fees_types`(`inst_id`, `types`) VALUES ('$instituteId','$types')";
        $insertResult = mysqli_query($conn, $insertSql);

        if ($insertResult) {
            $data = [
                'status' => 200,
                'message' => 'Fees types created successfully.'
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
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
