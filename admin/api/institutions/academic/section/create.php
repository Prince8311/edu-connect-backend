<?php

require "../../../../../utils/headers.php";
require "../../../../../utils/middleware.php";

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
    require "../../../../../_db-connect.php";
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

    $academicLevelId = mysqli_real_escape_string($conn, $inputData['academicLevelId']);
    $class = mysqli_real_escape_string($conn, $inputData['class']);

    $checkSql = "SELECT * FROM `academic_class_sections` WHERE `inst_id`='$instituteId' AND `class`='$class' AND `level_id`='$academicLevelId' LIMIT 1";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) > 0) {
        $row = mysqli_fetch_assoc($checkResult);
        $sections = $row['sections'];

        $sectionsArray = explode(",", $sections);
        $lastSection = end($sectionsArray);
        $nextSection = chr(ord($lastSection) + 1);
        $sectionsArray[] = $nextSection;
        $updatedSections = implode(",", $sectionsArray);

        $updateSql = "UPDATE `academic_class_sections` SET `sections`='$updatedSections' WHERE `id`='{$row['id']}'";
        $updateResult = mysqli_query($conn, $updateSql);
        if ($updateResult) {
            $data = [
                'status' => 200,
                'message' => 'Section added successfully.'
            ];
            header("HTTP/1.0 200 OK");
            echo json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Failed to update section.'
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
        }
    } else {
        $insertSql = "INSERT INTO `academic_class_sections`(`inst_id`,`level_id`,`class`,`sections`) VALUES ('$instituteId','$academicLevelId','$class','A')";
        $insertResult = mysqli_query($conn, $insertSql);
        if ($insertResult) {
            $data = [
                'status' => 200,
                'message' => 'Section added successfully.'
            ];
            header("HTTP/1.0 200 OK");
            echo json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Failed to update section.'
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
