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

    $sectionId = mysqli_real_escape_string($conn, $inputData['sectionId']);
    $fieldName = mysqli_real_escape_string($conn, $inputData['fieldName']);
    $fieldType = mysqli_real_escape_string($conn, $inputData['fieldType']);
    $isRequired = (isset($inputData['isRequired']) && $inputData['isRequired'] === true) ? 1 : 0;
    $items = NULL;

    if (($fieldType === 'dropdown' || $fieldType === 'multi-select-dropdown') && isset($inputData['items'])) {
        $items = mysqli_real_escape_string($conn, $inputData['items']);
    }

    if (($fieldType === 'dropdown' || $fieldType === 'multi-select-dropdown') && empty($items)) {
        echo json_encode([
            "status" => 400,
            "message" => "Items are required for this field type"
        ]);
        exit;
    }

    $checkSql = "SELECT * FROM `staff_form_fields` WHERE `inst_id`='$instituteId' AND `section_id`='$sectionId' AND `form_field`='$fieldName'";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) === 1) {
        $data = [
            'status' => 400,
            'message' => 'This field already created.'
        ];
        header("HTTP/1.0 400 Already exists");
        echo json_encode($data);
        exit;
    }

    $orderSql = "SELECT MAX(sort_order) as max_order FROM staff_form_fields WHERE inst_id='$instituteId' AND section_id='$sectionId'";
    $orderResult = mysqli_query($conn, $orderSql);
    $orderRow = mysqli_fetch_assoc($orderResult);

    $nextOrder = ($orderRow['max_order'] !== NULL) ? ((int)$orderRow['max_order'] + 1) : 1;

    $insertSql = "INSERT INTO `staff_form_fields`(`inst_id`, `section_id`, `form_field`, `field_type`, `is_required`, `items`, `sort_order`) VALUES ('$instituteId','$sectionId','$fieldName','$fieldType','$isRequired'," . ($items !== NULL ? "'$items'" : "NULL") . ",'$nextOrder')";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult) {
        $data = [
            'status' => 200,
            'message' => 'Field created successfully.'
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
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}