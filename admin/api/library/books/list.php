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
    $instituteId = mysqli_real_escape_string($conn, (string) $authResult['inst_id']);
    $limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $limit = $limit === false ? 10 : $limit;
    $page = $page === false ? 1 : $page;
    if ($page - 1 > intdiv(PHP_INT_MAX, $limit)) {
        http_response_code(400);
        echo json_encode(['status' => 400, 'message' => 'Pagination offset is too large.']);
        exit;
    }
    $offset = ($page - 1) * $limit;

    try {
        $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM `library_books` WHERE `inst_id`='$instituteId'");
        if ($countResult === false) {
            throw new RuntimeException(mysqli_error($conn));
        }
        $totalBooks = (int) mysqli_fetch_assoc($countResult)['total'];

        $sql = "SELECT `book_id`, `name`, `author`, `cover_image`, `stock`
                FROM `library_books` WHERE `inst_id`='$instituteId'
                ORDER BY `created_at` DESC, `book_id` DESC LIMIT $limit OFFSET $offset";
        $result = mysqli_query($conn, $sql);
        if ($result === false) {
            throw new RuntimeException(mysqli_error($conn));
        }
        $books = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $row['stock'] = (int) $row['stock'];
            $books[] = $row;
        }

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Books list fetched successfully.',
            'totalCount' => $totalBooks,
            'currentPage' => $page,
            'books' => $books
        ]);
    } catch (Throwable $error) {
        error_log('Library books list failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => 'Failed to fetch books list.']);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
