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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $configurationsSql = "SELECT * FROM `fee_configurations` WHERE `inst_id`='$instituteId' ORDER BY `id` DESC";
    $configurationsResult = mysqli_query($conn, $configurationsSql);

    if (!$configurationsResult) {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    $configurations = [];
    $configurationIds = [];

    while ($row = mysqli_fetch_assoc($configurationsResult)) {
        $row['installments'] = [];
        $configurations[$row['id']] = $row;
        $configurationIds[] = $row['id'];
    }

    if (!empty($configurationIds)) {
        $configurationIds = array_map('intval', $configurationIds);
        $configurationIdsString = implode(',', $configurationIds);

        $installmentsSql = "SELECT * FROM `fee_installments` WHERE `inst_id`='$instituteId' AND `configuration_id` IN ($configurationIdsString) ORDER BY `id` ASC";
        $installmentsResult = mysqli_query($conn, $installmentsSql);

        if (!$installmentsResult) {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        while ($installment = mysqli_fetch_assoc($installmentsResult)) {
            $configurationId = $installment['configuration_id'];
            unset($installment['inst_id'], $installment['configuration_id']);

            if (isset($configurations[$configurationId])) {
                $configurations[$configurationId]['installments'][] = $installment;
            }
        }
    }

    $data = [
        'status' => 200,
        'message' => 'Fee configuration list.',
        'configurations' => array_values($configurations)
    ];
    header("HTTP/1.0 200 OK");
    echo json_encode($data);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
