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

    $fields = "u.id AS user_id, u.name, u.profile_image, u.email,
        IF(u.is_mail_verified = 1, 1, 0) AS is_mail_verified, u.phone,
        IF(u.is_phone_verified = 1, 1, 0) AS is_phone_verified";
    $joins = '';

    if ($userType === 'student') {
        $fields .= ", s.id AS student_id, s.guardian_id, s.enrollment_id,
            (SELECT MAX(value) FROM student_field_values
                WHERE student_id = s.id AND inst_id = '$instId' AND section_id = 1 AND field_name = 'Session') AS session,
            (SELECT MAX(value) FROM student_field_values
                WHERE student_id = s.id AND inst_id = '$instId' AND section_id = 1 AND field_name = 'Class / Standard') AS class_standard,
            (SELECT MAX(value) FROM student_field_values
                WHERE student_id = s.id AND inst_id = '$instId' AND section_id = 1 AND field_name = 'Section') AS section,
            g.id AS guardian_user_id, g.name AS guardian_name,
            g.profile_image AS guardian_profile_image, g.email AS guardian_email,
            g.phone AS guardian_phone";
        $joins = " LEFT JOIN students s ON s.user_id = u.id AND s.inst_id = '$instId'
            LEFT JOIN users g ON g.id = s.guardian_id AND g.inst_id = '$instId'";
    } elseif ($userType === 'teacher') {
        $fields .= ", t.id AS teacher_id, t.staff_id,
            (SELECT MAX(value) FROM staff_field_values
                WHERE staff_id = t.id AND inst_id = '$instId' AND section_id = 1
                    AND staff_type = 'teaching' AND field_name = 'Subject') AS subject";
        $joins = " LEFT JOIN teachers t ON t.user_id = u.id AND t.inst_id = '$instId'";
    } elseif ($userType === 'guardian') {
        $studentId = mysqli_real_escape_string($conn, (string) $authResult['student_id']);
        $fields .= ", s.id AS student_id, s.user_id AS student_user_id,
            s.enrollment_id AS student_enrollment_id,
            (SELECT MAX(value) FROM student_field_values
                WHERE student_id = s.id AND inst_id = '$instId' AND section_id = 1
                    AND field_name = 'Class / Standard') AS student_class_standard,
            (SELECT MAX(value) FROM student_field_values
                WHERE student_id = s.id AND inst_id = '$instId' AND section_id = 1
                    AND field_name = 'Section') AS student_section,
            su.name AS student_name, su.profile_image AS student_profile_image";
        $joins = " LEFT JOIN students s ON s.id = '$studentId' AND s.inst_id = '$instId'
            LEFT JOIN users su ON su.id = s.user_id AND su.inst_id = '$instId'";
    }

    $sql = "SELECT $fields FROM users u $joins WHERE u.id = '$userId' AND u.inst_id = '$instId'";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        $response = [
            'success' => false,
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($response);
        exit;
    }

    if (mysqli_num_rows($result) === 1) {
        $userData = mysqli_fetch_assoc($result);
        $userData['is_mail_verified'] = (bool)$userData['is_mail_verified'];
        $userData['is_phone_verified'] = (bool)$userData['is_phone_verified'];
        $userData['user_type'] = $userType;

        if ($userType === 'student') {
            $userData['guardian'] = $userData['guardian_user_id'] === null ? null : [
                'name' => $userData['guardian_name'],
                'profile_image' => $userData['guardian_profile_image'],
                'email' => $userData['guardian_email'],
                'phone' => $userData['guardian_phone']
            ];
            unset(
                $userData['guardian_user_id'],
                $userData['guardian_name'],
                $userData['guardian_profile_image'],
                $userData['guardian_email'],
                $userData['guardian_phone']
            );
        } elseif ($userType === 'teacher' && $userData['subject'] !== null) {
            $userData['subject'] = implode(', ', array_map('trim', explode(',', $userData['subject'])));
        } elseif ($userType === 'guardian') {
            $userData['student'] = $userData['student_id'] === null ? null : [
                'user_id' => $userData['student_user_id'],
                'enrollment_id' => $userData['student_enrollment_id'],
                'class_standard' => $userData['student_class_standard'],
                'section' => $userData['student_section'],
                'name' => $userData['student_name'],
                'profile_image' => $userData['student_profile_image']
            ];
            unset(
                $userData['student_id'],
                $userData['student_user_id'],
                $userData['student_enrollment_id'],
                $userData['student_class_standard'],
                $userData['student_section'],
                $userData['student_name'],
                $userData['student_profile_image']
            );
        }
        $response = [
            'success' => true,
            'status' => 200,
            'message' => 'Profile details fetched.',
            'data' => $userData
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($response);
    } else {
        $response = [
            'success' => false,
            'status' => 404,
            'message' => 'User Not Found',
        ];
        header("HTTP/1.0 404 User Not Found");
        echo json_encode($response);
    }
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
