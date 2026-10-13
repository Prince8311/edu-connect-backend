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

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $respond = static function ($status, $message, $extra = []) {
        http_response_code($status);
        echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
        exit;
    };

    if (!array_key_exists('memberId', $_GET)
        || (!is_string($_GET['memberId']) && !is_int($_GET['memberId']))
        || trim((string) $_GET['memberId']) === '') {
        $respond(400, 'memberId is required.');
    }

    $memberId = trim((string) $_GET['memberId']);

    try {
        $statement = mysqli_prepare(
            $conn,
            'SELECT
                issue.`id`,
                issue.`inst_id`,
                issue.`member_id`,
                issue.`book_id`,
                issue.`issued_at`,
                issue.`returned_on`,
                issue.`updated_by`,
                issue.`updated_at`,
                book.`name`,
                book.`cover_image`,
                book.`author`
             FROM `library_books_issue` issue
             LEFT JOIN `library_books` book
                ON book.`id` = issue.`book_id`
                AND book.`inst_id` = issue.`inst_id`
             WHERE issue.`member_id` = ? AND issue.`inst_id` = ?
             ORDER BY issue.`issued_at` DESC, issue.`id` DESC'
        );
        if ($statement === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        if (!mysqli_stmt_bind_param($statement, 'ss', $memberId, $instituteId)
            || !mysqli_stmt_execute($statement)) {
            throw new RuntimeException(mysqli_stmt_error($statement));
        }

        $result = mysqli_stmt_get_result($statement);
        if ($result === false) {
            throw new RuntimeException(mysqli_stmt_error($statement));
        }

        $issueHistory = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $issueHistory[] = $row;
        }

        mysqli_stmt_close($statement);
        unset($statement);

        $respond(200, 'Member issue history fetched successfully.', [
            'totalCount' => count($issueHistory),
            'issueHistory' => $issueHistory
        ]);
    } catch (Throwable $error) {
        if (isset($statement) && $statement instanceof mysqli_stmt) {
            mysqli_stmt_close($statement);
        }
        error_log('Member issue history fetch failed: ' . $error->getMessage());
        $respond(500, 'Failed to fetch member issue history.');
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
