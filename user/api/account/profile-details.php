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

    $sql = "SELECT u.id AS user_id, u.name, u.profile_image, u.email, u.phone, u.user_type, MAX(CASE WHEN sfv.field_name = 'Class / Standard' THEN sfv.value END) AS class_standard, MAX(CASE WHEN sfv.field_name = 'Section' THEN sfv.value END) AS section FROM users u LEFT JOIN students s ON u.id = s.user_id LEFT JOIN student_field_values sfv ON s.id = sfv.student_id GROUP BY u.id, u.name, u.profile_image, u.email, u.phone, u.user_type";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        $response = [
            'success' => false,
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($response);
    }

    if (mysqli_num_rows($result) === 1) {
        $userData = mysqli_fetch_assoc($result);
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
