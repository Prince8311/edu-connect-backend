<?php

require "../../utils/headers.php";
require "../../utils/middleware.php";

$authResult = adminAuthenticateRequest();

if (!$authResult['authenticated']) {
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    header("HTTP/1.0 " . $authResult['status']);
    exit;
}

if ($requestMethod === 'GET') {
    require "../../_db-connect.php";
    global $conn;
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    $userSql = "SELECT `id`, `name`, `image`, `email`, `phone`, `status`, `user_type`, `user_role` FROM `admin_users` WHERE `id` = '$userId' LIMIT 1";
    $userResult = mysqli_query($conn, $userSql);

    if ($userResult && mysqli_num_rows($userResult) > 0) {
        $user = mysqli_fetch_assoc($userResult);
        $user['inst_id'] = null;
        $user['location'] = null;
        $user['session'] = null;

        if ($user['user_type'] === 'inst_admin') {
            $instSql = "SELECT `inst_id`, `location` FROM `institutions` WHERE `admin_id` = '{$user['id']}' LIMIT 1";
            $instResult = mysqli_query($conn, $instSql);

            if ($instResult && mysqli_num_rows($instResult) > 0) {
                $inst = mysqli_fetch_assoc($instResult);

                $user['inst_id'] = $inst['inst_id'];
                $user['location'] = $inst['location'];
                $instId = $inst['inst_id'];

                $sessionSql = "SELECT `sesssion_name`, `start_date`, `end_date` FROM `academic_sessions` WHERE `inst_id` = '$instId' AND `status` = 'Ongoing' LIMIT 1";
                $sessionResult = mysqli_query($conn, $sessionSql);

                if ($sessionResult && mysqli_num_rows($sessionResult) > 0) {
                    $sessionRow = mysqli_fetch_assoc($sessionResult);
                    $user['session'] = [
                        "name" => $sessionRow['sesssion_name'],
                        "start" => $sessionRow['start_date'],
                        "end" => $sessionRow['end_date']
                    ];
                }
            }
        }

        echo json_encode([
            'status' => 200,
            'message' => 'Authenticated',
            'user' => $user
        ]);
        header("HTTP/1.0 200 Authenticated");
    } else {
        echo json_encode([
            'status' => 400,
            'message' => 'No Authentication'
        ]);
        header("HTTP/1.0 400 No Authentication");
    }
} else {
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ]);
    header("HTTP/1.0 405 Method Not Allowed");
}
