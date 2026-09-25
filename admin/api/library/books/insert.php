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
    $intent = $_GET['intent'] ?? 'add';
    if (!is_string($intent) || !in_array(strtolower(trim($intent)), ['add', 'update'], true)) {
        $respond(400, 'Invalid intent. Allowed values: add, update.');
    }
    $intent = strtolower(trim($intent));
    // Accept the reference endpoint's JSON inputs field or direct multipart fields.
    $inputs = $_POST;
    if (isset($_POST['inputs'])) {
        $inputs = is_string($_POST['inputs']) ? json_decode($_POST['inputs'], true) : null;
        if (!is_array($inputs)) {
            $respond(400, 'Invalid inputs payload.');
        }
    }
    if (!array_key_exists('author', $inputs) && array_key_exists('author_name', $inputs)) {
        $inputs['author'] = $inputs['author_name'];
    }
    $values = [];
    foreach (['name', 'author', 'stock', 'shelf_no'] as $field) {
        if (!array_key_exists($field, $inputs)) {
            if ($intent === 'add') {
                $respond(400, 'name, author, stock and shelf_no are required.');
            }
            continue;
        }
        if (!is_string($inputs[$field]) && !is_int($inputs[$field])) {
            $respond(400, 'Invalid ' . $field . '.');
        }
        $value = trim((string) $inputs[$field]);
        if ($value === '') {
            $respond(400, $field . ' must not be empty.');
        }
        if ($field === 'stock' && (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0)) {
            $respond(400, 'stock must be a non-negative integer.');
        }
        $values[$field] = $value;
    }
    $file = $_FILES['cover_image'] ?? ($_FILES['image'] ?? null);
    $hasImage = $file !== null && ($file['error'] ?? null) !== UPLOAD_ERR_NO_FILE;
    if ($intent === 'add' && !$hasImage) {
        $respond(400, 'cover_image is required.');
    }
    if ($hasImage) {
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
            || !is_string($file['name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            $respond(400, 'Cover image upload failed.');
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $imageTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($imageTypes[$extension]) || $imageTypes[$extension] !== $mime || !@getimagesize($file['tmp_name'])) {
            $respond(400, 'Only valid JPG, JPEG, PNG and WEBP cover images are allowed.');
        }
    }

    $quote = static function ($value) use ($conn) {
        return "'" . mysqli_real_escape_string($conn, (string) $value) . "'";
    };
    $query = static function ($sql) use ($conn) {
        $result = mysqli_query($conn, $sql);
        if ($result === false) {
            throw new RuntimeException(mysqli_error($conn));
        }
        return $result;
    };
    $uploadDir = __DIR__ . '/../../../../library-books/';
    $newPath = null;
    $oldFile = '';
    $transactionStarted = false;
    try {
        if (!mysqli_begin_transaction($conn)) {
            throw new RuntimeException('Could not start transaction.');
        }
        $transactionStarted = true;
        if ($intent === 'update') {
            $key = array_key_exists('book_id', $inputs) ? 'book_id' : 'id';
            $identifier = $inputs[$key] ?? null;
            if ((!is_string($identifier) && !is_int($identifier)) || trim((string) $identifier) === '') {
                mysqli_rollback($conn);
                $respond(400, 'book_id or id is required for update intent.');
            }
            $where = '`inst_id`=' . $quote($instituteId) . ' AND `' . $key . '`=' . $quote($identifier);
            $result = $query('SELECT * FROM `library_books` WHERE ' . $where . ' LIMIT 1 FOR UPDATE');
            $existing = mysqli_fetch_assoc($result);
            if (!$existing) {
                mysqli_rollback($conn);
                $respond(404, 'Book not found.');
            }
            $bookId = $existing['book_id'];
            $bookName = $values['name'] ?? $existing['name'];
            $oldFile = $existing['cover_image'] ?? '';
        } else {
            $bookName = $values['name'];
            // Mix the institution and book name with fresh randomness into six digits.
            $bookId = null;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $hash = hash('sha256', $instituteId . ':' . $bookName . ':' . bin2hex(random_bytes(16)));
                $candidate = (string) (100000 + (hexdec(substr($hash, 0, 7)) % 900000));
                $result = $query('SELECT `book_id` FROM `library_books` WHERE `book_id`=' . $quote($candidate) . ' LIMIT 1');
                if (mysqli_num_rows($result) === 0) {
                    $bookId = $candidate;
                    break;
                }
            }
            if ($bookId === null) {
                throw new RuntimeException('Could not generate an available book ID.');
            }
        }

        if ($hasImage) {
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Could not create cover image directory.');
            }
            $safeName = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($bookName)), '-');
            $baseName = substr($safeName !== '' ? $safeName : 'book', 0, 120) . '-cover';
            $fileName = $baseName . '.' . $extension;
            // Reserve a fresh path so existing covers survive failed database writes.
            $reservation = null;
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $candidatePath = $uploadDir . $fileName;
                $reservation = @fopen($candidatePath, 'x');
                if ($reservation !== false) {
                    $newPath = $candidatePath;
                    fclose($reservation);
                    break;
                }
                $fileName = $baseName . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
            }
            if ($newPath === null || !move_uploaded_file($file['tmp_name'], $newPath)) {
                throw new RuntimeException('Failed to save cover image.');
            }
            $values['cover_image'] = $fileName;
        }

        $now = date('Y-m-d H:i:s');
        $values['updated_at'] = $now;
        $values['updated_by'] = $userId;
        if ($intent === 'add') {
            $values['inst_id'] = $instituteId;
            $values['book_id'] = $bookId;
            $values['created_at'] = $now;
            $values['created_by'] = $userId;
            $query('INSERT INTO `library_books` (`' . implode('`, `', array_keys($values)) . '`) VALUES ('
                . implode(', ', array_map($quote, array_values($values))) . ')');
        } else {
            $assignments = [];
            foreach ($values as $column => $value) {
                $assignments[] = '`' . $column . '`=' . $quote($value);
            }
            $query('UPDATE `library_books` SET ' . implode(', ', $assignments) . ' WHERE ' . $where . ' LIMIT 1');
        }
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Could not commit book changes.');
        }
        $transactionStarted = false;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            mysqli_rollback($conn);
        }
        if ($newPath !== null && is_file($newPath)) {
            unlink($newPath);
        }
        error_log('Library book save failed: ' . $error->getMessage());
        $respond(500, 'Failed to save book.');
    }
    // Remove the old image only after the replacement and database commit succeed.
    if ($hasImage && $oldFile !== '' && basename($oldFile) === $oldFile && strpos($oldFile, '\\') === false) {
        $oldPath = $uploadDir . $oldFile;
        if (is_file($oldPath) && !@unlink($oldPath)) {
            error_log('Could not remove old library cover: ' . $oldFile);
        }
    }
    $respond(200, $intent === 'add' ? 'Book added successfully.' : 'Book updated successfully.', ['book_id' => $bookId]);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
