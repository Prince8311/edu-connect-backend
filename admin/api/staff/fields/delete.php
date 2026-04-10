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

    $sectionId = mysqli_real_escape_string($conn, $inputData['sectionId']);
    $fieldId = mysqli_real_escape_string($conn, $inputData['fieldId']);

    $checkSql = "SELECT * FROM `staff_form_fields` WHERE `id`='$fieldId' AND `inst_id`='$instituteId' AND `section_id`='$sectionId'";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) === 0) {
        $data = [
            'status' => 400,
            'message' => "This field doesn't exists.",
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
