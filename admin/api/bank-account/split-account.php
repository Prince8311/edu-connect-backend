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
        $data = ['status' => 422, 'message' => 'Institute ID is missing from authentication'];
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }

    $accountId = trim((string)($_POST['accountId'] ?? ''));
    $class = trim((string)($_POST['class'] ?? ''));
    $section = trim((string)($_POST['section'] ?? ''));
    $feeType = trim((string)($_POST['feeType'] ?? ''));

    if ($accountId === '' || $class === '' || $section === '' || $feeType === '') {
        $data = [
            'status' => 400,
            'message' => 'Account, class, section, and fee type are required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $instIdEsc = mysqli_real_escape_string($conn, (string)$instituteId);
}

?>