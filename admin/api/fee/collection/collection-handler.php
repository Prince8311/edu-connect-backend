<?php

// Shared request handler for the collection summary and student-list endpoints.

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
    $debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';

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

    $parseStoredDate = static function ($date) {
        $date = trim((string)$date);
        $dateObject = DateTime::createFromFormat('!j F, Y', $date);
        $dateErrors = DateTime::getLastErrors();

        if (
            $dateObject === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            return false;
        }

        return $dateObject->getTimestamp();
    };

    $sessionId = (int)$session['id'];
    $sessionStartTimestamp = $parseStoredDate($session['start_date']);
    $sessionEndTimestamp = $parseStoredDate($session['end_date']);
    $sessionStatus = strtolower(trim((string)$session['status']));

    if ($sessionStartTimestamp === false || $sessionEndTimestamp === false) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Selected academic session has invalid start or end dates.'
        ]);
        exit;
    }

    $studentsOnly = !empty($collectionStudentListMode);
    $requestedClass = $studentsOnly ? trim((string)($_GET['class'] ?? '')) : '';
    $requestedSection = $studentsOnly ? trim((string)($_GET['section'] ?? '')) : '';

    if ($studentsOnly && ($requestedClass === '' || $requestedSection === '')) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'status' => 400,
            'message' => 'Class and section are required.'
        ]);
        exit;
    }

    if (!$studentsOnly && !isset($_GET['levelId'])) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'status' => 400,
            'message' => 'Academic level id required.'
        ]);
        exit;
    }

    $levelId = $studentsOnly ? null : trim((string)$_GET['levelId']);

    if (!$studentsOnly && ($levelId === '' || !ctype_digit($levelId) || (int)$levelId <= 0)) {
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode([
            'status' => 422,
            'message' => 'Academic level id must be a positive integer.'
        ]);
        exit;
    }

    $limit = isset($_GET['limit']) && ctype_digit((string)$_GET['limit'])
        ? max(1, min((int)$_GET['limit'], 100))
        : 10;
    $page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) && (int)$_GET['page'] > 0
        ? (int)$_GET['page']
        : 1;
    $offset = ($page - 1) * $limit;

    $sql = "SELECT `id`, `class`, `sections`
            FROM `academic_class_sections`
            WHERE `inst_id` = ?";

    if (!$studentsOnly) {
        $sql .= " AND `level_id` = ?";
    }

    $sql .= " ORDER BY `class` ASC";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare class and section query.'
        ]);
        exit;
    }

    if ($studentsOnly) {
        mysqli_stmt_bind_param($stmt, 's', $instituteId);
    } else {
        $levelId = (int)$levelId;
        mysqli_stmt_bind_param($stmt, 'si', $instituteId, $levelId);
    }

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
                    // Used internally to omit sections with no students from the response.
                    'student_count' => 0
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

    if ($studentsOnly && !isset($classSectionIndexes[$requestedClass][$requestedSection])) {
        header("HTTP/1.0 404 Not Found");
        echo json_encode([
            'status' => 404,
            'message' => 'Class and section not found.'
        ]);
        exit;
    }

    $studentSql = "SELECT
                        s.`id` AS `student_id`,
                        s.`enrollment_id`,
                        MAX(CASE WHEN sfv.`field_name` = 'First Name' THEN sfv.`value` END) AS `first_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Middle Name' THEN sfv.`value` END) AS `middle_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Last Name' THEN sfv.`value` END) AS `last_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Contact No.' THEN sfv.`value` END) AS `contact_no`,
                        MAX(CASE WHEN sfv.`field_name` = 'Date of Admission' THEN sfv.`value` END) AS `date_of_admission`,
                        MAX(CASE WHEN sfv.`field_name` = 'Class / Standard' THEN sfv.`value` END) AS `class_name`,
                        MAX(CASE WHEN sfv.`field_name` = 'Section' THEN sfv.`value` END) AS `section_name`
                    FROM `academic_class_sections` acs
                    INNER JOIN `student_field_values` sfv
                        ON sfv.`section_id` = acs.`id`
                        AND sfv.`inst_id` = acs.`inst_id`
                    INNER JOIN `students` s
                        ON s.`id` = sfv.`student_id`
                        AND s.`inst_id` = sfv.`inst_id`
                    WHERE acs.`inst_id` = ?";

    if (!$studentsOnly) {
        $studentSql .= " AND acs.`level_id` = ?";
    }

    $studentSql .= " GROUP BY s.`id`, s.`enrollment_id`
                     HAVING class_name IS NOT NULL AND section_name IS NOT NULL";

    if ($studentsOnly) {
        $studentSql .= " AND class_name = ? AND section_name = ?
                         AND (
                             date_of_admission IS NULL
                             OR STR_TO_DATE(date_of_admission, '%e %M, %Y') IS NULL
                             OR STR_TO_DATE(date_of_admission, '%e %M, %Y') <= STR_TO_DATE(?, '%e %M, %Y')
                         )
                         ORDER BY s.`id` DESC
                         LIMIT ? OFFSET ?";
    }
    $studentStmt = mysqli_prepare($conn, $studentSql);

    if (!$studentStmt) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to prepare student query.'
        ]);
        exit;
    }

    $totalStudents = 0;
    if ($studentsOnly) {
        $studentCountSql = "SELECT COUNT(*) AS `total` FROM (
                                SELECT
                                    s.`id`,
                                    MAX(CASE WHEN sfv.`field_name` = 'Class / Standard' THEN sfv.`value` END) AS `class_name`,
                                    MAX(CASE WHEN sfv.`field_name` = 'Section' THEN sfv.`value` END) AS `section_name`,
                                    MAX(CASE WHEN sfv.`field_name` = 'Date of Admission' THEN sfv.`value` END) AS `date_of_admission`
                                FROM `academic_class_sections` acs
                                INNER JOIN `student_field_values` sfv
                                    ON sfv.`section_id` = acs.`id`
                                    AND sfv.`inst_id` = acs.`inst_id`
                                INNER JOIN `students` s
                                    ON s.`id` = sfv.`student_id`
                                    AND s.`inst_id` = sfv.`inst_id`
                                WHERE acs.`inst_id` = ?
                                GROUP BY s.`id`, s.`enrollment_id`
                                HAVING class_name = ? AND section_name = ?
                                    AND (
                                        date_of_admission IS NULL
                                        OR STR_TO_DATE(date_of_admission, '%e %M, %Y') IS NULL
                                        OR STR_TO_DATE(date_of_admission, '%e %M, %Y') <= STR_TO_DATE(?, '%e %M, %Y')
                                    )
                            ) AS `section_students`";
        $studentCountStmt = mysqli_prepare($conn, $studentCountSql);

        if (!$studentCountStmt) {
            mysqli_stmt_close($studentStmt);
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode([
                'status' => 500,
                'message' => 'Failed to prepare student count query.'
            ]);
            exit;
        }

        mysqli_stmt_bind_param(
            $studentCountStmt,
            'ssss',
            $instituteId,
            $requestedClass,
            $requestedSection,
            $session['end_date']
        );

        if (!mysqli_stmt_execute($studentCountStmt)) {
            mysqli_stmt_close($studentCountStmt);
            mysqli_stmt_close($studentStmt);
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode([
                'status' => 500,
                'message' => 'Failed to count students.'
            ]);
            exit;
        }

        $studentCountResult = mysqli_stmt_get_result($studentCountStmt);
        $studentCountRow = mysqli_fetch_assoc($studentCountResult);
        $totalStudents = (int)($studentCountRow['total'] ?? 0);
        mysqli_stmt_close($studentCountStmt);
    }

    if ($studentsOnly) {
        mysqli_stmt_bind_param(
            $studentStmt,
            'ssssii',
            $instituteId,
            $requestedClass,
            $requestedSection,
            $session['end_date'],
            $limit,
            $offset
        );
    } else {
        mysqli_stmt_bind_param($studentStmt, 'si', $instituteId, $levelId);
    }

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
    $studentQueryCount = 0;
    $classSectionMatchCount = 0;
    $sessionEligibleStudentCount = 0;
    $students = [];
    $feeSql = "SELECT
                    fc.`id` AS `configuration_id`,
                    fc.`classes`,
                    fc.`applied_for`,
                    fc.`tax`,
                    fi.`id` AS `installment_id`,
                    fi.`scheduled date` AS `scheduled_date`,
                    fi.`amount` AS `installment_amount`
                FROM `fee_configurations` fc
                LEFT JOIN `fee_installments` fi
                    ON fi.`configuration_id` = fc.`id`
                    AND fi.`inst_id` = fc.`inst_id`
                WHERE fc.`inst_id` = ?
                ORDER BY fc.`id` ASC, fi.`id` ASC";
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
        $configurationId = (int)$fee['configuration_id'];

        if (!isset($feeConfigurations[$configurationId])) {
            $configuredClasses = array_values(array_filter(
                array_map('trim', explode(',', $fee['classes'])),
                static function ($class) {
                    return $class !== '';
                }
            ));

            $feeConfigurations[$configurationId] = [
                'classes' => $configuredClasses,
                'applied_for' => $fee['applied_for'],
                'tax' => (float)$fee['tax'],
                'installments' => []
            ];
        }

        if ($fee['installment_id'] !== null) {
            $feeConfigurations[$configurationId]['installments'][] = [
                'scheduled_date' => $fee['scheduled_date'],
                'amount' => (float)$fee['installment_amount']
            ];
        }
    }

    mysqli_stmt_close($feeStmt);
    $feeConfigurations = array_values($feeConfigurations);

    $today = new DateTimeImmutable('today');
    $todayTimestamp = $today->getTimestamp();
    $parseInstallmentDate = static function ($scheduledDate, $year) {
        $dateObject = DateTimeImmutable::createFromFormat(
            '!j F Y',
            trim((string)$scheduledDate) . ' ' . $year
        );
        $dateErrors = DateTimeImmutable::getLastErrors();

        if (
            $dateObject === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            return false;
        }

        return $dateObject->getTimestamp();
    };

    foreach ($feeConfigurations as &$feeConfiguration) {
        $installments = $feeConfiguration['installments'];

        usort($installments, static function ($first, $second) use ($parseInstallmentDate, $today) {
            return ($parseInstallmentDate($first['scheduled_date'], $today->format('Y')) ?: PHP_INT_MAX)
                <=> ($parseInstallmentDate($second['scheduled_date'], $today->format('Y')) ?: PHP_INT_MAX);
        });

        $feeConfiguration['active_installment_total'] = 0.0;

        if (!empty($installments)) {
            // The first installment always applies; later installments apply after its scheduled date.
            $feeConfiguration['active_installment_total'] = $installments[0]['amount'];
            $firstInstallmentTimestamp = $parseInstallmentDate(
                $installments[0]['scheduled_date'],
                $today->format('Y')
            );

            if ($firstInstallmentTimestamp !== false && $todayTimestamp > $firstInstallmentTimestamp) {
                foreach (array_slice($installments, 1) as $installment) {
                    $feeConfiguration['active_installment_total'] += $installment['amount'];
                }
            }
        }
    }
    unset($feeConfiguration);

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

        return $formattedAmount . $suffix;
    };

    while ($student = mysqli_fetch_assoc($studentResult)) {
        $studentQueryCount++;
        $className = trim((string)$student['class_name']);
        $sectionName = trim((string)$student['section_name']);

        if (!isset($classSectionIndexes[$className][$sectionName])) {
            continue;
        }

        $indexes = $classSectionIndexes[$className][$sectionName];
        $classSectionMatchCount++;
        $dueAmount = 0.0;
        $paidAmount = $studentPayments[(int)$student['student_id']] ?? 0.0;
        $studentClass = strtoupper(preg_replace('/\s+/', '', $className));
        $studentClassSection = $studentClass . strtoupper(preg_replace('/\s+/', '', $sectionName));
        $admissionTimestamp = !empty($student['date_of_admission'])
            ? $parseStoredDate($student['date_of_admission'])
            : false;

        // Students admitted after the selected session ends do not belong to this collection list.
        if ($admissionTimestamp !== false && $admissionTimestamp > $sessionEndTimestamp) {
            continue;
        }

        $sessionEligibleStudentCount++;

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
                $dueAmount += $feeConfiguration['active_installment_total']
                    * (1 + ($feeConfiguration['tax'] / 100));
            }
        }

        if ($studentsOnly) {
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

            $students[] = [
                'student_id' => (int)$student['student_id'],
                'enrollment_id' => $student['enrollment_id'],
                'student_name' => implode(' ', $nameParts),
                'contact_no' => $student['contact_no'],
                'date_of_admission' => $student['date_of_admission'],
                'due_amount' => $formatAmount(round($dueAmount, 2)),
                'paid_amount' => $formatAmount($paidAmount)
            ];
            continue;
        }

        $classes[$indexes['class_index']]['sections'][$indexes['section_index']]['total_applied'] += $dueAmount;
        $classes[$indexes['class_index']]['sections'][$indexes['section_index']]['total_due'] += $dueAmount;
        $classes[$indexes['class_index']]['sections'][$indexes['section_index']]['total_paid'] += $paidAmount;
        $classes[$indexes['class_index']]['sections'][$indexes['section_index']]['student_count']++;
    }

    mysqli_stmt_close($studentStmt);

    if ($studentsOnly) {
        header("HTTP/1.0 200 OK");
        echo json_encode([
            'status' => 200,
            'message' => 'Students fetched.',
            'totalCount' => $totalStudents,
            'currentPage' => $page,
            'students' => $students
        ]);
        exit;
    }

    foreach ($classes as &$class) {
        foreach ($class['sections'] as &$section) {
            $section['total_applied'] = $formatAmount(round($section['total_applied'], 2));
            $section['total_due'] = $formatAmount(round($section['total_due'], 2));
            $section['total_paid'] = $formatAmount(round($section['total_paid'], 2));
            $sectionHasStudents = $section['student_count'] > 0;
            unset($section['student_count']);
            $section['_has_students'] = $sectionHasStudents;
        }
        unset($section);

        $class['sections'] = array_values(array_filter(
            $class['sections'],
            static function ($section) {
                return !empty($section['_has_students']);
            }
        ));

        foreach ($class['sections'] as &$section) {
            unset($section['_has_students']);
        }
        unset($section);
    }
    unset($class);

    $classes = array_values(array_filter(
        $classes,
        static function ($class) {
            return !empty($class['sections']);
        }
    ));

    $responseData = [
        'status' => 200,
        'message' => 'Fee collection summary fetched.',
        'classes' => $classes,
    ];

    if ($debugMode) {
        $responseData['debug'] = [
            'session' => [
                'id' => $sessionId,
                'start_date' => $session['start_date'],
                'end_date' => $session['end_date'],
                'status' => $session['status']
            ],
            'student_query_count' => $studentQueryCount,
            'class_section_match_count' => $classSectionMatchCount,
            'session_eligible_student_count' => $sessionEligibleStudentCount
        ];
    }

    header("HTTP/1.0 200 OK");
    echo json_encode($responseData);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod .
            ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
