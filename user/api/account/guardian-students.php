<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = userAuthenticateRequest();
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
    $userId = mysqli_real_escape_string($conn, $authResult['userId']);
    $instId = mysqli_real_escape_string($conn, (string) $authResult['inst_id']);
    $userType = $authResult['user_type'];

    if ($userType !== 'guardian') {
        $response = [
            'success' => false,
            'status' => 403,
            'message' => 'Your current role is not eligible to access student selection. Please continue with a guardian role.'
        ];
        header("HTTP/1.0 403 Forbidden");
        echo json_encode($response);
        exit;
    }

    $studentsSql = "
        SELECT
            s.`id`,
            s.`user_id`,
            s.`enrollment_id`,
            u.`inst_id`,
            u.`name`,
            u.`profile_image`,
            u.`email`,
            u.`phone`
        FROM `students` s
        INNER JOIN `users` u ON u.`id` = s.`user_id`
        WHERE s.`guardian_id`='$userId'
          AND s.`status`='1'
    ";
    $studentsResult = mysqli_query($conn, $studentsSql);

    if (!$studentsResult) {
        $response = [
            'success' => false,
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($response);
        exit;
    }

    $students = [];
    while ($row = mysqli_fetch_assoc($studentsResult)) {
        $studentId = (string) $row['id'];
        $studentInstId = isset($row['inst_id']) ? (string) $row['inst_id'] : '';
        $studentClass = null;
        $studentSection = null;

        if ($studentInstId !== '' && $studentId !== '') {
            $escapedStudentInstId = mysqli_real_escape_string($conn, $studentInstId);
            $escapedStudentId = mysqli_real_escape_string($conn, $studentId);
            $studentFieldsSql = "SELECT `field_name`, `value` FROM `student_field_values` WHERE `inst_id` = '$escapedStudentInstId' AND `student_id` = '$escapedStudentId' AND `field_name` IN ('Class / Standard', 'Section')";
            $studentFieldsResult = mysqli_query($conn, $studentFieldsSql);

            if ($studentFieldsResult) {
                while ($fieldRow = mysqli_fetch_assoc($studentFieldsResult)) {
                    if ((string) $fieldRow['field_name'] === 'Class / Standard') {
                        $studentClass = trim((string) $fieldRow['value']);
                    }
                    if ((string) $fieldRow['field_name'] === 'Section') {
                        $studentSection = trim((string) $fieldRow['value']);
                    }
                }
            }
        }

        $students[] = [
            'id' => (int) $row['id'],
            'inst_id' => $studentInstId,
            'name' => $row['name'],
            'profile_image' => $row['profile_image'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'enrollment_id' => $row['enrollment_id'],
            'class' => $studentClass,
            'section' => $studentSection,
        ];
    }

    $response = [
        'success' => true,
        'status' => 200,
        'message' => 'Students fetched successfully.',
        'data' => $students,
    ];
    header("HTTP/1.0 200 OK");
    echo json_encode($response);
    exit;
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
