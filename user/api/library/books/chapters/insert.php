<?php

require __DIR__ . "/../../../../../utils/headers.php";
require __DIR__ . "/../../../../../utils/middleware.php";

$authResult = userAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../../../_db-connect.php";
    global $conn;

    $instituteId = mysqli_real_escape_string($conn, (string) ($authResult['inst_id'] ?? ''));
    $userId      = (int) ($authResult['userId'] ?? 0);
    $userType    = strtolower(trim((string) ($authResult['user_type'] ?? '')));

    if ($userType !== 'teacher') {
        header("HTTP/1.0 403 Forbidden");
        echo json_encode([
            'status'  => 403,
            'message' => 'Access denied. Only teachers are authorized to add books to the library.'
        ]);
        exit;
    }

    // --- Validate required text fields ---
    $bookId = trim((string) ($_POST['bookId'] ?? ''));
    $chapterIndex = trim((string) ($_POST['index'] ?? ''));
    $chapterName  = trim((string) ($_POST['name'] ?? ''));

    if ($bookId === '' || $chapterIndex === '' || $chapterName  === '') {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode(['status' => 400, 'message' => 'Book id, Chapter Name & index are required']);
        exit;
    }

    if (empty($_FILES) || !isset($_FILES['chapter_file']) || $_FILES['chapter_file']['error'] === UPLOAD_ERR_NO_FILE) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode(['status' => 400, 'message' => 'Chapter file is required']);
        exit;
    }

    $uploadedFile = $_FILES['chapter_file'];

    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode(['status' => 400, 'message' => 'File upload error code: ' . $uploadedFile['error']]);
        exit;
    }

    // --------------------------
    // Allow Only PDF
    // --------------------------
    $extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

    if ($extension !== 'pdf') {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'status' => 400,
            'message' => 'Only PDF files are allowed.'
        ]);
        exit;
    }

    // --------------------------
    // Fetch Book Short Code
    // --------------------------
    $bookSql = "SELECT short_code FROM library_books WHERE id = '$bookId' AND inst_id = '$instituteId' LIMIT 1";
    $bookQuery = mysqli_query($conn, $bookSql);

    if (!$bookQuery || mysqli_num_rows($bookQuery) == 0) {
        header("HTTP/1.0 404 Not Found");
        echo json_encode([
            'status' => 404,
            'message' => 'Book not found.'
        ]);
        exit;
    }

    $book = mysqli_fetch_assoc($bookQuery);
    $shortCode = trim($book['short_code']);
    $fileName = $shortCode . "-Chapter-" . $chapterIndex . "." . $extension;
    $saveDirectory = __DIR__ . "/../../../../../documents/library/books/" . $shortCode . "/chapters/";

    if (!is_dir($saveDirectory)) {
        if (!mkdir($saveDirectory, 0777, true)) {
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode([
                'status' => 500,
                'message' => 'Failed to create upload directory.'
            ]);
            exit;
        }
    }

    $destination = $saveDirectory . $fileName;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to save chapter PDF.'
        ]);
        exit;
    }

    $chapterNameEsc = mysqli_real_escape_string($conn, $chapterName);
    $fileNameEsc = mysqli_real_escape_string($conn, $fileName);
    $chapterIndexEsc = mysqli_real_escape_string($conn, $chapterIndex);

    $insertSql = "INSERT INTO `library_book_chapters`(`inst_id`, `book_id`, `chapter_index`, `name`, `file_name`) VALUES ('$instituteId','$bookId','$chapterIndexEsc','$chapterNameEsc','$fileNameEsc')";
    $insertQuery = mysqli_query($conn, $insertSql);

    if (!$insertQuery) {
        if (file_exists($destination)) {
            unlink($destination);
        }
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ]);
        exit;
    }

    echo json_encode([
        'status' => 200,
        'success' => true,
        'message' => 'Chapter uploaded successfully.',
    ]);
    exit;
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
