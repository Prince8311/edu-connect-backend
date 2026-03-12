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

    if (!isset($_GET['type'])) {
        $data = [
            'status' => 400,
            'message' => 'Section type required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }

    $type = mysqli_real_escape_string($conn, $_GET['type']);
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

    $sectionSql = "SELECT s.id, s.form_section, COUNT(f.id) AS total_fields FROM student_form_sections s LEFT JOIN student_form_fields f ON s.id = f.section_id AND f.inst_id = '$instituteId' WHERE (s.inst_id = '$instituteId' OR s.inst_id IS NULL) AND s.section_type = '$type' GROUP BY s.id ORDER BY s.id ASC";
    $sectionResult = mysqli_query($conn, $sectionSql);

    $sections = [];
    if ($sectionResult && mysqli_num_rows($sectionResult) > 0) {
        while ($row = mysqli_fetch_assoc($sectionResult)) {
            $sections[] = [
                "id" => $row['id'],
                "name" => $row['form_section'],
                "total_fields" => (int)$row['total_fields'],
                "isRemoval" => $row['inst_id'] !== null
            ];
        }
    }

    $data = [
        'status' => 200,
        'message' => 'Form sections fetched.',
        'sections' => $sections
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
