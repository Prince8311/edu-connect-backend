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

if ($requestMethod === 'POST') {
    require "../../../../../_db-connect.php";
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

    $academicLevelId = mysqli_real_escape_string($conn, $inputData['academicLevelId']);
    $class = mysqli_real_escape_string($conn, $inputData['class']);
    $subject = mysqli_real_escape_string($conn, $inputData['subject']);

    $adminSql = "SELECT i.inst_id FROM `admin_users` a JOIN institutions i ON a.id = i.admin_id WHERE a.id = '$userId' LIMIT 1";
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

    $checkSql = "SELECT `sections` FROM `academic_class_sections` WHERE `inst_id`='$instituteId' AND `level_id`='$academicLevelId' AND `class`='$class' LIMIT 1";
    $checkResult = mysqli_query($conn, $checkSql);

    $subjectSections = null;
    if ($checkResult && mysqli_num_rows($checkResult) > 0) {
        $row = mysqli_fetch_assoc($checkResult);
        $sections = $row['sections'];

        if (!empty($sections)) {
            $sectionsArray = explode(",", $sections);
            $mappedSections = [];
            foreach ($sectionsArray as $sec) {
                $mappedSections[] = trim($sec) . "-1";
            }
            $subjectSections = implode(",", $mappedSections);
        }
    }

    $insertSql = "INSERT INTO `class_wise_subjects` (`inst_id`, `subject`, `level_id`, `class`, `sections`) VALUES ('$instituteId', '$subject', '$academicLevelId', '$class', " . ($subjectSections ? "'$subjectSections'" : "NULL") . ")";
    $insertResult = mysqli_query($conn, $insertSql);

    if ($insertResult) {
        $data = [
            'status' => 200,
            'message' => 'Subject added successfully.'
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 500,
            'message' => 'Failed to add subject.'
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
