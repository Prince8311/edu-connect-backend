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
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

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

    $sql = "SELECT s.id AS section_id, s.form_section, f.id AS field_id, f.form_field, f.field_type, f.is_required, f.items FROM student_form_sections s LEFT JOIN student_form_fields f ON s.id = f.section_id AND (f.inst_id = '$instituteId' OR f.inst_id IS NULL) WHERE (s.inst_id = '$instituteId' OR s.inst_id IS NULL) ORDER BY s.id ASC, f.sort_order ASC";
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
            $decodedItems = null;
            if (!empty($row['items'])) {
                $decodedItems = json_decode($row['items'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $decodedItems = null;
                }
            }
            $sections[$sectionId]['fields'][] = [
                "id" => $row['field_id'],
                "name" => $row['form_field'],
                "type" => $row['field_type'],
                "is_required" => (bool) $row['is_required'],
                "items" => $decodedItems
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
