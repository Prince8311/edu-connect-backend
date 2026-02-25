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

    if (!isset($_GET['type'])) {
        $data = [
            'status' => 400,
            'message' => 'Section type required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }

    $type = mysqli_real_escape_string($conn, $_GET['type']);
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

    $sectionSql = "SELECT form_section, COUNT(CASE WHEN form_field IS NOT NULL AND form_field != '' THEN 1 END) AS total_fields FROM student_form_fields WHERE inst_id = '$instituteId' AND section_type = '$type' GROUP BY form_section ORDER BY id ASC";
    $sectionResult = mysqli_query($conn, $sectionSql);

    $sections = [];
    if ($sectionResult && mysqli_num_rows($sectionResult) > 0) {
        while ($row = mysqli_fetch_assoc($sectionResult)) {
            $sections[] = [
                "name" => $row['form_section'],
                "total_fields" => (int)$row['total_fields']
            ];
        }
    }

    $data = [
        'status' => 200,
        'message' => 'Profile information sections.',
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
