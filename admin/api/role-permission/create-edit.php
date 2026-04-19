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

    $roleName = mysqli_real_escape_string($conn, $inputData['role_name']);
    $permissions = mysqli_real_escape_string($conn, $inputData['permissions']);
    $status = (isset($inputData['status']) && $inputData['status'] === true) ? 1 : 0;

    if ($userType === 'super_admin') {
        $checkSql = "SELECT * FROM `roles_permissions` WHERE `role_name`='$roleName' AND `created_by`='$userType'";
        $checkResult = mysqli_query($conn, $checkSql);
        
        if (mysqli_num_rows($checkResult) > 0) {
            $updateSql = "UPDATE `roles_permissions` SET `permissions`='$permissions', `status`='$status' WHERE `role_name`='$roleName' AND `created_by`='$userType'";
            if (mysqli_query($conn, $updateSql)) {
                $data = [
                    'status' => 200,
                    'message' => 'Role updated successfully.'
                ];
                header("HTTP/1.0 200 Updated");
                echo json_encode($data);
            } else {
                $data = [
                    'status' => 500,
                    'message' => 'Failed to update role permissions'
                ];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($data);
            }
        } else {
            $insertSql = "INSERT INTO `roles_permissions` (`role_name`, `permissions`, `status`, `created_by`) VALUES ('$roleName', '$permissions', '$status', '$userType')";
            if (mysqli_query($conn, $insertSql)) {
                $data = [
                    'status' => 200,
                    'message' => 'Role created successfully.'
                ];
                header("HTTP/1.0 200 Created");
                echo json_encode($data);
            } else {
                $data = [
                    'status' => 500,
                    'message' => 'Failed to create role permissions'
                ];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($data);
            }
        }
    } else if ($userType === 'inst_admin') {
        $checkSql = "SELECT * FROM `roles_permissions` WHERE `role_name`='$roleName' AND `created_by`='$userType' AND `inst_id`='$instituteId'";
        $checkResult = mysqli_query($conn, $checkSql);
        
        if (mysqli_num_rows($checkResult) > 0) {
            $updateSql = "UPDATE `roles_permissions` SET `permissions`='$permissions', `status`='$status' WHERE `role_name`='$roleName' AND `created_by`='$userType' AND `inst_id`='$instituteId'";
            if (mysqli_query($conn, $updateSql)) {
                $data = [
                    'status' => 200,
                    'message' => 'Role updated successfully.'
                ];
                header("HTTP/1.0 200 Updated");
                echo json_encode($data);
            } else {
                $data = [
                    'status' => 500,
                    'message' => 'Failed to update role permissions'
                ];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($data);
            }
        } else {
            $insertSql = "INSERT INTO `roles_permissions` (`role_name`, `permissions`, `status`, `created_by`, `inst_id`) VALUES ('$roleName', '$permissions', '$status', '$userType', '$instituteId')";
            if (mysqli_query($conn, $insertSql)) {
                $data = [
                    'status' => 200,
                    'message' => 'Role created successfully.'
                ];
                header("HTTP/1.0 200 Created");
                echo json_encode($data);
            } else {
                $data = [
                    'status' => 500,
                    'message' => 'Failed to create role permissions'
                ];
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode($data);
            }
        }
    } else {
        $data = [
            'status' => 403,
            'message' => 'Forbidden: You do not have permission to perform this action.'
        ];
        header("HTTP/1.0 403 Forbidden");
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
