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

if ($requestMethod !== 'GET') {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ]);
    exit;
}

require __DIR__ . "/../../../../_db-connect.php";
global $conn;

$instituteId = $authResult['inst_id'];
$studentId = trim((string)($_GET['studentId'] ?? ''));
$class = trim((string)($_GET['class'] ?? ''));
$section = trim((string)($_GET['section'] ?? ''));
$requestedSessionId = trim((string)($_GET['sessionId'] ?? $_GET['sessionid'] ?? $_GET['session'] ?? ''));

if (!ctype_digit($studentId) || (int)$studentId <= 0 || $class === '' || $section === '') {
    header("HTTP/1.0 422 Unprocessable Entity");
    echo json_encode([
        'status' => 422,
        'message' => 'studentId, class and section are required.'
    ]);
    exit;
}

if ($requestedSessionId !== '' && (!ctype_digit($requestedSessionId) || (int)$requestedSessionId <= 0)) {
    header("HTTP/1.0 422 Unprocessable Entity");
    echo json_encode([
        'status' => 422,
        'message' => 'Session id must be a positive integer.'
    ]);
    exit;
}

if ($requestedSessionId !== '') {
    $sessionSql = "SELECT `id`, `start_date`, `end_date`, `status`
                   FROM `academic_sessions`
                   WHERE `id` = ? AND `inst_id` = ?
                   LIMIT 1";
    $sessionStmt = mysqli_prepare($conn, $sessionSql);
} else {
    $sessionSql = "SELECT `id`, `start_date`, `end_date`, `status`
                   FROM `academic_sessions`
                   WHERE `inst_id` = ? AND `status` = 'Ongoing'
                   ORDER BY `start_date` DESC, `id` DESC
                   LIMIT 1";
    $sessionStmt = mysqli_prepare($conn, $sessionSql);
}

if (!$sessionStmt) {
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode(['status' => 500, 'message' => 'Failed to fetch academic session.']);
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
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode(['status' => 500, 'message' => 'Failed to fetch academic session.']);
    exit;
}

$session = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionStmt));
mysqli_stmt_close($sessionStmt);

if (!$session) {
    header("HTTP/1.0 404 Not Found");
    echo json_encode([
        'status' => 404,
        'message' => $requestedSessionId === '' ? 'No ongoing academic session found.' : 'Academic session not found.'
    ]);
    exit;
}

$studentId = (int)$studentId;
$studentSql = "SELECT
                    s.`id`,
                    MAX(CASE WHEN sfv.`field_name` = 'Date of Admission' THEN sfv.`value` END) AS `date_of_admission`,
                    MAX(CASE WHEN sfv.`field_name` = 'Class / Standard' THEN sfv.`value` END) AS `class_name`,
                    MAX(CASE WHEN sfv.`field_name` = 'Section' THEN sfv.`value` END) AS `section_name`
                FROM `students` s
                LEFT JOIN `student_field_values` sfv
                    ON sfv.`student_id` = s.`id` AND sfv.`inst_id` = s.`inst_id`
                WHERE s.`id` = ? AND s.`inst_id` = ?
                GROUP BY s.`id`
                HAVING `class_name` = ? AND `section_name` = ?
                LIMIT 1";
$studentStmt = mysqli_prepare($conn, $studentSql);

if (!$studentStmt) {
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode(['status' => 500, 'message' => 'Failed to prepare student query.']);
    exit;
}

mysqli_stmt_bind_param($studentStmt, 'isss', $studentId, $instituteId, $class, $section);
mysqli_stmt_execute($studentStmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($studentStmt));
mysqli_stmt_close($studentStmt);

if (!$student) {
    header("HTTP/1.0 404 Not Found");
    echo json_encode([
        'status' => 404,
        'message' => 'Student not found for the supplied class and section.'
    ]);
    exit;
}

$parseSessionDate = static function ($date) {
    $dateObject = DateTimeImmutable::createFromFormat('!j F, Y', trim((string)$date));
    $errors = DateTimeImmutable::getLastErrors();

    if ($dateObject === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return false;
    }

    return $dateObject;
};

$sessionStartDate = $parseSessionDate($session['start_date']);
$sessionEndDate = $parseSessionDate($session['end_date']);
$admissionDate = !empty($student['date_of_admission']) ? $parseSessionDate($student['date_of_admission']) : false;

if ($sessionStartDate === false || $sessionEndDate === false) {
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode(['status' => 500, 'message' => 'Selected academic session has invalid dates.']);
    exit;
}

$studentType = null;
if ($admissionDate !== false) {
    if ($admissionDate < $sessionStartDate) {
        $studentType = 'Existing Students';
    } elseif ($admissionDate <= $sessionEndDate) {
        $studentType = strtolower(trim((string)$session['status'])) === 'concluded'
            ? 'Existing Students'
            : 'New Students';
    }
}

