<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

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
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;
    $instituteId = mysqli_real_escape_string($conn, (string) ($authResult['inst_id'] ?? ''));
    $class = mysqli_real_escape_string($conn, trim((string) ($_GET['class'] ?? '')));

    if ($instituteId === '') {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            'success' => false,
            'status' => 400,
            'message' => 'Institute information is missing for the authenticated user.'
        ]);
        exit;
    }

    $whereClause = "WHERE `inst_id` = '$instituteId'";
    if ($class !== '') {
        $whereClause .= " AND `class` = '$class'";
    }

    $sql = "SELECT `subject`
            FROM `class_wise_subjects`
            $whereClause";
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            'success' => false,
            'status' => 500,
            'message' => 'Failed to fetch subjects.'
        ]);
        exit;
    }

    $subjects = [];
    $seenSubjects = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $subject = trim((string) ($row['subject'] ?? ''));
        if ($subject === '') {
            continue;
        }

        $subjectKey = strtolower($subject);
        if (isset($seenSubjects[$subjectKey])) {
            continue;
        }

        $seenSubjects[$subjectKey] = true;
        $subjects[] = $subject;
    }

    header("HTTP/1.0 200 OK");
    echo json_encode([
        'success' => true,
        'status' => 200,
        'message' => 'Subjects fetched successfully',
        'data' => $subjects
    ]);
} else {
    $response = [
        'success' => false,
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($response);
}
