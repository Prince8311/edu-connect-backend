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
    $section = mysqli_real_escape_string($conn, $inputData['section']);

    $checkSql = "SELECT * FROM `student_form_sections` WHERE `id`='$sectionId' AND `inst_id`='$instituteId' AND `form_section`='$section'";
    $checkResult = mysqli_query($conn, $checkSql);

    if ($checkResult && mysqli_num_rows($checkResult) === 0) {
        $data = [
            'status' => 400,
            'message' => "This section doesn't exists."
        ];
        header("HTTP/1.0 400 Bad request");
        echo json_encode($data);
        exit;
    }

    mysqli_begin_transaction($conn);

    try {
        $deleteFieldsSql = "DELETE FROM `student_form_fields` WHERE `inst_id`='$instituteId' AND `section_id`='$sectionId'";
        $deleteFieldsResult = mysqli_query($conn, $deleteFieldsSql);
        if (!$deleteFieldsResult) {
            throw new Exception("Failed to delete fields: " . mysqli_error($conn));
        }

        $deleteSectionSql = "DELETE FROM `student_form_sections` WHERE `id`='$sectionId' AND `inst_id`='$instituteId' AND `form_section`='$section'";
        $deleteSectionResult = mysqli_query($conn, $deleteSectionSql);

        if (!$deleteSectionResult) {
            throw new Exception("Failed to delete section: " . mysqli_error($conn));
        }

        mysqli_commit($conn);

        echo json_encode([
            'status' => 200,
            'message' => "Section removed successfully."
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);

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
