<?php

require "../../../../utils/headers.php";
require "../../../../utils/middleware.php";

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
    require "../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    if (!isset($_GET['sectionId'])) {
        $data = [
            'status' => 400,
            'message' => 'Section id required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
    }

    $sectionId = mysqli_real_escape_string($conn, $_GET['sectionId']);
    $checkSectionSql = "SELECT * FROM `staff_form_sections` WHERE `id`='$sectionId' AND (`inst_id`='$instituteId' OR inst_id IS NULL)";
    $checkSectionResult = mysqli_query($conn, $checkSectionSql);

    if ($checkSectionResult && mysqli_num_rows($checkSectionResult) === 1) {
        $fieldSql = "SELECT `id`, `inst_id`, `section_id`, `form_field`, `field_type`, `is_required`, `items`, `sort_order` FROM `staff_form_fields` WHERE (`inst_id`='$instituteId' OR inst_id IS NULL) AND `section_id`='$sectionId' ORDER BY sort_order ASC";
        $fieldResult = mysqli_query($conn, $fieldSql);

        if ($fieldResult) {
            $fields = mysqli_fetch_all($fieldResult, MYSQLI_ASSOC);
            foreach ($fields as &$field) {
                $field['is_required'] = (bool) $field['is_required'];
                $field['isRemoval'] = $field['inst_id'] === null ? false : true;
                if (!empty($field['items'])) {
                    $decodedItems = json_decode($field['items'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $field['items'] = $decodedItems;
                    } else {
                        $field['items'] = null;
                    }
                } else {
                    $field['items'] = null;
                }
                unset($field['inst_id']);
            }
            $data = [
                'status' => 200,
                'message' => 'Form fields fetched.',
                'fields' => $fields
            ];
            header("HTTP/1.0 200 OK");
            echo json_encode($data);
        } else {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
        }
    } else {
        $data = [
            'status' => 201,
            'message' => 'Section is not available.',
        ];
        header("HTTP/1.0 201 Not available");
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
