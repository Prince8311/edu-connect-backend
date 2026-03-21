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

    $adminSql = "SELECT i.inst_id FROM admin_users a JOIN institutions i ON a.id = i.admin_id WHERE a.id = '$userId' LIMIT 1";
    $adminResult = mysqli_query($conn, $adminSql);

    if (!$adminResult || mysqli_num_rows($adminResult) === 0) {
        header("HTTP/1.0 401 Bad request");
        echo json_encode([
            "status" => 401,
            "message" => "Invalid token or institute not found"
        ]);
        exit;
    }

    $adminData = mysqli_fetch_assoc($adminResult);
    $instituteId = $adminData['inst_id'];

    mysqli_begin_transaction($conn);

    try {
        // --------------------------------
        // FETCH CLASS SECTIONS
        // --------------------------------
        $sql = "SELECT `id`, `sections` FROM `academic_class_sections` WHERE `inst_id`='$instituteId' AND `class`='$class' AND `level_id`='$academicLevelId' LIMIT 1";
        $result = mysqli_query($conn, $sql);

        if (!$result || mysqli_num_rows($result) === 0) {
            throw new Exception("Class not found");
        }

        $row = mysqli_fetch_assoc($result);
        $sections = $row['sections'];

        // --------------------------------
        // GENERATE NEW SECTION
        // --------------------------------
        if (empty($sections)) {
            $newSection = "A";
            $updatedSections = "A";
        } else {
            $sectionsArray = explode(",", $sections);
            $lastSection = trim(end($sectionsArray));

            if ($lastSection === 'Z') {
                throw new Exception("Maximum section limit reached");
            }

            $newSection = chr(ord($lastSection) + 1);
            $sectionsArray[] = $newSection;
            $updatedSections = implode(",", $sectionsArray);
        }

        // --------------------------------
        // UPDATE academic_class_sections
        // --------------------------------
        $updateSql = "UPDATE `academic_class_sections` SET `sections`='$updatedSections' WHERE `id`='{$row['id']}'";

        if (!mysqli_query($conn, $updateSql)) {
            throw new Exception("Failed to update class sections");
        }

        // --------------------------------
        // FETCH DISTINCT SUBJECTS
        // --------------------------------
        $subjectSql = "SELECT DISTINCT `subject` FROM `class_wise_subjects` WHERE `inst_id`='$instituteId' AND `level_id`='$academicLevelId' AND `class`='$class'";
        $subjectResult = mysqli_query($conn, $subjectSql);

        if (!$subjectResult) {
            throw new Exception("Failed to fetch subjects");
        }

        // --------------------------------
        // INSERT NEW SECTION FOR EACH SUBJECT
        // --------------------------------
        while ($subjectRow = mysqli_fetch_assoc($subjectResult)) {
            $subject = mysqli_real_escape_string($conn, $subjectRow['subject']);

            $checkSql = "SELECT `id` FROM `class_wise_subjects` WHERE `inst_id`='$instituteId' AND `level_id`='$academicLevelId' AND `class`='$class' AND `section`='$newSection' AND `subject`='$subject' LIMIT 1";
            $checkResult = mysqli_query($conn, $checkSql);

            if (mysqli_num_rows($checkResult) > 0) {
                continue;
            }

            $insertSql = "INSERT INTO `class_wise_subjects`(`inst_id`, `subject`, `level_id`, `class`, `section`, `is_mandatory`, `students`) VALUES ('$instituteId', '$subject', '$academicLevelId', '$class', '$newSection', 1, NULL)";

            if (!mysqli_query($conn, $insertSql)) {
                throw new Exception("Failed inserting subject for section");
            }
        }

        // --------------------------------
        // COMMIT
        // --------------------------------
        mysqli_commit($conn);

        $data = [
            'status' => 200,
            'message' => 'Section added successfully.'
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error.'
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
