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

    $respond = static function ($status, $message, $extra = []) {
        http_response_code($status);
        echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
        exit;
    };

    $inputData = json_decode(file_get_contents('php://input'), true);
    if (!is_array($inputData)) {
        $inputData = $_POST;
    }

    foreach (['memberId', 'bookId'] as $field) {
        if (
            !array_key_exists($field, $inputData)
            || (!is_string($inputData[$field]) && !is_int($inputData[$field]))
            || trim((string) $inputData[$field]) === ''
        ) {
            $respond(400, $field . ' is required.');
        }
    }

    $memberId = trim((string) $inputData['memberId']);
    $bookId = trim((string) $inputData['bookId']);
    $issuedAt = trim((string) $inputData['issuedAt']);
    $currentDateTime = date('Y-m-d H:i:s');

    $transactionStarted = false;
    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Could not start transaction.');
        }
        $transactionStarted = true;

        $duplicateStatement = mysqli_prepare(
            $conn,
            'SELECT `id`
             FROM `library_books_issue`
             WHERE `inst_id` = ? AND `member_id` = ? AND `book_id` = ?
             LIMIT 1 FOR UPDATE'
        );
        if ($duplicateStatement === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        if (!mysqli_stmt_bind_param($duplicateStatement, 'sss', $instituteId, $memberId, $bookId)
            || !mysqli_stmt_execute($duplicateStatement)
            || !mysqli_stmt_store_result($duplicateStatement)) {
            throw new RuntimeException(mysqli_stmt_error($duplicateStatement));
        }

        $alreadyIssued = mysqli_stmt_num_rows($duplicateStatement) > 0;
        mysqli_stmt_close($duplicateStatement);
        unset($duplicateStatement);

        if ($alreadyIssued) {
            mysqli_rollback($conn);
            $transactionStarted = false;
            $respond(409, 'This member has already issued this book.');
        }

        $statement = mysqli_prepare(
            $conn,
            'INSERT INTO `library_books_issue`
                (`inst_id`, `member_id`, `book_id`, `issued_at`, `returned_on`, `updated_by`, `updated_at`)
             VALUES (?, ?, ?, ?, NULL, ?, ?)'
        );
        if ($statement === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        if (!mysqli_stmt_bind_param(
            $statement,
            'ssssss',
            $instituteId,
            $memberId,
            $bookId,
            $issuedAt,
            $userId,
            $currentDateTime
        ) || !mysqli_stmt_execute($statement)) {
            throw new RuntimeException(mysqli_stmt_error($statement));
        }

        $issueId = mysqli_insert_id($conn);
        mysqli_stmt_close($statement);
        unset($statement);

        $stockStatement = mysqli_prepare(
            $conn,
            'UPDATE `library_books`
             SET `stock` = `stock` - 1
             WHERE `id` = ? AND `inst_id` = ? AND `stock` > 0'
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
            $respond(409, 'Book not found or out of stock.');
        }

        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Could not commit book issue changes.');
        }
        $transactionStarted = false;

        $respond(200, 'Book issued successfully.', ['issue_id' => $issueId]);
    } catch (Throwable $error) {
        if ($transactionStarted) {
            mysqli_rollback($conn);
        }
        if (isset($duplicateStatement) && $duplicateStatement instanceof mysqli_stmt) {
            mysqli_stmt_close($duplicateStatement);
        }
        if (isset($statement) && $statement instanceof mysqli_stmt) {
            mysqli_stmt_close($statement);
        }
        if (isset($stockStatement) && $stockStatement instanceof mysqli_stmt) {
            mysqli_stmt_close($stockStatement);
        }
        error_log('Book issue failed: ' . $error->getMessage());
        $respond(500, 'Failed to issue book.');
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
