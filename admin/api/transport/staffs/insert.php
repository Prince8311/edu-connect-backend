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

    if (!in_array($intent, ['add', 'update'], true)) {
        $data = [
            'status' => 400,
            'message' => 'Invalid intent. Allowed values: add, update.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if (!isset($_POST['inputs'])) {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $inputData = json_decode($_POST['inputs'], true);
    if (!is_array($inputData)) {
        $data = [
            'status' => 400,
            'message' => 'Invalid inputs payload'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $name = mysqli_real_escape_string($conn, $inputData['name'] ?? '');
    $role = mysqli_real_escape_string($conn, $inputData['role'] ?? '');
    $contactNo = mysqli_real_escape_string($conn, $inputData['phone'] ?? ($inputData['contact_no'] ?? ''));
    $email = mysqli_real_escape_string($conn, $inputData['email'] ?? '');
    $status = filter_var($inputData['status'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $uploadDir = __DIR__ . "/../../../../documents/driving-license/";

    if ($name === '' || $role === '' || $contactNo === '') {
        $data = [
            'status' => 400,
            'message' => 'Name, role and contact number are required'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $licenseFile = null;
    if (isset($_FILES['license_file'])) {
        $licenseFile = $_FILES['license_file'];
    } elseif (isset($_FILES['file'])) {
        $licenseFile = $_FILES['file'];
    } elseif (isset($_FILES['image'])) {
        $licenseFile = $_FILES['image'];
    } else {
        $firstFileKey = array_key_first($_FILES);
        if ($firstFileKey !== null) {
            $licenseFile = $_FILES[$firstFileKey];
        }
    }

    if ($intent === 'add') {
        $isDriver = strtolower(trim($inputData['role'] ?? '')) === 'driver';
        if ($isDriver && (!$licenseFile || !isset($licenseFile['error']) || $licenseFile['error'] !== UPLOAD_ERR_OK)) {
            $data = [
                'status' => 400,
                'message' => 'Driving license file is required for Driver role'
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }

        $originalName = $licenseFile['name'] ?? '';
        $tmpPath = $licenseFile['tmp_name'] ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array($extension, $allowedExtensions, true)) {
            $data = [
                'status' => 400,
                'message' => 'Invalid file type. Only jpg, jpeg, png, pdf are allowed'
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }

        $nameForFile = trim($inputData['name'] ?? '');
        $safeNameForFile = strtolower($nameForFile);
        $safeNameForFile = preg_replace('/[^a-z0-9]+/', '-', $safeNameForFile);
        $safeNameForFile = trim($safeNameForFile, '-');
        if ($safeNameForFile === '') {
            $safeNameForFile = 'staff';
        }

        $newFileName = $safeNameForFile . '-license-' . time() . '.' . $extension;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
            $data = [
                'status' => 500,
                'message' => 'Could not create upload directory'
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        $destinationPath = $uploadDir . $newFileName;
        if (!move_uploaded_file($tmpPath, $destinationPath)) {
            $data = [
                'status' => 500,
                'message' => 'Failed to upload driving license file'
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        $licenseFileName = mysqli_real_escape_string($conn, $newFileName);

        $sql = "INSERT INTO `transport_staffs`(`inst_id`, `name`, `role`, `contact_no`, `email`, `license_file`, `status`) VALUES ('$instituteId','$name','$role','$contactNo','$email','$licenseFileName','$status')";
        $result = mysqli_query($conn, $sql);

        if ($result) {
            $data = [
                'status' => 200,
                'message' => 'Transport staff added successfully.'
            ];
            header("HTTP/1.0 200 OK");
            echo json_encode($data);
        } else {
            if (file_exists($destinationPath)) {
                unlink($destinationPath);
            }

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

    $staffSql = "SELECT `id`, `license_file` FROM `transport_staffs` WHERE `inst_id`='$instituteId' AND `id`='$id' LIMIT 1";
    $staffResult = mysqli_query($conn, $staffSql);

    if (!$staffResult) {
        $data = [
            'status' => 500,
            'message' => 'Internal Server Error.'
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    if (mysqli_num_rows($staffResult) === 0) {
        $data = [
            'status' => 404,
            'message' => 'Transport staff not found.'
        ];
        header("HTTP/1.0 404 Not Found");
        echo json_encode($data);
        exit;
    }

    $staffRow = mysqli_fetch_assoc($staffResult);
    $hasNewFile = $licenseFile && isset($licenseFile['error']) && $licenseFile['error'] === UPLOAD_ERR_OK;

    if (!$hasNewFile) {
        $sql = "UPDATE `transport_staffs` SET `name`='$name', `role`='$role', `contact_no`='$contactNo', `email`='$email', `status`='$status' WHERE `inst_id`='$instituteId' AND `id`='$id'";
        $result = mysqli_query($conn, $sql);

        if ($result) {
            $data = [
                'status' => 200,
                'message' => 'Transport staff updated successfully.'
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

    $originalName = $licenseFile['name'] ?? '';
    $tmpPath = $licenseFile['tmp_name'] ?? '';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $data = [
            'status' => 400,
            'message' => 'Invalid file type. Only jpg, jpeg, png, pdf are allowed'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $nameForFile = trim($inputData['name'] ?? '');
    $safeNameForFile = strtolower($nameForFile);
    $safeNameForFile = preg_replace('/[^a-z0-9]+/', '-', $safeNameForFile);
    $safeNameForFile = trim($safeNameForFile, '-');
    if ($safeNameForFile === '') {
        $safeNameForFile = 'staff';
    }

    $newFileName = $safeNameForFile . '-license-' . time() . '.' . $extension;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
        $data = [
            'status' => 500,
            'message' => 'Could not create upload directory'
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    $existingFileName = $staffRow['license_file'] ?? '';
    $existingFilePath = $existingFileName !== '' ? $uploadDir . $existingFileName : '';
    if ($existingFilePath !== '' && file_exists($existingFilePath)) {
        unlink($existingFilePath);
    }

    $destinationPath = $uploadDir . $newFileName;
    if (!move_uploaded_file($tmpPath, $destinationPath)) {
        $data = [
            'status' => 500,
            'message' => 'Failed to upload driving license file'
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    $licenseFileName = mysqli_real_escape_string($conn, $newFileName);
    $sql = "UPDATE `transport_staffs` SET `name`='$name', `role`='$role', `contact_no`='$contactNo', `email`='$email', `license_file`='$licenseFileName', `status`='$status' WHERE `inst_id`='$instituteId' AND `id`='$id'";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $data = [
            'status' => 200,
            'message' => 'Transport staff updated successfully.'
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } else {
        if (file_exists($destinationPath)) {
            unlink($destinationPath);
        }

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
