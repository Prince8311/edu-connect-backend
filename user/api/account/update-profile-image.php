<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

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
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;

    $userId = mysqli_real_escape_string($conn, $authResult['userId']);
    $instId = mysqli_real_escape_string($conn, (string) $authResult['inst_id']);

    $newImagePath = null;
    $imageSaved = false;

    try {
        $userResult = mysqli_query($conn, "SELECT name, profile_image FROM users WHERE id = '$userId' AND inst_id = '$instId' LIMIT 1");
        if (!$userResult) {
            throw new Exception('Failed to fetch user details');
        }
        $user = mysqli_fetch_assoc($userResult);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['status' => 404, 'message' => 'User not found']);
            exit;
        }

        $uploadedFile = $_FILES['profile_image'] ?? null;
        if (!is_array($uploadedFile)
            || !isset($uploadedFile['error'], $uploadedFile['tmp_name'])
            || $uploadedFile['error'] !== UPLOAD_ERR_OK
            || !is_string($uploadedFile['tmp_name'])
            || !is_uploaded_file($uploadedFile['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['status' => 400, 'message' => 'A valid profile_image upload is required']);
            exit;
        }

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($uploadedFile['tmp_name']);
        if (!isset($allowedMimes[$mimeType]) || @getimagesize($uploadedFile['tmp_name']) === false) {
            http_response_code(400);
            echo json_encode(['status' => 400, 'message' => 'Invalid image file format. Allowed: JPEG, PNG, GIF, WebP']);
            exit;
        }

        $profileImagesDir = __DIR__ . '/../../../profile-images/user/';
        if (!is_dir($profileImagesDir) && !mkdir($profileImagesDir, 0755, true) && !is_dir($profileImagesDir)) {
            throw new Exception('Failed to create profile images directory');
        }

        // Match student creation: lowercase first name followed by -profile- and a timestamp.
        $nameParts = preg_split('/\s+/', trim((string) $user['name']));
        $firstName = trim(preg_replace('/[^a-z0-9_-]/', '', strtolower($nameParts[0])), '-_');
        $firstName = $firstName !== '' ? $firstName : 'user';
        $fileExt = $allowedMimes[$mimeType];
        $currentTime = time();
        do {
            $profileImageFileName = $firstName . '-profile-' . $currentTime++ . '.' . $fileExt;
            $newImagePath = $profileImagesDir . $profileImageFileName;
        } while (file_exists($newImagePath));

        $oldImageFileName = (string) ($user['profile_image'] ?? '');
        if ($oldImageFileName !== '') {
            // Only remove a filename within the profile images directory.
            if (basename($oldImageFileName) !== $oldImageFileName
                || strpbrk($oldImageFileName, '/\\:') !== false
                || $oldImageFileName === '.' || $oldImageFileName === '..') {
                throw new Exception('Invalid existing profile image filename');
            }
            $oldImagePath = $profileImagesDir . $oldImageFileName;
            if (is_file($oldImagePath) && !unlink($oldImagePath)) {
                throw new Exception('Failed to remove old profile image');
            }
        }

        if (!move_uploaded_file($uploadedFile['tmp_name'], $newImagePath)) {
            throw new Exception('Failed to save profile image');
        }
        $imageSaved = true;

        $profileImageEsc = mysqli_real_escape_string($conn, $profileImageFileName);
        if (!mysqli_query($conn, "UPDATE users SET profile_image = '$profileImageEsc' WHERE id = '$userId' AND inst_id = '$instId'")) {
            throw new Exception('Failed to update profile image');
        }

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Profile image updated successfully',
            'profile_image' => $profileImageFileName,
        ]);
    } catch (Throwable $e) {
        if ($imageSaved && is_file($newImagePath)) {
            unlink($newImagePath);
        }
        error_log('Profile image update failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => 'Failed to update profile image']);
    }
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
