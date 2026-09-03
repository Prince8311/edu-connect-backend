<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

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

if ($requestMethod !== 'POST') {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
    exit;
}

require __DIR__ . "/../../../../_db-connect.php";
global $conn;
$instituteId = $authResult['inst_id'];

$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true);

if (!is_array($inputData)) {
    $data = [
        'status' => 400,
        'message' => 'A valid JSON request body is required.'
    ];
    header("HTTP/1.0 400 Bad Request");
    echo json_encode($data);
    exit;
}

$class = trim((string)($inputData['class'] ?? ''));
$section = trim((string)($inputData['section'] ?? ''));
$studentIdInput = trim((string)($inputData['studentId'] ?? ''));
$paymentMethod = trim((string)($inputData['paymentMethod'] ?? ''));
$paymentDate = trim((string)($inputData['paymentDate'] ?? ''));
$paymentsInput = $inputData['payments'] ?? null;

if (
    $class === ''
    || $section === ''
    || !ctype_digit($studentIdInput)
    || (int)$studentIdInput <= 0
    || $paymentMethod === ''
    || $paymentDate === ''
    || !is_array($paymentsInput)
    || count($paymentsInput) === 0
) {
    $data = [
        'status' => 400,
        'message' => 'class, section, studentId, paymentMethod, paymentDate and a non-empty payments array are required.'
    ];
    header("HTTP/1.0 400 Bad Request");
    echo json_encode($data);
    exit;
}

