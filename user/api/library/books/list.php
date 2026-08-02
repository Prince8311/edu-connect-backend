<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

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
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;

    $instituteId = mysqli_real_escape_string($conn, (string) ($authResult['inst_id'] ?? ''));

    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $limit   = max(1, min(100, (int) ($_GET['limit'] ?? 10)));
    $offset  = ($page - 1) * $limit;

    // Optional filters (single, comma-separated, or array values)
    $filterClassRaw   = $_GET['class'] ?? '';
    $filterSubjectRaw = $_GET['subject'] ?? '';
    $search           = trim((string) ($_GET['search'] ?? ''));

    $normalizeFilterValues = function ($raw) {
        $values = [];

        if (is_array($raw)) {
            foreach ($raw as $value) {
                $parts = explode(',', (string) $value);
                foreach ($parts as $part) {
                    $clean = trim($part);
                    if ($clean !== '') {
                        $values[] = $clean;
                    }
                }
            }
        } else {
            $parts = explode(',', (string) $raw);
            foreach ($parts as $part) {
                $clean = trim($part);
                if ($clean !== '') {
                    $values[] = $clean;
                }
            }
        }

        return array_values(array_unique($values));
    };

    $filterClasses  = $normalizeFilterValues($filterClassRaw);
    $filterSubjects = $normalizeFilterValues($filterSubjectRaw);

    $whereClauses = ["`lb`.`inst_id` = '$instituteId'"];

    if (!empty($filterClasses)) {
        $escapedClasses = array_map(function ($value) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $value) . "'";
        }, $filterClasses);
        $whereClauses[] = "`lb`.`class` IN (" . implode(', ', $escapedClasses) . ")";
    }
    if (!empty($filterSubjects)) {
        $escapedSubjects = array_map(function ($value) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $value) . "'";
        }, $filterSubjects);
        $whereClauses[] = "`lb`.`subject` IN (" . implode(', ', $escapedSubjects) . ")";
    }
    if ($search !== '') {
        $searchEsc      = mysqli_real_escape_string($conn, $search);
        $whereClauses[] = "(`lb`.`name` LIKE '%$searchEsc%' OR `lb`.`author` LIKE '%$searchEsc%')";
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);

    // Total count
    $countSql    = "SELECT COUNT(*) AS total FROM `library_books` lb $whereSQL";
    $countResult = mysqli_query($conn, $countSql);
    $totalCount  = (int) mysqli_fetch_assoc($countResult)['total'];

    // Paginated data
    $sql    = "SELECT lb.`id`, lb.`inst_id`, lb.`name`, lb.`short_code`, lb.`cover_image`,
                      lb.`class`, lb.`subject`, lb.`author`,
                      lb.`uploaded_by`, u.`name` AS uploaded_by_name,
                      DATE_FORMAT(lb.`uploaded_at`, '%d %b, %Y') AS uploaded_at
               FROM `library_books` lb
               LEFT JOIN `users` u ON u.`id` = lb.`uploaded_by`
               $whereSQL
               ORDER BY lb.`uploaded_at` DESC
               LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $sql);

    $books = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $books[] = $row;
    }

    echo json_encode([
        'status'      => 200,
        'message'     => 'Books fetched successfully',
        'data'        => [
            'list'        => $books,
            'totalCount'  => $totalCount,
            'currentPage' => $page,
        ]
    ]);
    exit;
}
