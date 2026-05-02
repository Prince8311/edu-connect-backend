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

    $isForm = isset($_GET['isForm']) && $_GET['isForm'] === 'true';

    if ($userType === 'super_admin') {
        $sql = "SELECT rp.*, COUNT(au.id) AS user_count FROM roles_permissions rp LEFT JOIN admin_users au ON au.user_type = rp.created_by AND au.user_role = rp.role_name WHERE rp.created_by = '$userType' GROUP BY rp.id";
    } else if ($userType === 'inst_admin') {
        $sql = "SELECT rp.*, COUNT(au.id) AS user_count FROM roles_permissions rp LEFT JOIN admin_users au ON au.user_type = rp.created_by AND au.user_role = rp.role_name WHERE rp.created_by = '$userType' AND rp.inst_id = '$instituteId' GROUP BY rp.id";
    } else {
        header("HTTP/1.0 403 Forbidden");
        echo json_encode([
            'status' => 403,
            'message' => 'Unauthorized access'
        ]);
        exit;
    }

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }
    $rolesPermissions = [];
    if ($isForm) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rolesPermissions[] = $row['role_name'];
        }
        $rolesPermissions = array_values(array_unique($rolesPermissions));
        $data = [
            'status' => 200,
            'message' => 'Roles fetched for form.',
            'data' => $rolesPermissions
        ];
    } else {
        while ($row = mysqli_fetch_assoc($result)) {
            $rolesPermissions[] = [
                'id' => $row['id'],
                'role_name' => $row['role_name'],
                'user_count' => (int)$row['user_count']
            ];
        }
        $data = [
            'status' => 200,
            'message' => 'Roles and permissions retrieved successfully',
            'roles' => $rolesPermissions
        ];
    }
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
