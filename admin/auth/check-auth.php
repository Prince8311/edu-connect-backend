<?php

require "../../utils/headers.php";
require "../../utils/middleware.php";

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
    require "../../_db-connect.php";
    global $conn;
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    $sql = "SELECT a.id, a.name, a.image, a.email, a.phone, a.status, a.user_type, a.user_role, i.inst_id, i.location FROM admin_users a JOIN institutions i ON i.admin_id = a.id WHERE a.id = '$userId' LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if (mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        $session = null;

        if ($user['user_type'] === 'inst_admin') {
            $instId = $user['inst_id'];

            $sessionSql = "SELECT `sesssion_name`, `start_date`, `end_date` FROM `academic_sessions` WHERE `inst_id` = '$instId' AND `status` = 'Ongoing' LIMIT 1";
            $sessionResult = mysqli_query($conn, $sessionSql);

            if ($sessionResult && mysqli_num_rows($sessionResult) > 0) {
                $sessionRow = mysqli_fetch_assoc($sessionResult);

                $session = [
                    "name" => $sessionRow['sesssion_name'],
                    "start" => $sessionRow['start_date'],
                    "end" => $sessionRow['end_date']
                ];
            }
        }

        $user['session'] = $session;
        $data = [
            'status' => 200,
            'message' => 'Authenticated',
            'user' => $user
        ];

        header("HTTP/1.0 200 Authenticated");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 400,
            'message' => 'No Authentication'
        ];
        header("HTTP/1.0 400 No Authentication");
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
