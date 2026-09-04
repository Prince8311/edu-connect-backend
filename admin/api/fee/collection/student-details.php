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

$feeResult = mysqli_stmt_get_result($feeStmt);

$normalize = static function ($value) {
    return strtoupper(preg_replace('/\s+/', '', trim((string)$value)));
};
$studentClass = $normalize($class);
$studentClassSection = $studentClass . $normalize($section);

$accountSql = "SELECT
                    iba.`account_name`,
                    iba.`account_no`,
                    iba.`beneficiary_name`,
                    iba.`ifsc_code`
               FROM `split_bank_accounts` sba
               INNER JOIN `institution_bank_accounts` iba
                   ON iba.`id` = sba.`account_id`
                  AND iba.`inst_id` = sba.`inst_id`
               WHERE sba.`inst_id` = ?
                 AND iba.`inst_id` = ?
                 AND iba.`status` = 1
                 AND (
                     FIND_IN_SET(?, REPLACE(UPPER(sba.`class_section`), ' ', '')) > 0
                     OR FIND_IN_SET(?, REPLACE(UPPER(sba.`class_section`), ' ', '')) > 0
                 )
               ORDER BY
                   CASE
                       WHEN FIND_IN_SET(?, REPLACE(UPPER(sba.`class_section`), ' ', '')) > 0 THEN 0
                       ELSE 1
                   END,
                   sba.`id` ASC
               LIMIT 1";
$accountStmt = mysqli_prepare($conn, $accountSql);

if (!$accountStmt) {
    mysqli_stmt_close($feeStmt);
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode(['status' => 500, 'message' => 'Failed to prepare bank account query.']);
    exit;
}

mysqli_stmt_bind_param(
    $accountStmt,
    'sssss',
    $instituteId,
    $instituteId,
    $studentClassSection,
    $studentClass,
    $studentClassSection
);

if (!mysqli_stmt_execute($accountStmt)) {
    mysqli_stmt_close($accountStmt);
    mysqli_stmt_close($feeStmt);
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode(['status' => 500, 'message' => 'Failed to fetch bank account details.']);
    exit;
}

$accountDetails = mysqli_fetch_assoc(mysqli_stmt_get_result($accountStmt));
mysqli_stmt_close($accountStmt);

if ($accountDetails) {
    $accountDetails = [
        'account_name' => $accountDetails['account_name'],
        'account_no' => $accountDetails['account_no'],
        'beneficiary_name' => $accountDetails['beneficiary_name'],
        'ifsc_code' => $accountDetails['ifsc_code']
    ];
} else {
    $accountDetails = null;
}

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
$formatCompactAmount = static function ($amount) {
    $amount = (float)$amount;
    $absoluteAmount = abs($amount);

    if ($absoluteAmount >= 100000) {
        return number_format($amount / 100000, 2, '.', '') . 'L';
    }

    if ($absoluteAmount >= 1000) {
        return number_format($amount / 1000, 2, '.', '') . 'K';
    }

    return number_format($amount, 2, '.', '');
};
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));

$installments = [];

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

$todayTimestamp = $today->getTimestamp();
$hasOverduePreviousDue = false;
$totalDue = 0.0;
$overdue = 0.0;

foreach ($installments as $index => &$installment) {
    $isPaid = $installment['status'] === 'Paid';
    $installment['isActive'] = false;
    $installment['message'] = null;

    $isIncludedInTotalDue = $index === 0
        || (
            $installments[$index - 1]['_sort_date'] !== PHP_INT_MAX
            && $todayTimestamp > $installments[$index - 1]['_sort_date']
        );

    if ($isIncludedInTotalDue) {
        $totalDue += (float)$installment['due_amount'];
    }

    if (
        $installment['due_amount'] !== '0.00'
        && $installment['_sort_date'] !== PHP_INT_MAX
        && $todayTimestamp > $installment['_sort_date']
    ) {
        $overdue += (float)$installment['due_amount'];
    }

    if (!$isPaid) {
        if ($index === 0) {
            // The first unpaid installment is always available for payment.
            $installment['isActive'] = true;
        } else {
            $previousInstallment = $installments[$index - 1];
            $previousDate = $previousInstallment['_sort_date'];

            if ($hasOverduePreviousDue) {
                $installment['message'] = 'Please clear the previous installment dues first.';
            } elseif ($previousDate === PHP_INT_MAX || $todayTimestamp <= $previousDate) {
                $installment['message'] = 'This installment can be paid after ' . $previousInstallment['scheduled_date'] . '.';
            } else {
                $installment['isActive'] = true;
            }
        }
    }

    if ($overdue > 0) {
        $hasOverduePreviousDue = true;
    }

}
unset($installment);

foreach ($installments as &$installment) {
    unset($installment['_sort_date']);
}
unset($installment);

$canSelectInstallment = false;

// Find the next installment whose own scheduled date has not passed yet.
foreach ($installments as $index => $installment) {
    $installmentDate = $parseInstallmentDate($installment['scheduled_date']);

    if ($installmentDate === false || $installmentDate->getTimestamp() < $todayTimestamp) {
        continue;
    }

    $hasPreviousOverdueDue = false;
    foreach (array_slice($installments, 0, $index) as $previousInstallment) {
        $previousDate = $parseInstallmentDate($previousInstallment['scheduled_date']);

        if (
            $previousInstallment['due_amount'] !== '0.00'
            && $previousDate !== false
            && $previousDate->getTimestamp() < $todayTimestamp
        ) {
            $hasPreviousOverdueDue = true;
            break;
        }
    }

    // A prior overdue balance can still be selected for payment. Otherwise,
    // the next installment can be selected only before it receives any payment.
    $canSelectInstallment = $hasPreviousOverdueDue
        || (float)$installment['paid_amount'] < 0.005;
    break;
}

header("HTTP/1.0 200 OK");
echo json_encode([
    'status' => 200,
    'message' => 'Student fee installments fetched.',
    'total_due' => $formatCompactAmount($totalDue),
    'overdue' => $formatCompactAmount($overdue),
    'canSelectInstallment' => $canSelectInstallment,
    'account_details' => $accountDetails,
    'installments' => $installments
]);
