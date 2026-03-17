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

    if (!isset($_GET['sectionId'])) {
        $data = [
            'status' => 400,
            'message' => 'Section id required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }

    $sectionId = mysqli_real_escape_string($conn, $_GET['sectionId']);
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

    $sectionId = mysqli_real_escape_string($conn, $inputData['sectionId']);
    $fieldId = mysqli_real_escape_string($conn, $inputData['fieldId']);

    $checkSql = "SELECT * FROM `staff_form_fields` WHERE `id`='$fieldId' AND `inst_id`='$instituteId' AND `section_id`='$sectionId'";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) === 0) {
        $data = [
            'status' => 400,
            'message' => "This field doesn't exists."
        ];
        header("HTTP/1.0 400 Bad request");
        echo json_encode($data);
        exit;
    }

    $deleteSql = "DELETE FROM `staff_form_fields` WHERE `id`='$fieldId' AND `inst_id`='$instituteId' AND `section_id`='$sectionId'";
    $deleteResult = mysqli_query($conn, $deleteSql);

    if ($deleteResult) {
        $data = [
            'status' => 200,
            'message' => "Field removed successfully."
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

    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ]);
}
