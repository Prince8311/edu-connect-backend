<?php

require "../../../utils/headers.php";
require "../../../utils/middleware.php";

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
    require "../../../_db-connect.php";
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

    $students = $inputData['students'] ?? [];
    $session = mysqli_real_escape_string($conn, $inputData['session']);
    $isBulkUpload = $inputData['isBulkUpload'] ?? false;

    if (empty($students)) {
        echo json_encode([
            "status" => 400,
            "message" => "No students found in request"
        ]);
        exit;
    }

    mysqli_begin_transaction($conn);

    function generateEnrollmentId($instId, $session)
    {
        // get 2 letters from institute id
        $instLetters = strtoupper(substr(preg_replace("/[^A-Za-z]/", "", $instId), 0, 2));

        if (strlen($instLetters) < 2) {
            $instLetters = "IN";
        }

        // session 2026-27 → 2627
        $sessionDigits = substr(str_replace("-", "", $session), -4);

        // random numbers
        $rand1 = rand(1, 9);
        $rand2 = rand(1, 9);

        return $rand1 . $instLetters[0] . $rand2 . $instLetters[1] . $sessionDigits;
    }

    try {
        foreach ($students as $student) {
            $enrollmentId = generateEnrollmentId($instituteId, $session);

            $studentInsertSql = "INSERT INTO `students` (`inst_id`, `enrollment_id`, `created_at`) VALUES ('$instituteId', '$enrollmentId', NOW())";

            if (!mysqli_query($conn, $studentInsertSql)) {
                throw new Exception("Failed to insert student");
            }

            $studentId = mysqli_insert_id($conn);
            $studentFields = $student['student_fields'] ?? [];

            foreach ($studentFields as $field) {
                $sectionId = mysqli_real_escape_string($conn, $field['section_id']);
                $fieldName = mysqli_real_escape_string($conn, $field['field_name']);
                $value = mysqli_real_escape_string($conn, $field['value']);

                $fieldInsertSql = "INSERT INTO `student_field_values` (`inst_id`, `student_id`, `section_id`, `field_name`, `value`) VALUES ('$instituteId', '$studentId', '$sectionId', '$fieldName', '$value')";

                if (!mysqli_query($conn, $fieldInsertSql)) {
                    throw new Exception("Failed to insert student field value");
                }
            }
        }

        mysqli_commit($conn);
        $message = $isBulkUpload ? 'Students uploaded successfully' : 'Student uploaded successfully';
        echo json_encode([
            "status" => 200,
            "message" => $message
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Transaction failed",
            "error" => $e->getMessage()
        ]);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
