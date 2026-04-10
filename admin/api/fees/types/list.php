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

if ($requestMethod === 'GET') {
    require "../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $sql = "SELECT `types` FROM `fees_types` WHERE `inst_id`='$instituteId'";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $res = mysqli_fetch_assoc($result);
        $types = [];
        if (!empty($res['types'])) {
            $decodedItems = json_decode($res['types'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $types = $decodedItems;
            } else {
                $types = null;
            }
        } else {
            $types = null;
        }
        $data = [
            'status' => 200,
            'message' => 'Fees types.',
            'types' => $types
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
