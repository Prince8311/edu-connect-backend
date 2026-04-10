<?php

require "../../utils/headers.php";
require "../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    $data = [
        'status' => $authResult['status'],
        'message' => $authResult['message'],
    ];
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode($data);
    exit;
}

if ($requestMethod === 'GET') {
    require "../../_db-connect.php";
    global $conn;
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    $userSql = "SELECT `id`, `name`, `inst_id`, `image`, `email`, `phone`, `status`, `user_type`, `user_role` FROM `admin_users` WHERE `id` = '$userId' LIMIT 1";
    $userResult = mysqli_query($conn, $userSql);

    if ($userResult && mysqli_num_rows($userResult) > 0) {
        $userRow = mysqli_fetch_assoc($userResult);
        $allowedRoles = ['inst_admin', 'super_admin'];
        if (!in_array($userRow['user_type'], $allowedRoles)) {
            $data = [
                'status' => 403,
                'message' => "You don't have the permission"
            ];
            header("HTTP/1.0 403 Forbidden");
            echo json_encode($data);
            exit;
        }

        $$user = [
            "id" => $userRow['id'],
            "name" => $userRow['name'],
            "image" => $userRow['image'],
            "email" => $userRow['email'],
            "phone" => $userRow['phone'],
            "is_active" => (bool)$userRow['status'],
            "user_type" => $userRow['user_type'],
            "user_role" => $userRow['user_role']
        ];

        if ($userRow['user_type'] === 'inst_admin') {
            $instId = $userRow['inst_id'];
            $user['institution'] = null;

            $instSql = "SELECT `inst_id`, `inst_name`, `image`, `receipt_prefix`, `status`, `deactive_date`, `location` FROM `institutions` WHERE `inst_id` = '$instId' LIMIT 1";
            $instResult = mysqli_query($conn, $instSql);

            if ($instResult && mysqli_num_rows($instResult) > 0) {
                $instRow = mysqli_fetch_assoc($instResult);

                $institution = [
                    "inst_id" => $instRow['inst_id'],
                    "inst_name" => $instRow['inst_name'],
                    "image" => $instRow['image'],
                    "receipt_prefix" => $instRow['receipt_prefix'],
                    "is_active" => (bool)$instRow['status'],
                    "deactive_date" => $instRow['deactive_date'] ?? null,
                    "location" => $instRow['location'],
                    "ongoingSession" => null
                ];

                $sessionSql = "SELECT `sesssion_name`, `start_date`, `end_date` FROM `academic_sessions` WHERE `inst_id` = '$instId' AND `status` = 'Ongoing' LIMIT 1";
                $sessionResult = mysqli_query($conn, $sessionSql);

                if ($sessionResult && mysqli_num_rows($sessionResult) > 0) {
                    $sessionRow = mysqli_fetch_assoc($sessionResult);

                    $institution['ongoingSession'] = [
                        "name" => $sessionRow['sesssion_name'],
                        "start" => $sessionRow['start_date'],
                        "end" => $sessionRow['end_date']
                    ];
                }

                $user['institution'] = $institution;
            }
        }

        $data = [
            'status' => 200,
            'message' => 'Authenticated',
            'user' => $user
        ];
        header("HTTP/1.0 200 Authenticated");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 404,
            'message' => 'User not found'
        ];
        header("HTTP/1.0 404 Not Found");
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
