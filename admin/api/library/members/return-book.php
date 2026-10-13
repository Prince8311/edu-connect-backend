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

    $transactionStarted = false;
    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Could not start transaction.');
        }
        $transactionStarted = true;

        $findStatement = mysqli_prepare(
            $conn,
            'SELECT `book_id`, `returned_on`
             FROM `library_books_issue`
             WHERE `id` = ? AND `inst_id` = ?
             LIMIT 1 FOR UPDATE'
        );
        if ($findStatement === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        if (!mysqli_stmt_bind_param($findStatement, 'ss', $issueId, $instituteId)
            || !mysqli_stmt_execute($findStatement)) {
            throw new RuntimeException(mysqli_stmt_error($findStatement));
        }

        mysqli_stmt_bind_result($findStatement, $bookId, $existingReturnedOn);
        $issueExists = mysqli_stmt_fetch($findStatement);
        mysqli_stmt_close($findStatement);
        unset($findStatement);

        if (!$issueExists) {
            mysqli_rollback($conn);
            $transactionStarted = false;
            $respond(404, 'Book issue record not found.');
        }
        if ($existingReturnedOn !== null && $existingReturnedOn !== '') {
            mysqli_rollback($conn);
            $transactionStarted = false;
            $respond(409, 'This book has already been returned.');
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

        $stockStatement = mysqli_prepare(
            $conn,
            'UPDATE `library_books`
             SET `stock` = `stock` + 1
             WHERE `id` = ? AND `inst_id` = ?'
        );
        if ($stockStatement === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        if (!mysqli_stmt_bind_param($stockStatement, 'ss', $bookId, $instituteId)
            || !mysqli_stmt_execute($stockStatement)) {
            throw new RuntimeException(mysqli_stmt_error($stockStatement));
        }

        $stockUpdated = mysqli_stmt_affected_rows($stockStatement) === 1;
        mysqli_stmt_close($stockStatement);
        unset($stockStatement);

        if (!$stockUpdated) {
            mysqli_rollback($conn);
            $transactionStarted = false;
            $respond(409, 'Associated book record not found.');
        }

        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Could not commit book return changes.');
        }
        $transactionStarted = false;

        $respond(200, 'Book returned successfully.');
    } catch (Throwable $error) {
        if ($transactionStarted) {
            mysqli_rollback($conn);
        }
        if (isset($findStatement) && $findStatement instanceof mysqli_stmt) {
            mysqli_stmt_close($findStatement);
        }
        if (isset($updateStatement) && $updateStatement instanceof mysqli_stmt) {
            mysqli_stmt_close($updateStatement);
        }
        if (isset($stockStatement) && $stockStatement instanceof mysqli_stmt) {
            mysqli_stmt_close($stockStatement);
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
