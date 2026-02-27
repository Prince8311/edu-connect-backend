<?php

require "../../../utils/headers.php";
require "../../../utils/middleware.php";

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

if ($requestMethod === 'GET') {
    require "../../../_db-connect.php";
    global $conn;
    $authToken = mysqli_real_escape_string($conn, $authResult['token']);

    if (!isset($_GET['sectionId'])) {
        $data = [
            'status' => 400,
            'message' => 'Section id required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }

    $sectionId = mysqli_real_escape_string($conn, $_GET['sectionId']);
    $adminSql = "SELECT i.inst_id FROM admin_users a JOIN institutions i ON a.id = i.admin_id WHERE a.auth_token = '$authToken' LIMIT 1";
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

    $checkSectionSql = "SELECT * FROM `student_form_sections` WHERE `id`='$sectionId' AND `inst_id`='$instituteId'";
    $checkSectionResult = mysqli_query($conn, $checkSectionSql);

    if ($checkSectionResult && mysqli_num_rows($checkSectionResult) === 1) {
        $fieldSql = "SELECT `id`, `form_field`, `field_type`, `is_required` FROM `student_form_fields` WHERE `inst_id`='$instituteId' AND `section_id`='$sectionId'";
        $fieldResult = mysqli_query($conn, $fieldSql);

        if ($fieldResult) {
            $fields = mysqli_fetch_all($fieldResult, MYSQLI_ASSOC);
            foreach ($fields as &$field) {
                $field['is_required'] = (bool) $field['is_required'];
            }
            $data = [
                'status' => 200,
                'message' => 'Form fields fetched.',
                'fields' => $fields
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
            'status' => 201,
            'message' => 'Section is not available.',
        ];
        header("HTTP/1.0 201 Not available");
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
