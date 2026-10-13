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

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];
    $userId = $authResult['userId'];

    $respond = static function ($status, $message) {
        http_response_code($status);
        echo json_encode(['status' => $status, 'message' => $message]);
        exit;
    };

    $inputData = json_decode(file_get_contents('php://input'), true);
    if (!is_array($inputData)) {
        $inputData = $_POST;
    }

    foreach (['issueId', 'returnedOn'] as $field) {
        if (!array_key_exists($field, $inputData)
            || (!is_string($inputData[$field]) && !is_int($inputData[$field]))
            || trim((string) $inputData[$field]) === '') {
            $respond(400, $field . ' is required.');
        }
    }

    $issueId = trim((string) $inputData['issueId']);
    $returnedOn = trim((string) $inputData['returnedOn']);

    try {
        $findStatement = mysqli_prepare(
            $conn,
            'SELECT `id` FROM `library_books_issue` WHERE `id` = ? AND `inst_id` = ? LIMIT 1'
        );
        if ($findStatement === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        if (!mysqli_stmt_bind_param($findStatement, 'ss', $issueId, $instituteId)
            || !mysqli_stmt_execute($findStatement)) {
            throw new RuntimeException(mysqli_stmt_error($findStatement));
        }

        if (!mysqli_stmt_store_result($findStatement)) {
            throw new RuntimeException(mysqli_stmt_error($findStatement));
        }
        $issueExists = mysqli_stmt_num_rows($findStatement) > 0;
        mysqli_stmt_close($findStatement);
        unset($findStatement);

        if (!$issueExists) {
            $respond(404, 'Book issue record not found.');
        }

        $currentDateTime = date('Y-m-d H:i:s');
        $updateStatement = mysqli_prepare(
            $conn,
            'UPDATE `library_books_issue`
             SET `returned_on` = ?, `updated_by` = ?, `updated_at` = ?
             WHERE `id` = ? AND `inst_id` = ?'
        );
        if ($updateStatement === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        if (!mysqli_stmt_bind_param(
            $updateStatement,
            'sssss',
            $returnedOn,
            $userId,
            $currentDateTime,
            $issueId,
            $instituteId
        ) || !mysqli_stmt_execute($updateStatement)) {
            throw new RuntimeException(mysqli_stmt_error($updateStatement));
        }

        mysqli_stmt_close($updateStatement);
        unset($updateStatement);
        $respond(200, 'Book returned successfully.');
    } catch (Throwable $error) {
        if (isset($findStatement) && $findStatement instanceof mysqli_stmt) {
            mysqli_stmt_close($findStatement);
        }
        if (isset($updateStatement) && $updateStatement instanceof mysqli_stmt) {
            mysqli_stmt_close($updateStatement);
        }
        error_log('Book return failed: ' . $error->getMessage());
        $respond(500, 'Failed to return book.');
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
