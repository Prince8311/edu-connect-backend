<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

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
    require __DIR__ . "/../../../../_db-connect.php";
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
    $bookName = trim((string) ($_POST['name'] ?? ''));
    $class    = trim((string) ($_POST['class'] ?? ''));
    $subject  = trim((string) ($_POST['subject'] ?? ''));
    $author   = trim((string) ($_POST['author'] ?? ''));

    if ($bookName === '' || $class === '' || $subject === '' || $author === '') {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode(['status' => 400, 'message' => 'name, class, subject, and author are required']);
        exit;
    }

    // --- Validate cover image ---
    if (empty($_FILES) || !isset($_FILES['cover_image']) || $_FILES['cover_image']['error'] === UPLOAD_ERR_NO_FILE) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode(['status' => 400, 'message' => 'Cover image file is required']);
        exit;
    }

    $uploadedFile = $_FILES['cover_image'];

    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode(['status' => 400, 'message' => 'File upload error code: ' . $uploadedFile['error']]);
        exit;
    }

    $allowedMimes = ['image/jpeg', 'image/png'];
    $finfo        = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType     = finfo_file($finfo, $uploadedFile['tmp_name']);
    finfo_close($finfo);

    $fileExt = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    if (!in_array($mimeType, $allowedMimes, true) || !in_array($fileExt, ['jpg', 'jpeg', 'png'], true)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode(['status' => 400, 'message' => 'Only JPG, JPEG, and PNG images are allowed']);
        exit;
    }

    // --- Generate short code: first 4 consonants/letters of book name + 4 random digits ---
    $nameSlug  = preg_replace('/[^a-zA-Z0-9]/', '', $bookName);
    $nameSlug  = strtoupper($nameSlug);
    $shortCode = substr($nameSlug, 0, 4);
    if (strlen($shortCode) < 4) {
        $shortCode = str_pad($shortCode, 4, 'X');
    }
    $shortCode .= rand(1000, 9999);

    // --- Build directory paths ---
    $booksBaseDir  = __DIR__ . '/../../../../documents/library/books/';
    $bookDir       = $booksBaseDir . $shortCode . '/';
    $chaptersDir   = $bookDir . 'chapters/';

    if (!is_dir($bookDir)) {
        mkdir($bookDir, 0755, true);
    }
    if (!is_dir($chaptersDir)) {
        mkdir($chaptersDir, 0755, true);
    }

    // --- Rename and save cover image ---
    $timestamp         = time();
    $safeBookName      = preg_replace('/[^a-zA-Z0-9\-_]/', '-', strtolower($bookName));
    $safeBookName      = trim(preg_replace('/-+/', '-', $safeBookName), '-');
    $coverImageFileName = $safeBookName . '-cover-' . $timestamp . '.' . $fileExt;
    $coverImagePath    = $bookDir . $coverImageFileName;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $coverImagePath)) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode(['status' => 500, 'message' => 'Failed to save cover image']);
        exit;
    }

    // --- Insert into DB ---
    $nameEsc       = mysqli_real_escape_string($conn, $bookName);
    $shortCodeEsc  = mysqli_real_escape_string($conn, $shortCode);
    $coverEsc      = mysqli_real_escape_string($conn, $coverImageFileName);
    $classEsc      = mysqli_real_escape_string($conn, $class);
    $subjectEsc    = mysqli_real_escape_string($conn, $subject);
    $authorEsc     = mysqli_real_escape_string($conn, $author);

    $sql = "INSERT INTO `library_books` (`inst_id`, `name`, `short_code`, `cover_image`, `class`, `subject`, `author`, `uploaded_by`)
            VALUES ('$instituteId', '$nameEsc', '$shortCodeEsc', '$coverEsc', '$classEsc', '$subjectEsc', '$authorEsc', '$userId')";

    if (!mysqli_query($conn, $sql)) {
        @unlink($coverImagePath);
        @rmdir($chaptersDir);
        @rmdir($bookDir);

        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode(['status' => 500, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit;
    }


    $response = [
        'success' => true,
        'status' => 200,
        'message' => 'Book added successfully',
    ];
    header("HTTP/1.0 200 Ok");
    echo json_encode($response);
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
