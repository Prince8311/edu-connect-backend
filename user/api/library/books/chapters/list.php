<?php

require __DIR__ . "/../../../../../utils/headers.php";
require __DIR__ . "/../../../../../utils/middleware.php";

$authResult = userAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status'  => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../../../_db-connect.php";
    global $conn;
    $instituteId = mysqli_real_escape_string($conn, (string) ($authResult['inst_id'] ?? ''));

    $bookId = trim((string) ($_GET['book_id'] ?? ''));

    if ($bookId === '') {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'status'  => 400,
            'message' => 'inst_id and book_id are required'
        ]);
        exit;
    }

    $bookIdEsc = mysqli_real_escape_string($conn, $bookId);

    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $limit  = max(1, min(100, (int) ($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $whereSQL = "WHERE `inst_id` = '$instituteId' AND `book_id` = '$bookIdEsc'";

    $countSql    = "SELECT COUNT(*) AS total FROM `library_book_chapters` $whereSQL";
    $countResult = mysqli_query($conn, $countSql);
    $totalCount  = (int) mysqli_fetch_assoc($countResult)['total'];

    $sql = "SELECT `id`, `chapter_index`, `name`, `file_name`
			FROM `library_book_chapters`
			$whereSQL
			ORDER BY `chapter_index` ASC, `id` ASC
			LIMIT $limit OFFSET $offset";

    $result = mysqli_query($conn, $sql);

    $chapters = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $chapters[] = $row;
    }

    echo json_encode([
        'status'  => 200,
        'message' => 'Chapters fetched successfully',
        'data'    => [
            'list'        => $chapters,
            'totalCount'  => $totalCount,
            'currentPage' => $page,
        ]
    ]);
    exit;
}
