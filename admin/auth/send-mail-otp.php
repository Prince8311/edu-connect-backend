<?php

require "../../utils/headers.php";

if ($requestMethod === 'POST') {
    require "../../_db-connect.php";
    global $conn;

    require "../../PHPMailer/Exception.php";
    require "../../PHPMailer/PHPMailer.php";
    require "../../PHPMailer/SMTP.php";

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (!empty($inputData)) {
        $user = mysqli_real_escape_string($conn, $inputData['name']);

        $sql = "SELECT * FROM `admin_users` WHERE `name`='$user' OR `email`='$user' OR `phone`='$user'";
        $result = mysqli_query($conn, $sql);

        if (!$result) {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
        }

        if (mysqli_num_rows($result) === 1) {
            $data = mysqli_fetch_assoc($result);
            $userId = $data['id'];
            $userEmail = $data['email'];
            $userType = $data['user_type'];

            $data = [
                'status' => 200,
                'message' => 'User details',
            ];
            header("HTTP/1.0 200 ok");
            echo json_encode($data);
        } else {
            $data = [
                'status' => 404,
                'message' => 'User Not Found',
            ];
            header("HTTP/1.0 404 User Not Found");
            echo json_encode($data);
        }
    } else {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
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
