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
    $roleId = isset($_GET['id']) ? mysqli_real_escape_string($conn, $_GET['id']) : '';

    if (empty($roleName)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'status' => 400,
            'message' => 'Role id is required.'
        ]);
        exit;
    }

    $deleteSql = "DELETE FROM `roles_permissions` WHERE `id`='$roleId' AND `created_by`='$userType'";
    if ($userType === 'inst_admin') {
        $deleteSql .= " AND `inst_id`='$instituteId'";
    }
    $deleteResult = mysqli_query($conn, $deleteSql);
    if ($deleteResult) {
        $data = [
            'status' => 200,
            'message' => 'Role deleted successfully'
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 500,
            'message' => 'Failed to delete role'
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
