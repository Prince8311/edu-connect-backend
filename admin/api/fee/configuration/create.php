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

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
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

    $applicableType = isset($inputData['applicable_type']) ? trim((string) $inputData['applicable_type']) : '';
    $feeType = isset($inputData['fee_type']) ? trim((string) $inputData['fee_type']) : '';
    $feeNameInput = isset($inputData['fee_name']) ? trim((string) $inputData['fee_name']) : $feeType;
    $configurationTypeInput = isset($inputData['type']) ? trim((string) $inputData['type']) : $feeType;
    $receiptPrefix = isset($inputData['receipt_prefix']) ? trim((string) $inputData['receipt_prefix']) : '';
    $taxPercentage = isset($inputData['tax_percentage']) ? (float) $inputData['tax_percentage'] : 0;
    $classesInput = isset($inputData['classes']) ? $inputData['classes'] : '';
    $scheduledPaymentsInput = isset($inputData['scheduled_payments']) ? $inputData['scheduled_payments'] : '';

    if ($applicableType === '' || $feeType === '' || $receiptPrefix === '') {
        $data = [
            'status' => 400,
            'message' => 'applicable_type, fee_type and receipt_prefix are required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if (is_string($classesInput)) {
        $classesInput = json_decode($classesInput, true);
    }

    if (!is_array($classesInput) || empty($classesInput)) {
        $data = [
            'status' => 400,
            'message' => 'classes must be a non-empty array.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    if (is_string($scheduledPaymentsInput)) {
        $scheduledPaymentsInput = json_decode($scheduledPaymentsInput, true);
    }

    if (!is_array($scheduledPaymentsInput) || empty($scheduledPaymentsInput)) {
        $data = [
            'status' => 400,
            'message' => 'scheduled_payments must be a non-empty array.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $normalizedClasses = [];
    foreach ($classesInput as $classItem) {
        $classItem = trim((string) $classItem);
        if ($classItem !== '') {
            $normalizedClasses[] = $classItem;
        }
    }

    if (empty($normalizedClasses)) {
        $data = [
            'status' => 400,
            'message' => 'classes must contain valid values.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $normalizedPayments = [];
    foreach ($scheduledPaymentsInput as $payment) {
        if (!is_array($payment)) {
            continue;
        }

        $paymentDate = isset($payment['paymentDate']) ? trim((string) $payment['paymentDate']) : '';
        $amount = isset($payment['amount']) ? (float) $payment['amount'] : null;

        if ($paymentDate === '' || $amount === null || $amount < 0) {
            $data = [
                'status' => 400,
                'message' => 'Each scheduled payment must include a valid paymentDate and amount.'
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }

        $normalizedPayments[] = [
            'paymentDate' => $paymentDate,
            'amount' => $amount
        ];
    }

    if (empty($normalizedPayments)) {
        $data = [
            'status' => 400,
            'message' => 'scheduled_payments must contain valid values.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $classesJson = mysqli_real_escape_string($conn, json_encode(array_values($normalizedClasses)));
    $appliedFor = mysqli_real_escape_string($conn, $applicableType);
    $feeName = mysqli_real_escape_string($conn, $feeNameInput);
    $type = mysqli_real_escape_string($conn, $configurationTypeInput);
    $receiptPrefix = mysqli_real_escape_string($conn, $receiptPrefix);
    $taxPercentage = mysqli_real_escape_string($conn, (string) $taxPercentage);
    $installmentsCount = count($normalizedPayments);

    $dateColumn = 'scheduled_date';
    $columnCheckResult = mysqli_query($conn, "SHOW COLUMNS FROM `fee_installments`");
    if ($columnCheckResult) {
        while ($column = mysqli_fetch_assoc($columnCheckResult)) {
            if ($column['Field'] === 'scheduled date') {
                $dateColumn = 'scheduled date';
                break;
            }
        }
    }

    mysqli_begin_transaction($conn);

    try {
        $insertConfigurationSql = "INSERT INTO `fee_configurations`(`inst_id`, `fee_name`, `type`, `classes`, `applied_for`, `tax`, `installments_no`, `receipt_prefix`) VALUES ('$instituteId', '$feeName', '$type', '$classesJson', '$appliedFor', '$taxPercentage', '$installmentsCount', '$receiptPrefix')";
        $insertConfigurationResult = mysqli_query($conn, $insertConfigurationSql);

        if (!$insertConfigurationResult) {
            throw new Exception('Failed to create fee configuration.');
        }

        $configurationId = mysqli_insert_id($conn);
        $dateColumnSql = "`" . mysqli_real_escape_string($conn, $dateColumn) . "`";

        foreach ($normalizedPayments as $payment) {
            $paymentDate = mysqli_real_escape_string($conn, $payment['paymentDate']);
            $amount = mysqli_real_escape_string($conn, (string) $payment['amount']);

            $insertInstallmentSql = "INSERT INTO `fee_installments`(`inst_id`, `configuration_id`, $dateColumnSql, `amount`) VALUES ('$instituteId', '$configurationId', '$paymentDate', '$amount')";
            $insertInstallmentResult = mysqli_query($conn, $insertInstallmentSql);

            if (!$insertInstallmentResult) {
                throw new Exception('Failed to create fee installment.');
            }
        }

        mysqli_commit($conn);

        $data = [
            'status' => 200,
            'message' => 'Fee configuration created successfully.'
        ];
        header("HTTP/1.0 200 OK");
        echo json_encode($data);
    } catch (Exception $e) {
        mysqli_rollback($conn);

        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
