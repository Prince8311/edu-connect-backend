<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

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
require __DIR__ . "/../../../../PHPMailer/Exception.php";
require __DIR__ . "/../../../../PHPMailer/PHPMailer.php";
require __DIR__ . "/../../../../PHPMailer/SMTP.php";
require __DIR__ . "/../../../../utils/payment-receipt.php";
require __DIR__ . "/../../../../utils/email-safety.php";
global $conn;
$instituteId = $authResult['inst_id'];

function sendGuardianPaymentReceiptEmail(
    string $email,
    string $studentName,
    string $institutionName,
    string $receiptNo,
    string $paymentDate,
    string $totalAmount,
    string $receiptPdf,
    string $logoPath
): void {
    $mail = new PHPMailer(true);

    try {
        $safeStudentName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');
        $safeInstitutionName = htmlspecialchars($institutionName, ENT_QUOTES, 'UTF-8');
        $safeReceiptNo = htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8');
        $safePaymentDate = htmlspecialchars($paymentDate, ENT_QUOTES, 'UTF-8');
        $safeTotalAmount = htmlspecialchars($totalAmount, ENT_QUOTES, 'UTF-8');

        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_MAIL');
        $mail->Password = getenv('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = getenv('SMTP_PORT');
        $mail->CharSet = 'UTF-8';

        $mail->isHTML(true);
        $mail->setFrom(getenv('SMTP_MAIL'), $institutionName);
        $mail->addAddress($email, 'Parent/Guardian');

        $logoHtml = '';
        if (is_file($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'educonnekt-logo', 'logo.png');
            $logoHtml = '<img src="cid:educonnekt-logo" alt="Edu Connekt" style="display:block;width:190px;max-width:100%;height:auto;">';
        }

        $mail->Subject = 'Payment received - Receipt ' . $receiptNo;
        $mail->Body = '<!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
            </head>
            <body style="margin:0;padding:0;background:#f3f8fb;font-family:Arial,sans-serif;color:#1d2d3b;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f8fb;padding:32px 12px;">
                    <tr>
                        <td align="center">
                            <table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 30px rgba(0,101,141,0.10);">
                                <tr><td style="height:7px;background:#00658d;"></td></tr>
                                <tr><td style="height:3px;background:#1da1f2;"></td></tr>
                                <tr>
                                    <td style="padding:28px 34px 16px;">' . $logoHtml . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 34px 30px;">
                                        <div style="display:inline-block;padding:7px 12px;border-radius:20px;background:#e3f8ee;color:#148052;font-size:12px;font-weight:bold;letter-spacing:.4px;">PAYMENT RECEIVED</div>
                                        <h1 style="margin:18px 0 10px;color:#00658d;font-size:27px;line-height:1.25;">Thank you for your payment</h1>
                                        <p style="margin:0;color:#5d707e;font-size:15px;line-height:1.7;">Dear Parent/Guardian,</p>
                                        <p style="margin:10px 0 22px;color:#5d707e;font-size:15px;line-height:1.7;">We have successfully received the fee payment for <strong style="color:#1d2d3b;">' . $safeStudentName . '</strong>. A detailed official receipt is attached to this email for your records.</p>

                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f0f9fe;border:1px solid #d8e7ef;border-radius:12px;">
                                            <tr>
                                                <td style="padding:18px 20px;border-bottom:1px solid #d8e7ef;color:#5d707e;font-size:13px;">Receipt number</td>
                                                <td align="right" style="padding:18px 20px;border-bottom:1px solid #d8e7ef;color:#00658d;font-size:14px;font-weight:bold;">' . $safeReceiptNo . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:18px 20px;border-bottom:1px solid #d8e7ef;color:#5d707e;font-size:13px;">Payment date</td>
                                                <td align="right" style="padding:18px 20px;border-bottom:1px solid #d8e7ef;color:#1d2d3b;font-size:14px;font-weight:bold;">' . $safePaymentDate . '</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:18px 20px;color:#5d707e;font-size:13px;">Amount received</td>
                                                <td align="right" style="padding:18px 20px;color:#1da1f2;font-size:18px;font-weight:bold;">INR ' . $safeTotalAmount . '</td>
                                            </tr>
                                        </table>

                                        <p style="margin:24px 0 0;color:#5d707e;font-size:13px;line-height:1.7;">Please retain the attached receipt for future reference. If you have any questions about this payment, contact the institution and quote the receipt number above.</p>
                                        <p style="margin:24px 0 0;color:#1d2d3b;font-size:14px;line-height:1.6;">Warm regards,<br><strong style="color:#00658d;">' . $safeInstitutionName . '</strong></p>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding:18px 30px;background:#00658d;color:#dff5ff;font-size:11px;line-height:1.6;">This is an automated payment confirmation sent securely through Edu Connekt.</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>';
        $mail->AltBody = 'Payment received for ' . $studentName
            . '. Receipt: ' . $receiptNo
            . '. Payment date: ' . $paymentDate
            . '. Amount received: INR ' . $totalAmount
            . '. The official receipt is attached.';
        $mail->addStringAttachment(
            $receiptPdf,
            'payment-receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $receiptNo) . '.pdf',
            'base64',
            'application/pdf'
        );
        $mail->send();
    } catch (PHPMailerException $error) {
        throw new RuntimeException('Failed to send payment receipt email: ' . $error->getMessage());
    }
}

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
    $amountInput = isset($payment['amount']) ? trim((string)$payment['amount']) : '';

    $amountValue = is_numeric($amountInput) ? (float)$amountInput : 0.0;
    $amount = is_finite($amountValue)
        ? number_format(round($amountValue, 2), 2, '.', '')
        : '';

    if (
        !ctype_digit($installmentIdInput)
        || (int)$installmentIdInput <= 0
        || !is_numeric($amountInput)
        || !is_finite($amountValue)
        || (float)$amount <= 0
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
    $sessionSql = "SELECT `id`, `session_name`
                   FROM `academic_sessions`
                   WHERE `id` = ? AND `inst_id` = ?
                   LIMIT 1";
    $sessionStmt = mysqli_prepare($conn, $sessionSql);
} else {
    $sessionSql = "SELECT `id`, `session_name`
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
$sessionName = trim((string)$session['session_name']);

$institutionSql = "SELECT `inst_name`, `phone`, `email`, `city`, `state`, `location`
                   FROM `institutions`
                   WHERE `inst_id` = ?
                   LIMIT 1";
$institutionStmt = mysqli_prepare($conn, $institutionSql);

if (!$institutionStmt) {
    $data = [
        'status' => 500,
        'message' => 'Failed to prepare institution query.'
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

mysqli_stmt_bind_param($institutionStmt, 's', $instituteId);
if (!mysqli_stmt_execute($institutionStmt)) {
    mysqli_stmt_close($institutionStmt);
    $data = [
        'status' => 500,
        'message' => 'Failed to fetch institution details.'
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

$institution = mysqli_fetch_assoc(mysqli_stmt_get_result($institutionStmt));
mysqli_stmt_close($institutionStmt);

if (!$institution) {
    $data = [
        'status' => 404,
        'message' => 'Institution not found.'
    ];
    header("HTTP/1.0 404 Not Found");
    echo json_encode($data);
    exit;
}

$studentSql = "SELECT
                    s.`id`,
                    s.`enrollment_id`,
                    student_first_name.`value` AS `first_name`,
                    student_middle_name.`value` AS `middle_name`,
                    student_last_name.`value` AS `last_name`,
                    guardian_email.`value` AS `guardian_email`
               FROM `students` s
               INNER JOIN `student_field_values` class_field
                   ON class_field.`student_id` = s.`id`
                   AND class_field.`inst_id` = s.`inst_id`
                   AND class_field.`section_id` = 1
                   AND class_field.`field_name` = 'Class / Standard'
                   AND class_field.`value` = ?
               INNER JOIN `student_field_values` section_field
                   ON section_field.`student_id` = s.`id`
                   AND section_field.`inst_id` = s.`inst_id`
                   AND section_field.`section_id` = 1
                   AND section_field.`field_name` = 'Section'
                   AND section_field.`value` = ?
               LEFT JOIN `student_field_values` student_first_name
                   ON student_first_name.`student_id` = s.`id`
                   AND student_first_name.`inst_id` = s.`inst_id`
                   AND student_first_name.`section_id` = 1
                   AND student_first_name.`field_name` = 'First Name'
               LEFT JOIN `student_field_values` student_middle_name
                   ON student_middle_name.`student_id` = s.`id`
                   AND student_middle_name.`inst_id` = s.`inst_id`
                   AND student_middle_name.`section_id` = 1
                   AND student_middle_name.`field_name` = 'Middle Name'
               LEFT JOIN `student_field_values` student_last_name
                   ON student_last_name.`student_id` = s.`id`
                   AND student_last_name.`inst_id` = s.`inst_id`
                   AND student_last_name.`section_id` = 1
                   AND student_last_name.`field_name` = 'Last Name'
               LEFT JOIN `student_field_values` guardian_email
                   ON guardian_email.`student_id` = s.`id`
                   AND guardian_email.`inst_id` = s.`inst_id`
                   AND guardian_email.`section_id` = 2
                   AND guardian_email.`field_name` = 'Email'
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

$guardianEmail = trim((string)($student['guardian_email'] ?? ''));
$studentName = trim(implode(' ', array_filter([
    $student['first_name'] ?? '',
    $student['middle_name'] ?? '',
    $student['last_name'] ?? ''
])));
$studentName = $studentName !== '' ? $studentName : 'Student';

$installmentSql = "SELECT
                        fi.`id`,
                        fi.`configuration_id`,
                        fi.`scheduled date` AS `scheduled_date`,
                        fc.`receipt_prefix`,
                        fc.`fee_name`
                   FROM `fee_installments` fi
                   INNER JOIN `fee_configurations` fc
                       ON fc.`id` = fi.`configuration_id`
                       AND fc.`inst_id` = fi.`inst_id`
                   WHERE fi.`id` = ? AND fi.`inst_id` = ?
                   LIMIT 1";
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

$configurationId = null;
$receiptPrefix = '';

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

    $installment = mysqli_fetch_assoc(mysqli_stmt_get_result($installmentStmt));

    if (!$installment) {
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

    if ($configurationId === null) {
        $configurationId = (int)$installment['configuration_id'];
        $receiptPrefix = trim((string)$installment['receipt_prefix']);
    } elseif ($configurationId !== (int)$installment['configuration_id']) {
        mysqli_stmt_close($installmentStmt);
        $data = [
            'status' => 400,
            'message' => 'All payment installments must belong to the same fee configuration.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $payments[$index]['fee_name'] = trim((string)$installment['fee_name']);
    $payments[$index]['scheduled_date'] = trim((string)$installment['scheduled_date']);
}
mysqli_stmt_close($installmentStmt);

$receiptNo = $receiptPrefix . random_int(100000, 999999);
$totalAmount = array_sum(array_map(static function ($payment) {
    return (float)$payment['amount'];
}, $payments));
$formattedTotalAmount = number_format($totalAmount, 2, '.', ',');
$institutionAddress = implode(', ', array_filter([
    trim((string)($institution['location'] ?? '')),
    trim((string)($institution['city'] ?? '')),
    trim((string)($institution['state'] ?? ''))
]));
$institutionName = trim((string)$institution['inst_name']);
$institutionName = $institutionName !== '' ? $institutionName : 'Institution';
$logoPath = __DIR__ . '/../../../../images/logo.png';

try {
    $receiptPdf = buildPaymentReceiptPdf([
        'receipt_no' => $receiptNo,
        'payment_date' => $paymentDate,
        'payment_method' => $paymentMethod,
        'student_name' => $studentName,
        'student_id' => (string)($student['enrollment_id'] ?: $studentIdInput),
        'class' => $class,
        'section' => $section,
        'session_name' => $sessionName,
        'institution_name' => $institutionName,
        'institution_phone' => trim((string)($institution['phone'] ?? '')),
        'institution_email' => trim((string)($institution['email'] ?? '')),
        'institution_address' => $institutionAddress,
        'logo_path' => $logoPath,
        'payments' => $payments,
        'total_amount' => $totalAmount
    ]);
} catch (Throwable $error) {
    $data = [
        'status' => 500,
        'message' => 'Failed to generate payment receipt.'
    ];
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode($data);
    exit;
}

$insertSql = "INSERT INTO `payments`
              (`inst_id`, `session_id`, `student_id`, `installment_id`, `receipt_no`, `payment_type`, `amount`, `payment_date`)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
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
            'siiissss',
            $instituteId,
            $sessionId,
            $studentId,
            $installmentId,
            $receiptNo,
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

$emailSent = false;
$emailMessage = 'Guardian email is not available or is invalid.';

if ($guardianEmail !== '' && filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
    $emailReservation = reserveEmailSend($conn, $guardianEmail, 'payment_receipt');

    if (!$emailReservation['allowed']) {
        $emailMessage = 'Payment was saved, but the receipt email was deferred by the email safety limit.';
    } else {
        try {
            sendGuardianPaymentReceiptEmail(
                $guardianEmail,
                $studentName,
                $institutionName,
                $receiptNo,
                $paymentDate,
                $formattedTotalAmount,
                $receiptPdf,
                $logoPath
            );
            $emailSent = true;
            $emailMessage = 'Payment receipt emailed to the guardian successfully.';
            completeEmailSendReservation($conn, (int)$emailReservation['event_id'], true);
        } catch (Throwable $error) {
            completeEmailSendReservation($conn, (int)$emailReservation['event_id'], false);
            $emailMessage = 'Payment was saved, but the receipt email could not be sent.';
        }
    }
}

$data = [
    'status' => 200,
    'message' => 'Payment record saved successfully.',
    'receiptNo' => $receiptNo,
    'emailSent' => $emailSent,
    'emailMessage' => $emailMessage
];
header("HTTP/1.0 200 OK");
echo json_encode($data);
