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

if ($requestMethod === 'POST') {
    require "../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $inputData = json_decode(file_get_contents("php://input"), true);

    if (empty($inputData) || empty($inputData['sectionId']) || empty($inputData['fields'])) {
        echo json_encode([
            'status' => 400,
            'message' => 'Invalid request data'
        ]);
        exit;
    }

    $sectionId = mysqli_real_escape_string($conn, $inputData['sectionId']);
    $fields = $inputData['fields'];

    mysqli_begin_transaction($conn);

    try {
        $caseQuery = "UPDATE staff_form_fields SET sort_order = CASE id ";
        $ids = [];

        foreach ($fields as $field) {

            if (!isset($field['id']) || !isset($field['sort_order'])) {
                continue;
            }

            $id = (int)$field['id'];
            $order = (int)$field['sort_order'];

            $caseQuery .= "WHEN $id THEN $order ";
            $ids[] = $id;
        }

        if (empty($ids)) {
            throw new Exception("No valid fields provided");
        }

        $idsList = implode(",", $ids);

        $caseQuery .= "END WHERE id IN ($idsList) AND inst_id = '$instituteId' AND section_id = '$sectionId'";
        $updateResult = mysqli_query($conn, $caseQuery);

        if (!$updateResult) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_commit($conn);

        echo json_encode([
            'status' => 200,
            'message' => 'Field order updated successfully'
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to update order: ' . $e->getMessage()
        ]);
    }
} else {

    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ]);
}
