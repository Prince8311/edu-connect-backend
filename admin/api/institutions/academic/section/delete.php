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

    $academicLevelId = mysqli_real_escape_string($conn, $inputData['academicLevelId']);
    $class = mysqli_real_escape_string($conn, $inputData['class']);
    $section = mysqli_real_escape_string($conn, $inputData['section']);

    $sectionSql = "SELECT id, sections FROM academic_class_sections WHERE inst_id='$instituteId' AND level_id='$academicLevelId' AND class='$class' LIMIT 1";
    $sectionResult = mysqli_query($conn, $sectionSql);

    if ($sectionResult && mysqli_num_rows($sectionResult) > 0) {
        $row = mysqli_fetch_assoc($sectionResult);
        $sections = $row['sections'];

        if (!empty($sections)) {
            $sectionsArray = explode(",", $sections);
            $updatedArray = [];
            foreach ($sectionsArray as $sec) {
                if (trim($sec) !== $section) {
                    $updatedArray[] = trim($sec);
                }
            }
            $updatedSections = empty($updatedArray) ? NULL : implode(",", $updatedArray);
            $updateSql = "UPDATE academic_class_sections SET sections=" . ($updatedSections ? "'$updatedSections'" : "NULL") . " WHERE id='{$row['id']}'";
            mysqli_query($conn, $updateSql);
        }
    }

    $subjectSql = "SELECT id, sections FROM class_wise_subjects WHERE inst_id='$instituteId' AND level_id='$academicLevelId' AND class='$class'";
    $subjectResult = mysqli_query($conn, $subjectSql);

    if ($subjectResult && mysqli_num_rows($subjectResult) > 0) {
        while ($subjectRow = mysqli_fetch_assoc($subjectResult)) {
            $subjectSections = $subjectRow['sections'];
            if (!empty($subjectSections)) {
                $sectionsArray = explode(",", $subjectSections);
                $updatedArray = [];
                foreach ($sectionsArray as $sec) {
                    $secParts = explode("-", $sec);
                    $secLetter = trim($secParts[0]);
                    if ($secLetter !== $section) {
                        $updatedArray[] = trim($sec);
                    }
                }

                $updatedSections = empty($updatedArray) ? NULL : implode(",", $updatedArray);
                $updateSubjectSql = "UPDATE class_wise_subjects SET sections=" . ($updatedSections ? "'$updatedSections'" : "NULL") . " WHERE id='{$subjectRow['id']}'";
                mysqli_query($conn, $updateSubjectSql);
            }
        }
    }

    $data = [
        'status' => 200,
        'message' => 'Section removed successfully',
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