$sessionId = (int)$session['id'];
$feeSql = "SELECT
                fc.`id` AS `configuration_id`,
                fc.`fee_name`,
                fc.`type` AS `fee_type`,
                fc.`classes`,
                fc.`applied_for`,
                fc.`tax` AS `tax_percentage`,
                fi.`id` AS `installment_id`,
                fi.`scheduled date` AS `scheduled_date`,
                fi.`amount` AS `base_amount`,
                COALESCE(SUM(p.`amount`), 0) AS `paid_amount`
            FROM `fee_configurations` fc
            INNER JOIN `fee_installments` fi
                ON fi.`configuration_id` = fc.`id` AND fi.`inst_id` = fc.`inst_id`
            LEFT JOIN `payments` p
                ON p.`inst_id` = fc.`inst_id`
                AND p.`student_id` = ?
                AND p.`session_id` = ?
                AND p.`installment_id` = fi.`id`
            WHERE fc.`inst_id` = ?
            GROUP BY
                fc.`id`, fc.`fee_name`, fc.`type`, fc.`classes`, fc.`applied_for`, fc.`tax`,
                fi.`id`, fi.`scheduled date`, fi.`amount`
            ORDER BY fi.`id` ASC";
$feeStmt = mysqli_prepare($conn, $feeSql);

if (!$feeStmt) {
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode(['status' => 500, 'message' => 'Failed to prepare fee query.']);
    exit;
}

mysqli_stmt_bind_param($feeStmt, 'iis', $studentId, $sessionId, $instituteId);

if (!mysqli_stmt_execute($feeStmt)) {
    mysqli_stmt_close($feeStmt);
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode(['status' => 500, 'message' => 'Failed to fetch fee installments.']);
    exit;
}

$normalize = static function ($value) {
    return strtoupper(preg_replace('/\s+/', '', trim((string)$value)));
};
$studentClass = $normalize($class);
$studentClassSection = $studentClass . $normalize($section);
$parseClasses = static function ($classes) {
    $classes = trim((string)$classes);
    $decodedClasses = json_decode($classes, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedClasses)) {
        return $decodedClasses;
    }

    return explode(',', $classes);
};
$parseInstallmentDate = static function ($scheduledDate) use ($sessionStartDate, $sessionEndDate) {
    $scheduledDate = trim((string)$scheduledDate);
    $dateObject = DateTimeImmutable::createFromFormat('!j F Y', $scheduledDate . ' ' . $sessionStartDate->format('Y'));
    $errors = DateTimeImmutable::getLastErrors();

    if ($dateObject === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return false;
    }

    // A session crossing into a new calendar year uses the end year for earlier months.
    if ($sessionEndDate->format('Y') !== $sessionStartDate->format('Y') && $dateObject < $sessionStartDate) {
        $dateObject = DateTimeImmutable::createFromFormat('!j F Y', $scheduledDate . ' ' . $sessionEndDate->format('Y'));
    }

    return $dateObject;
};
$formatAmount = static function ($amount) {
    return number_format(round((float)$amount, 2), 2, '.', '');
};
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));

$installments = [];
$feeResult = mysqli_stmt_get_result($feeStmt);

while ($fee = mysqli_fetch_assoc($feeResult)) {
    $classMatches = false;
    foreach ($parseClasses($fee['classes']) as $configuredClass) {
        $configuredClass = $normalize($configuredClass);
        if ($configuredClass === $studentClass || $configuredClass === $studentClassSection) {
            $classMatches = true;
            break;
        }
    }

    $appliesToStudent = $fee['applied_for'] === 'Applicable for all'
        || ($studentType !== null && $fee['applied_for'] === $studentType);

    if (!$classMatches || !$appliesToStudent) {
        continue;
    }

    $baseAmount = (float)$fee['base_amount'];
    $taxPercentage = (float)$fee['tax_percentage'];
    $totalAmount = round($baseAmount * (1 + ($taxPercentage / 100)), 2);
    $paidAmount = round((float)$fee['paid_amount'], 2);
    $dueAmount = max(0, round($totalAmount - $paidAmount, 2));
    $installmentDate = $parseInstallmentDate($fee['scheduled_date']);

    if ($paidAmount < 0.005) {
        $status = 'Unpaid';
    } elseif (abs($paidAmount - $totalAmount) < 0.005) {
        $status = 'Paid';
    } elseif ($dueAmount > 0 && $installmentDate !== false && $installmentDate < $today) {
        $status = 'Overdue';
    } else {
        $status = 'Partially Paid';
    }

    $installments[] = [
        'configuration_id' => (int)$fee['configuration_id'],
        'installment_id' => (int)$fee['installment_id'],
        'fee_name' => $fee['fee_name'],
        'fee_type' => $fee['fee_type'],
        'scheduled_date' => $fee['scheduled_date'],
        'base_amount' => $formatAmount($baseAmount),
        'tax_percentage' => $formatAmount($taxPercentage),
        'tax_amount' => $formatAmount($totalAmount - $baseAmount),
        'amount' => $formatAmount($totalAmount),
        'paid_amount' => $formatAmount($paidAmount),
        'due_amount' => $formatAmount($dueAmount),
        'status' => $status,
        '_sort_date' => $installmentDate === false ? PHP_INT_MAX : $installmentDate->getTimestamp()
    ];
}

mysqli_stmt_close($feeStmt);

usort($installments, static function ($first, $second) {
    return $first['_sort_date'] <=> $second['_sort_date']
        ?: $first['installment_id'] <=> $second['installment_id'];
});

foreach ($installments as &$installment) {
    unset($installment['_sort_date']);
}
unset($installment);

header("HTTP/1.0 200 OK");
echo json_encode([
    'status' => 200,
    'message' => 'Student fee installments fetched.',
    'session_id' => $sessionId,
    'student_id' => $studentId,
    'class' => $class,
    'section' => $section,
    'installments' => $installments
]);
