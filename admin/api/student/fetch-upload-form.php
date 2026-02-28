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

    $sql = "SELECT s.id AS section_id, s.form_section, f.id AS field_id, f.form_field, f.field_type, f.is_required FROM student_form_sections s LEFT JOIN student_form_fields f ON s.id = f.section_id AND s.inst_id = f.inst_id WHERE s.inst_id = '$instituteId' ORDER BY s.id";
    $result = mysqli_query($conn, $sql);

    $sections = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $sectionId = $row['section_id'];

        if (!isset($sections[$sectionId])) {
            $sections[$sectionId] = [
                "id" => $sectionId,
                "name" => $row['form_section'],
                "fields" => []
            ];
        }

        if ($row['field_id'] !== null) {
            $sections[$sectionId]['fields'][] = [
                "id" => $row['field_id'],
                "name" => $row['form_field'],
                "type" => $row['field_type'],
                "is_required" => $row['is_required']
            ];
        }
    }

    $sections = array_filter($sections, function ($section) {
        return !empty($section['fields']);
    });

    $data = [
        'status' => 200,
        'message' => 'Student form fetched.',
        'form' => array_values($sections)
    ];
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
