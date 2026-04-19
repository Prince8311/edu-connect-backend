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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $userType = $authResult['user_type'];
    $instituteId = $authResult['inst_id'];
    $roleName = isset($_GET['roleName']) ? mysqli_real_escape_string($conn, $_GET['roleName']) : '';

    if (empty($roleName)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'status' => 400,
            'message' => 'Role name is required.'
        ]);
        exit;
    }

    if ($userType === 'super_admin') {
        $sql = "SELECT * FROM `roles_permissions` WHERE `created_by`='$userType' AND `role_name`= '$roleName'";
    } else if ($userType === 'inst_admin') {
        $sql = "SELECT * FROM `roles_permissions` WHERE `created_by`='$userType' AND `inst_id`='$instituteId' AND `role_name`='$roleName'";
    } else {
        header("HTTP/1.0 403 Forbidden");
        echo json_encode([
            'status' => 403,
            'message' => 'Unauthorized access'
        ]);
        exit;
    }

    $result = mysqli_query($conn, $sql);
    if ($result) {
        if (mysqli_num_rows($result) == 0) {
            header("HTTP/1.0 404 Not Found");
            echo json_encode([
                'status' => 404,
                'message' => 'Role not found'
            ]);
            exit;
        }
        $row = mysqli_fetch_assoc($result);
        $roleDetails = [
            'id' => $row['id'],
            'role_name' => $row['role_name'],
            'permissions' => json_decode($row['permissions'], true),
            'status' => (bool)$row['status']
        ];
        $data = [
            'status' => 200,
            'message' => 'Role details retrieved successfully',
            'details' => $roleDetails
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch role permissions'
        ]);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
