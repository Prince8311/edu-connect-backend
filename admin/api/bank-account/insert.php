<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    $data = [
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ];
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode($data);
    exit;
}

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    if (empty($instituteId)) {
        $data = [
            'status' => 422,
            'message' => 'Institute ID is missing from authentication'
        ];

        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }

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

    $accountName = mysqli_real_escape_string($conn, $inputData['accountName'] ?? '');
    $accountNo = mysqli_real_escape_string($conn, $inputData['accountNo'] ?? '');
    $beneficiaryName = mysqli_real_escape_string($conn, $inputData['beneficiaryName'] ?? '');
    $ifscCode = mysqli_real_escape_string($conn, $inputData['ifscCode'] ?? '');

    $id = (int)($inputData['id'] ?? 0);
    $statusInput = $inputData['status'] ?? false;
    $status = filter_var($statusInput, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

    if ($accountName === '' || $accountNo === '' || $beneficiaryName === '' || $ifscCode === '') {
        $data = [
            'status' => 400,
            'message' => 'account_name, account_no, beneficiary_name, and ifsc_code are required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if ($intent === 'update' && $id <= 0) {
        $data = [
            'status' => 400,
            'message' => 'id is required for update.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $instIdEsc = mysqli_real_escape_string($conn, (string)$instituteId);
    $accountNoEsc = mysqli_real_escape_string($conn, $accountNo);

    if ($intent === 'add') {
        $duplicateSql = "SELECT `id` FROM `institution_bank_accounts` WHERE `inst_id` = '$instIdEsc' AND `account_no` = '$accountNoEsc' LIMIT 1";
    } else {
        $duplicateSql = "SELECT `id` FROM `institution_bank_accounts` WHERE `inst_id` = '$instIdEsc' AND `account_no` = '$accountNoEsc' AND `id` != '$id' LIMIT 1";
    }

    $duplicateResult = mysqli_query($conn, $duplicateSql);
    if ($duplicateResult === false) {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    if (mysqli_num_rows($duplicateResult) > 0) {
        $data = [
            'status' => 409,
            'message' => 'This bank account already exists for the institute.'
        ];
        header("HTTP/1.0 409 Conflict");
        echo json_encode($data);
        exit;
    }

    $hasNewCheque = isset($_FILES['cancelled_cheque']) && $_FILES['cancelled_cheque']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($intent === 'add' && !$hasNewCheque) {
        $data = [
            'status' => 400,
            'message' => 'cancelled_cheque image is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $fileName = null;
    $destinationPath = null;

    if ($hasNewCheque) {
        $uploadedFile = $_FILES['cancelled_cheque'];
        if ((int)$uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $data = [
                'status' => 400,
                'message' => 'File upload error code: ' .
                    (int)$uploadedFile['error']
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }

        $tmpPath = (string)($uploadedFile['tmp_name'] ?? '');

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            $data = [
                'status' => 400,
                'message' => 'Invalid uploaded file.'
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }

        $allowedMimes = ['image/jpeg', 'image/png'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        $extension = strtolower(
            pathinfo(
                (string)($uploadedFile['name'] ?? ''),
                PATHINFO_EXTENSION
            )
        );

        if (!in_array($mimeType, $allowedMimes, true) || !in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            $data = [
                'status' => 400,
                'message' => 'Invalid file type. Allowed types: jpg, jpeg, png.'
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }

        $uploadDir = __DIR__ . '/../../../documents/cheque/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
            $data = [
                'status' => 500,
                'message' => 'Could not create cheque upload directory.'
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        $safeBase = strtolower($beneficiaryName);
        $safeBase = preg_replace('/[^a-z0-9]+/', '-', $safeBase);
        $safeBase = trim((string)$safeBase, '-');

        if ($safeBase === '') {
            $safeBase = 'cheque';
        }

        $fileName = $safeBase . '-cheque-' . time() . '.' . $extension;
        $destinationPath = $uploadDir . $fileName;

        if (!move_uploaded_file($tmpPath, $destinationPath)) {
            $data = [
                'status' => 500,
                'message' => 'Failed to save cancelled cheque image.'
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }
    }

    $accountNameEsc = mysqli_real_escape_string($conn, $accountName);
    $beneficiaryNameEsc = mysqli_real_escape_string($conn, $beneficiaryName);
    $ifscCodeEsc = mysqli_real_escape_string($conn, $ifscCode);

    if ($intent === 'add') {
        $fileNameEsc = mysqli_real_escape_string($conn, $fileName);

        $sql = "INSERT INTO `institution_bank_accounts`(`inst_id`, `account_name`, `account_no`, `beneficiary_name`, `ifsc_code`, `cancelled_cheque`) VALUES ('$instIdEsc','$accountNameEsc','$accountNoEsc','$beneficiaryNameEsc','$ifscCodeEsc','$fileNameEsc')";
    } else {
        $oldChequeFile = null;
        if ($intent === 'update' && $fileName !== null) {
            $oldChequeQuery = "SELECT `cancelled_cheque` FROM `institution_bank_accounts` WHERE `id` = '$id' AND `inst_id` = '$instIdEsc' LIMIT 1";
            $oldChequeResult = mysqli_query($conn, $oldChequeQuery);

            if ($oldChequeResult && mysqli_num_rows($oldChequeResult) > 0) {
                $oldChequeData = mysqli_fetch_assoc(
                    $oldChequeResult
                );
                $oldChequeFile = $oldChequeData['cancelled_cheque'];
            }
        }

        $sql = "UPDATE `institution_bank_accounts` SET `account_name`='$accountNameEsc', `account_no`='$accountNoEsc', `beneficiary_name`='$beneficiaryNameEsc', `ifsc_code`='$ifscCodeEsc', `status`='$status'";

        if ($fileName !== null) {
            $fileNameEsc = mysqli_real_escape_string($conn, $fileName);
            $sql .= ", `cancelled_cheque`='$fileNameEsc'";
        }

        $sql .= " WHERE `id`='$id' AND `inst_id`='$instIdEsc'";
    }

    $result = mysqli_query($conn, $sql);

    if ($result) {
        if ($intent === 'update' && $fileName !== null && !empty($oldChequeFile)) {
            $oldFilePath = __DIR__ . '/../../../documents/cheque/' . $oldChequeFile;

            if (file_exists($oldFilePath)) {
                unlink($oldFilePath);
            }
        }
        $data = [
            'status' => 200,
            'message' => $intent === 'add'
                ? 'Bank account created successfully.'
                : 'Bank account updated successfully.'
        ];

        header("HTTP/1.0 200 OK");
        echo json_encode($data);
        exit;
    }

    if ($destinationPath !== null && file_exists($destinationPath)) {
        unlink($destinationPath);
    }

    $data = [
        'status' => 500,
        'message' => 'Database error: ' .
            mysqli_error($conn)
    ];

    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod .
            ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
