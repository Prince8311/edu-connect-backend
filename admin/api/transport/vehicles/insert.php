<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

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
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    $intent = strtolower(trim($_GET['intent'] ?? 'add'));

    if (!in_array($intent, ['add', 'update'])) {
        $data = [
            'status' => 400,
            'message' => 'Invalid intent..'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

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

    $name = mysqli_real_escape_string($conn, $inputData['name']);
    $number = mysqli_real_escape_string($conn, $inputData['number']);
    $type = mysqli_real_escape_string($conn, $inputData['type']);
    $capacity = mysqli_real_escape_string($conn, $inputData['capacity']);
    $status = filter_var($inputData['status'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

    if ($intent === 'add') {
        $checkSql = "SELECT * FROM `transport_vehicles` WHERE `inst_id`='$instituteId' AND `number`='$number'";
        $checkResult = mysqli_query($conn, $checkSql);

        if (!$checkResult) {
            $data = [
                'status' => 500,
                'message' => 'Internal Server Error.'
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        if (mysqli_num_rows($checkResult) > 1) {
            $data = [
                'status' => 400,
                'message' => 'This vehicle already registered.'
            ];
            header("HTTP/1.0 400 Already exists");
            echo json_encode($data);
            exit;
        }

        $sql = "INSERT INTO `transport_vehicles`(`inst_id`, `name`, `number`, `type`, `capacity`, `status`) VALUES ('$instituteId','$name','$number','$type','$capacity','$status')";
        $result = mysqli_query($conn, $sql);

        if ($result) {
            $data = [
                'status' => 200,
                'message' => 'Vehicle registered successfully.'
            ];
            header("HTTP/1.0 200 OK");
            echo json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
        }
        exit;
    }

    $id = mysqli_real_escape_string($conn, (string)($inputData['id'] ?? ''));

    if ($id === '') {
        $data = [
            'status' => 400,
            'message' => 'id is required for update intent.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $existsSql = "SELECT `id` FROM `transport_vehicles` WHERE `inst_id`='$instituteId' AND `id`='$id' LIMIT 1";
    $existsResult = mysqli_query($conn, $existsSql);

    if (!$existsResult) {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error.'
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    if (mysqli_num_rows($existsResult) === 0) {
        $data = [
            'status' => 404,
            'message' => 'Vehicle not found.'
        ];
        header("HTTP/1.0 404 Not Found");
        echo json_encode($data);
        exit;
    }

    $checkSql = "SELECT `id` FROM `transport_vehicles` WHERE `inst_id`='$instituteId' AND `number`='$number' AND `id`!='$id' LIMIT 1";
    $checkResult = mysqli_query($conn, $checkSql);

    if (!$checkResult) {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error.'
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    if (mysqli_num_rows($checkResult) > 0) {
        $data = [
            'status' => 400,
            'message' => 'This vehicle number is already registered.'
        ];
        header("HTTP/1.0 400 Already exists");
        echo json_encode($data);
        exit;
    }

    $sql = "UPDATE `transport_vehicles` SET `name`='$name', `number`='$number', `type`='$type', `capacity`='$capacity', `status`='$status' WHERE `inst_id`='$instituteId' AND `id`='$id'";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $data = [
            'status' => 200,
            'message' => 'Vehicle updated successfully.'
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } else {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
    }
    exit;
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
