<?php

require __DIR__ . "/../../utils/headers.php";

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../_db-connect.php";
    global $conn;

    $tempToken = isset($_GET['tempToken']) ? trim($_GET['tempToken']) : '';

    if ($tempToken === '') {
        $response = [
            'success' => false,
            'status' => 400,
            'message' => 'tempToken is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($response);
        exit;
    }

    $escapedTempToken = mysqli_real_escape_string($conn, $tempToken);
    $tokenSql = "SELECT `user_id`, `user_type`, `temp_token_expiry` FROM `user_auth_tokens` WHERE `temp_token`='$escapedTempToken' LIMIT 1";
    $tokenResult = mysqli_query($conn, $tokenSql);

    if (!$tokenResult) {
        $response = [
            'success' => false,
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($response);
        exit;
    }

    if (mysqli_num_rows($tokenResult) !== 1) {
        $response = [
            'success' => false,
            'status' => 401,
            'message' => 'Invalid temp token.'
        ];
        header("HTTP/1.0 401 Unauthorized");
        echo json_encode($response);
        exit;
    }

    $tokenRow = mysqli_fetch_assoc($tokenResult);
    $guardianUserId = (int) $tokenRow['user_id'];
    $tokenUserType = isset($tokenRow['user_type']) ? strtolower(trim((string) $tokenRow['user_type'])) : '';
    $tempTokenExpiry = $tokenRow['temp_token_expiry'];

    if ($tempTokenExpiry === null || time() > strtotime($tempTokenExpiry)) {
        $response = [
            'success' => false,
            'status' => 401,
            'message' => 'Session has expired. Please login again.'
        ];
        header("HTTP/1.0 401 Unauthorized");
        echo json_encode($response);
        exit;
    }

    if ($tokenUserType !== 'guardian') {
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
            u.`name`,
            u.`profile_image`,
            u.`email`,
            u.`phone`
        FROM `students` s
        INNER JOIN `users` u ON u.`id` = s.`user_id`
        WHERE s.`guardian_id`='$guardianUserId'
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
        $students[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'profile_image' => $row['profile_image'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'enrollment_id' => $row['enrollment_id'],
        ];
    }

    $response = [
        'success' => true,
        'status' => 200,
        'message' => 'Students fetched successfully.',
        'data' => [
            'students' => $students
        ],
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
