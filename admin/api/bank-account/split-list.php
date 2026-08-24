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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    if (empty($instituteId)) {
        $data = ['status' => 422, 'message' => 'Institute ID is missing from authentication'];
        header("HTTP/1.0 422 Unprocessable Entity");
        echo json_encode($data);
        exit;
    }

    $instIdEsc = mysqli_real_escape_string($conn, (string)$instituteId);
    $sql = "SELECT sba.`class_section`, sba.`fee_type`, iba.`account_name`, iba.`account_no`
            FROM `split_bank_accounts` AS sba
            INNER JOIN `institution_bank_accounts` AS iba
                ON iba.`id` = sba.`account_id`
                AND iba.`inst_id` = sba.`inst_id`
            WHERE sba.`inst_id` = '$instIdEsc'
            ORDER BY sba.`id` DESC";
    $result = mysqli_query($conn, $sql);

    if ($result === false) {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
        exit;
    }

    $list = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $groupedClasses = [];
        $classSectionValues = explode(',', (string)$row['class_section']);

        foreach ($classSectionValues as $classSectionValue) {
            $classSectionValue = trim($classSectionValue);
            if ($classSectionValue === '') {
                continue;
            }

            if (preg_match('/^(\d+)(.*)$/u', $classSectionValue, $matches)) {
                $class = $matches[1];
                $section = trim($matches[2]);

                if (!array_key_exists($class, $groupedClasses)) {
                    $groupedClasses[$class] = [];
                }

                if ($section === '') {
                    $groupedClasses[$class] = null;
                } elseif ($groupedClasses[$class] !== null && !in_array($section, $groupedClasses[$class], true)) {
                    $groupedClasses[$class][] = $section;
                }
            }
        }

        $formattedClassSections = [];
        foreach ($groupedClasses as $class => $sections) {
            $formattedClassSections[] = [
                'class' => (int)$class,
                'section' => $sections === null ? 'All Sections' : implode(', ', $sections)
            ];
        }

        $list[] = [
            'account_name' => $row['account_name'],
            'account_no' => $row['account_no'],
            'class_section' => $formattedClassSections,
            'fee_type' => $row['fee_type']
        ];
    }

    $data = [
        'status' => 200,
        'message' => 'Split bank account list retrieved successfully',
        'data' => $list
    ];
    header("HTTP/1.0 200 OK");
    echo json_encode($data);
    exit;
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
