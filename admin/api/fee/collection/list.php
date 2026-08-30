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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../../_db-connect.php";
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

    $requestedSessionId = isset($_GET['session'])
        ? trim((string)$_GET['session'])
        : '';

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

        if ($sessionStmt) {
            $requestedSessionId = (int)$requestedSessionId;
            mysqli_stmt_bind_param($sessionStmt, 'is', $requestedSessionId, $instituteId);
        }
    } else {
        $sessionSql = "SELECT `id`, `start_date`, `end_date`, `status`
                       FROM `academic_sessions`
                       WHERE `inst_id` = ? AND `status` = 'Ongoing'
                       ORDER BY `start_date` DESC, `id` DESC
                       LIMIT 1";
        $sessionStmt = mysqli_prepare($conn, $sessionSql);

        if ($sessionStmt) {
            mysqli_stmt_bind_param($sessionStmt, 's', $instituteId);
        }
    }

    if (!$sessionStmt) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare academic session query.'
        ]);
        exit;
    }

    if (!mysqli_stmt_execute($sessionStmt)) {
        mysqli_stmt_close($sessionStmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch academic session.'
        ]);
        exit;
    }

    $sessionResult = mysqli_stmt_get_result($sessionStmt);
    $session = mysqli_fetch_assoc($sessionResult);
    mysqli_stmt_close($sessionStmt);

    if (!$session) {
        header("HTTP/1.0 404 Not Found");
        echo json_encode([
            'status' => 404,
            'message' => $requestedSessionId !== ''
                ? 'Academic session not found.'
                : 'No ongoing academic session found.'
        ]);
        exit;
    }

    $sessionId = (int)$session['id'];
    $sessionStartTimestamp = strtotime($session['start_date']);
    $sessionEndTimestamp = strtotime($session['end_date']);
    $sessionStatus = strtolower(trim((string)$session['status']));

    if ($sessionStartTimestamp === false || $sessionEndTimestamp === false) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Selected academic session has invalid start or end dates.'
        ]);
        exit;
    }

    if (!isset($_GET['levelId'])) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'status' => 400,
            'message' => 'Academic level id required.'
        ]);
        exit;
    }

    $levelId = trim((string)$_GET['levelId']);

    if ($levelId === '' || !ctype_digit($levelId) || (int)$levelId <= 0) {
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode([
            'status' => 422,
            'message' => 'Academic level id must be a positive integer.'
        ]);
        exit;
    }

    $sql = "SELECT `id`, `class`, `sections`
            FROM `academic_class_sections`
            WHERE `inst_id` = ? AND `level_id` = ?
            ORDER BY `class` ASC";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare class and section query.'
        ]);
        exit;
    }

    $levelId = (int)$levelId;
    mysqli_stmt_bind_param($stmt, 'si', $instituteId, $levelId);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch classes and sections.'
        ]);
        exit;
    }

    $result = mysqli_stmt_get_result($stmt);
    $classes = [];
    $classSectionIndexes = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $sections = [];

        if (!empty($row['sections'])) {
            $sectionNames = array_values(array_filter(
                array_map('trim', explode(',', $row['sections'])),
                static function ($section) {
                    return $section !== '';
                }
            ));

            foreach ($sectionNames as $sectionName) {
                $sections[] = [
                    'section' => $sectionName,
                    'total_applied' => 0.0,
                    'total_due' => 0.0,
                    'total_paid' => 0.0,
                    'students' => []
                ];
            }
        }

        $classIndex = count($classes);
        $classes[] = [
            'id' => (int)$row['id'],
            'class' => $row['class'],
            'sections' => $sections
        ];

        foreach ($sections as $sectionIndex => $section) {
            $classSectionIndexes[(string)$row['class']][$section['section']] = [
                'class_index' => $classIndex,
                'section_index' => $sectionIndex
            ];
        }
    }

    mysqli_stmt_close($stmt);

    $studentSql = "SELECT
                        s.`id` AS `student_id`,
                        s.`enrollment_id`,
                        MAX(CASE WHEN sfv.`field_name` = 'First Name' THEN sfv.`value` END) AS `first_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Middle Name' THEN sfv.`value` END) AS `middle_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Last Name' THEN sfv.`value` END) AS `last_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Contact No.' THEN sfv.`value` END) AS `contact_no`,
                        MAX(CASE WHEN sfv.`field_name` = 'Date of Admission' THEN sfv.`value` END) AS `date_of_admission`,
                        class_field.`value` AS `class_name`,
                        section_field.`value` AS `section_name`
                    FROM `students` s
                    INNER JOIN `student_field_values` class_field
                        ON class_field.`student_id` = s.`id`
                        AND class_field.`inst_id` = s.`inst_id`
                        AND class_field.`field_name` = 'Class / Standard'
                    INNER JOIN `student_field_values` section_field
                        ON section_field.`student_id` = s.`id`
                        AND section_field.`inst_id` = s.`inst_id`
                        AND section_field.`section_id` = class_field.`section_id`
                        AND section_field.`field_name` = 'Section'
                    LEFT JOIN `student_field_values` sfv
                        ON sfv.`student_id` = s.`id`
                        AND sfv.`inst_id` = s.`inst_id`
                        AND sfv.`section_id` = class_field.`section_id`
                        AND sfv.`field_name` IN (
                            'First Name',
                            'Middle Name',
                            'Last Name',
                            'Contact No.',
                            'Date of Admission'
                        )
                    WHERE s.`inst_id` = ?
                        AND s.`status` = 1
                    GROUP BY
                        s.`id`,
                        s.`enrollment_id`,
                        class_field.`value`,
                        section_field.`value`";
    $studentStmt = mysqli_prepare($conn, $studentSql);

    if (!$studentStmt) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare student query.'
        ]);
        exit;
    }

    mysqli_stmt_bind_param($studentStmt, 's', $instituteId);

    if (!mysqli_stmt_execute($studentStmt)) {
        mysqli_stmt_close($studentStmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch students.'
        ]);
        exit;
    }

    $studentResult = mysqli_stmt_get_result($studentStmt);
    $feeSql = "SELECT
                    fc.`id`,
                    fc.`classes`,
                    fc.`applied_for`,
                    fc.`tax`,
                    fc.`created_at`,
                    COALESCE(SUM(fi.`amount`), 0) AS `installment_total`
                FROM `fee_configurations` fc
                LEFT JOIN `fee_installments` fi
                    ON fi.`configuration_id` = fc.`id`
                    AND fi.`inst_id` = fc.`inst_id`
                WHERE fc.`inst_id` = ?
                GROUP BY
                    fc.`id`,
                    fc.`classes`,
                    fc.`applied_for`,
                    fc.`tax`,
                    fc.`created_at`";
    $feeStmt = mysqli_prepare($conn, $feeSql);

    if (!$feeStmt) {
        mysqli_stmt_close($studentStmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare fee query.'
        ]);
        exit;
    }

    mysqli_stmt_bind_param($feeStmt, 's', $instituteId);

    if (!mysqli_stmt_execute($feeStmt)) {
        mysqli_stmt_close($feeStmt);
        mysqli_stmt_close($studentStmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch fee configurations.'
        ]);
        exit;
    }

    $feeResult = mysqli_stmt_get_result($feeStmt);
    $feeConfigurations = [];

    while ($fee = mysqli_fetch_assoc($feeResult)) {
        $configuredClasses = array_values(array_filter(
            array_map('trim', explode(',', $fee['classes'])),
            static function ($class) {
                return $class !== '';
            }
        ));

        $feeConfigurations[] = [
            'classes' => $configuredClasses,
            'applied_for' => $fee['applied_for'],
            'tax' => (float)$fee['tax'],
            'created_at' => $fee['created_at'],
            'installment_total' => (float)$fee['installment_total']
        ];
    }

    mysqli_stmt_close($feeStmt);

    $paymentSql = "SELECT `student_id`, COALESCE(SUM(`amount`), 0) AS `paid_total`
                   FROM `payments`
                   WHERE `inst_id` = ? AND `session_id` = ?
                   GROUP BY `student_id`";
    $paymentStmt = mysqli_prepare($conn, $paymentSql);

    if (!$paymentStmt) {
        mysqli_stmt_close($studentStmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare payment query.'
        ]);
        exit;
    }

    mysqli_stmt_bind_param($paymentStmt, 'si', $instituteId, $sessionId);

    if (!mysqli_stmt_execute($paymentStmt)) {
        mysqli_stmt_close($paymentStmt);
        mysqli_stmt_close($studentStmt);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch payments.'
        ]);
        exit;
    }

    $paymentResult = mysqli_stmt_get_result($paymentStmt);
    $studentPayments = [];

    while ($payment = mysqli_fetch_assoc($paymentResult)) {
        $studentPayments[(int)$payment['student_id']] = (float)$payment['paid_total'];
    }

    mysqli_stmt_close($paymentStmt);

    $formatAmount = static function ($amount) {
        $amount = (float)$amount;
        $absoluteAmount = abs($amount);

        if ($absoluteAmount < 0.005) {
            return '0.00';
        }

        $divisor = 1;
        $suffix = '';

        if ($absoluteAmount >= 100000) {
            $divisor = 100000;
            $suffix = 'L';
        } elseif ($absoluteAmount >= 1000) {
            $divisor = 1000;
            $suffix = 'K';
        }

        $formattedAmount = number_format($amount / $divisor, 2, '.', '');
        $formattedAmount = rtrim(rtrim($formattedAmount, '0'), '.');

        return $formattedAmount . $suffix;
    };

    while ($student = mysqli_fetch_assoc($studentResult)) {
        $className = trim((string)$student['class_name']);
        $sectionName = trim((string)$student['section_name']);

        if (!isset($classSectionIndexes[$className][$sectionName])) {
            continue;
        }

        $indexes = $classSectionIndexes[$className][$sectionName];
        $dueAmount = 0.0;
        $paidAmount = $studentPayments[(int)$student['student_id']] ?? 0.0;
        $studentClass = strtoupper(preg_replace('/\s+/', '', $className));
        $studentClassSection = $studentClass . strtoupper(preg_replace('/\s+/', '', $sectionName));
        $admissionTimestamp = !empty($student['date_of_admission'])
            ? strtotime($student['date_of_admission'])
            : false;

        // Students admitted after the selected session ends do not belong to this collection list.
        if ($admissionTimestamp !== false && $admissionTimestamp > $sessionEndTimestamp) {
            continue;
        }

        foreach ($feeConfigurations as $feeConfiguration) {
            $classMatches = false;

            foreach ($feeConfiguration['classes'] as $configuredClass) {
                $configuredClass = strtoupper(preg_replace('/\s+/', '', $configuredClass));

                if ($configuredClass === $studentClass || $configuredClass === $studentClassSection) {
                    $classMatches = true;
                    break;
                }
            }

            if (!$classMatches) {
                continue;
            }

            $appliesToStudent = $feeConfiguration['applied_for'] === 'Applicable for all';

            if (!$appliesToStudent && $admissionTimestamp !== false) {
                $studentType = null;

                if ($admissionTimestamp < $sessionStartTimestamp) {
                    $studentType = 'Existing Students';
                } elseif ($admissionTimestamp <= $sessionEndTimestamp) {
                    if (in_array($sessionStatus, ['ongoing', 'upcoming'], true)) {
                        $studentType = 'New Students';
                    } elseif ($sessionStatus === 'concluded') {
                        $studentType = 'Existing Students';
                    }
                }

                $appliesToStudent = $studentType !== null
                    && $feeConfiguration['applied_for'] === $studentType;
            }

            if ($appliesToStudent) {
                $dueAmount += $feeConfiguration['installment_total']
                    * (1 + ($feeConfiguration['tax'] / 100));
            }
        }

        $nameParts = array_values(array_filter(
            [
                trim((string)$student['first_name']),
                trim((string)$student['middle_name']),
                trim((string)$student['last_name'])
            ],
            static function ($namePart) {
                return $namePart !== '';
            }
        ));

        $classes[$indexes['class_index']]['sections'][$indexes['section_index']]['total_applied'] += $dueAmount;
        $classes[$indexes['class_index']]['sections'][$indexes['section_index']]['total_due'] += $dueAmount;
        $classes[$indexes['class_index']]['sections'][$indexes['section_index']]['total_paid'] += $paidAmount;
        $classes[$indexes['class_index']]['sections'][$indexes['section_index']]['students'][] = [
            'student_id' => (int)$student['student_id'],
            'enrollment_id' => $student['enrollment_id'],
            'student_name' => implode(' ', $nameParts),
            'contact_no' => $student['contact_no'],
            'date_of_admission' => $student['date_of_admission'],
            'due_amount' => $formatAmount(round($dueAmount, 2)),
            'paid_amount' => $formatAmount($paidAmount)
        ];
    }

    mysqli_stmt_close($studentStmt);

    foreach ($classes as &$class) {
        foreach ($class['sections'] as &$section) {
            $section['total_applied'] = $formatAmount(round($section['total_applied'], 2));
            $section['total_due'] = $formatAmount(round($section['total_due'], 2));
            $section['total_paid'] = $formatAmount(round($section['total_paid'], 2));
        }
        unset($section);

        $class['sections'] = array_values(array_filter(
            $class['sections'],
            static function ($section) {
                return !empty($section['students']);
            }
        ));
    }
    unset($class);

    $classes = array_values(array_filter(
        $classes,
        static function ($class) {
            return !empty($class['sections']);
        }
    ));

    header("HTTP/1.0 200 OK");
    echo json_encode([
        'status' => 200,
        'message' => 'Students fetched.',
        'classes' => $classes
    ]);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod .
            ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
