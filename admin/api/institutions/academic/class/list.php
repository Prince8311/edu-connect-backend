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

if ($requestMethod === 'GET') {
    require "../../../../../_db-connect.php";
    global $conn;
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    if (!isset($_GET['levelId'])) {
        $data = [
            'status' => 400,
            'message' => 'Academic level id required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }

    $levelId = mysqli_real_escape_string($conn, $_GET['levelId']);
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

    $sql = "SELECT `id`, `level_id`, `class`, `sections` FROM `academic_class_sections` WHERE `inst_id`='$instituteId' AND `level_id`='$levelId'";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $classes = [];

        while ($row = mysqli_fetch_assoc($result)) {
            $sectionsArray = [];
            if (!empty($row['sections'])) {
                $sectionsArray = explode(',', $row['sections']);
            }
            $classes[] = [
                "id" => $row['id'],
                "level_id" => $row['level_id'],
                "class" => $row['class'],
                "sections" => $sectionsArray
            ];
        }
        $data = [
            'status' => 200,
            'message' => 'Classes fetched.',
            'classes' => $classes
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