$payments = [];
foreach ($paymentsInput as $index => $payment) {
    if (!is_array($payment)) {
        $data = [
            'status' => 400,
            'message' => 'Each item in payments must be an object.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    // Accept the API's camelCase name and the database-style name for compatibility.
    $installmentIdInput = trim((string)($payment['installmentId'] ?? $payment['installment_id'] ?? ''));
    $amountInput = $payment['amount'] ?? null;

    $amount = is_numeric($amountInput) ? round((float)$amountInput, 2) : 0;

    if (
        !ctype_digit($installmentIdInput)
        || (int)$installmentIdInput <= 0
        || !is_numeric($amountInput)
        || !is_finite((float)$amountInput)
        || $amount <= 0
    ) {
        $data = [
            'status' => 400,
            'message' => 'Each payment must contain a valid installmentId and an amount greater than zero.',
            'invalidPaymentIndex' => $index
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $payments[] = [
        'installment_id' => (int)$installmentIdInput,
        'amount' => $amount
    ];
}

$requestedSessionId = isset($_GET['session']) ? trim((string)$_GET['session']) : '';

if ($requestedSessionId !== '' && (!ctype_digit($requestedSessionId) || (int)$requestedSessionId <= 0)) {
    $data = [
        'status' => 400,
        'message' => 'session must be a positive integer.'
    ];
    header("HTTP/1.0 400 Bad Request");
    echo json_encode($data);
    exit;
}

if ($requestedSessionId !== '') {
    $sessionSql = "SELECT `id`
                   FROM `academic_sessions`
                   WHERE `id` = ? AND `inst_id` = ?
                   LIMIT 1";
    $sessionStmt = mysqli_prepare($conn, $sessionSql);
} else {
    $sessionSql = "SELECT `id`
                   FROM `academic_sessions`
                   WHERE `inst_id` = ? AND `status` = 'Ongoing'
                   ORDER BY `start_date` DESC, `id` DESC
                   LIMIT 1";
    $sessionStmt = mysqli_prepare($conn, $sessionSql);
}

if (!$sessionStmt) {
    $data = [
        'status' => 500,
        'message' => 'Failed to prepare academic session query.'
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

if ($requestedSessionId !== '') {
    $requestedSessionId = (int)$requestedSessionId;
    mysqli_stmt_bind_param($sessionStmt, 'is', $requestedSessionId, $instituteId);
} else {
    mysqli_stmt_bind_param($sessionStmt, 's', $instituteId);
}

if (!mysqli_stmt_execute($sessionStmt)) {
    mysqli_stmt_close($sessionStmt);
    $data = [
        'status' => 500,
        'message' => 'Failed to fetch academic session.'
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

$session = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionStmt));
mysqli_stmt_close($sessionStmt);

if (!$session) {
    $data = [
        'status' => 404,
        'message' => $requestedSessionId === ''
            ? 'No ongoing academic session found.'
            : 'Academic session not found.'
    ];
    header("HTTP/1.0 404 Not Found");
    echo json_encode($data);
    exit;
}

$studentId = (int)$studentIdInput;
$sessionId = (int)$session['id'];
$studentSql = "SELECT s.`id`
               FROM `students` s
               INNER JOIN `student_field_values` class_field
                   ON class_field.`student_id` = s.`id`
                   AND class_field.`inst_id` = s.`inst_id`
                   AND class_field.`field_name` = 'Class / Standard'
                   AND class_field.`value` = ?
               INNER JOIN `student_field_values` section_field
                   ON section_field.`student_id` = s.`id`
                   AND section_field.`inst_id` = s.`inst_id`
                   AND section_field.`field_name` = 'Section'
                   AND section_field.`value` = ?
               WHERE s.`id` = ? AND s.`inst_id` = ?
               LIMIT 1";
$studentStmt = mysqli_prepare($conn, $studentSql);

if (!$studentStmt) {
    $data = [
        'status' => 500,
        'message' => 'Failed to prepare student query.'
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

mysqli_stmt_bind_param($studentStmt, 'ssis', $class, $section, $studentId, $instituteId);
if (!mysqli_stmt_execute($studentStmt)) {
    mysqli_stmt_close($studentStmt);
    $data = [
        'status' => 500,
        'message' => 'Failed to validate student.'
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

$student = mysqli_fetch_assoc(mysqli_stmt_get_result($studentStmt));
mysqli_stmt_close($studentStmt);

if (!$student) {
    $data = [
        'status' => 404,
        'message' => 'Student not found for the supplied class and section.'
    ];
    header("HTTP/1.0 404 Not Found");
    echo json_encode($data);
    exit;
}

$installmentSql = "SELECT `id` FROM `fee_installments` WHERE `id` = ? AND `inst_id` = ? LIMIT 1";
$installmentStmt = mysqli_prepare($conn, $installmentSql);

if (!$installmentStmt) {
    $data = [
        'status' => 500,
        'message' => 'Failed to prepare installment query.'
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

foreach ($payments as $index => $payment) {
    $installmentId = $payment['installment_id'];
    mysqli_stmt_bind_param($installmentStmt, 'is', $installmentId, $instituteId);

    if (!mysqli_stmt_execute($installmentStmt)) {
        mysqli_stmt_close($installmentStmt);
        $data = [
            'status' => 500,
            'message' => 'Failed to validate fee installments.'
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($installmentStmt))) {
        mysqli_stmt_close($installmentStmt);
        $data = [
            'status' => 404,
            'message' => 'A fee installment was not found for this institute.',
            'invalidPaymentIndex' => $index,
            'installmentId' => $installmentId
        ];
        header("HTTP/1.0 404 Not Found");
        echo json_encode($data);
        exit;
    }
}
mysqli_stmt_close($installmentStmt);

$insertSql = "INSERT INTO `payments`
              (`inst_id`, `session_id`, `student_id`, `installment_id`, `payment_type`, `amount`, `payment_date`)
              VALUES (?, ?, ?, ?, ?, ?, ?)";
$insertStmt = mysqli_prepare($conn, $insertSql);

if (!$insertStmt) {
    $data = [
        'status' => 500,
        'message' => 'Failed to prepare payment insertion.'
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

mysqli_begin_transaction($conn);
$insertedPaymentIds = [];

try {
    foreach ($payments as $payment) {
        $installmentId = $payment['installment_id'];
        $amount = $payment['amount'];
        mysqli_stmt_bind_param(
            $insertStmt,
            'siiisds',
            $instituteId,
            $sessionId,
            $studentId,
            $installmentId,
            $paymentMethod,
            $amount,
            $paymentDate
        );

        if (!mysqli_stmt_execute($insertStmt)) {
            throw new RuntimeException('Failed to insert payment.');
        }

        $insertedPaymentIds[] = mysqli_insert_id($conn);
    }

    mysqli_commit($conn);
    mysqli_stmt_close($insertStmt);
} catch (Throwable $error) {
    mysqli_rollback($conn);
    mysqli_stmt_close($insertStmt);
    $data = [
        'status' => 500,
        'message' => 'Database error: ' . mysqli_error($conn)
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

$data = [
    'status' => 200,
    'message' => 'Payment record saved successfully.',
];
header("HTTP/1.0 200 OK");
echo json_encode($data);
