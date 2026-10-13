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

    try {
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

        $respond(200, 'Book issued successfully.', ['issue_id' => $issueId]);
    } catch (Throwable $error) {
        if (isset($statement) && $statement instanceof mysqli_stmt) {
            mysqli_stmt_close($statement);
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
