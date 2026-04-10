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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $sql = "SELECT al.id AS level_id, al.level_name, acs.class, acs.sections FROM academic_levels al LEFT JOIN academic_class_sections acs ON al.id = acs.level_id AND al.inst_id = acs.inst_id WHERE al.inst_id = '$instituteId' ORDER BY al.id, acs.class";
    $result = mysqli_query($conn, $sql);

    $response = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $levelId = $row['level_id'];

        if (!isset($response[$levelId])) {
            $response[$levelId] = [
                'id' => (int)$levelId,
                'level_name' => $row['level_name'],
                'classes' => []
            ];
        }

        if (!empty($row['class'])) {
            $sectionsArray = !empty($row['sections'])
                ? explode(',', $row['sections'])
                : [];

            $response[$levelId]['classes'][] = [
                'class' => (int)$row['class'],
                'sections' => $sectionsArray
            ];
        }
    }
    $response = array_values($response);
    $data = [
        'status' => 200,
        'message' => 'Academic level fetched.',
        'academics' => $response
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
