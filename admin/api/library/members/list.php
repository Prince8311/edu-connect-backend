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
    $limit = 12;
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $page = $page === false ? 1 : $page;
    if ($page - 1 > intdiv(PHP_INT_MAX, $limit)) {
        http_response_code(400);
        echo json_encode(['status' => 400, 'message' => 'Pagination offset is too large.']);
        exit;
    }
    $offset = ($page - 1) * $limit;

    $searchInput = $_GET['search'] ?? '';
    $search = is_string($searchInput) || is_int($searchInput)
        ? mysqli_real_escape_string($conn, trim((string) $searchInput))
        : '';
    $searchCondition = $search === ''
        ? ''
        : " AND (lm.`library_id` LIKE '$search%'
            OR member_user.`name` LIKE '$search%'
            OR member_admin.`name` LIKE '$search%')";

    $memberJoins = "
        LEFT JOIN `users` member_user
            ON LOWER(lm.`user_type`) IN ('student', 'teacher')
            AND member_user.`id` = lm.`user_id`
            AND member_user.`inst_id` = lm.`inst_id`
        LEFT JOIN `admin_users` member_admin
            ON LOWER(lm.`user_type`) IN ('staff', 'stuff')
            AND member_admin.`id` = lm.`user_id`
            AND member_admin.`inst_id` = lm.`inst_id`";

    try {
        $countSql = "SELECT COUNT(DISTINCT lm.`id`) AS `total`
                    FROM `library_members` lm
                    $memberJoins
                    WHERE lm.`inst_id` = '$instituteId'$searchCondition";
        $countResult = mysqli_query($conn, $countSql);
        if ($countResult === false) {
            throw new RuntimeException(mysqli_error($conn));
        }
        $totalMembers = (int) mysqli_fetch_assoc($countResult)['total'];

        $sql = "SELECT
                    lm.`id`,
                    lm.`user_id`,
                    lm.`user_type`,
                    lm.`enroll_id`,
                    lm.`library_id`,
                    lm.`created_at`,
                    lm.`created_by`,
                    CASE
                        WHEN LOWER(lm.`user_type`) IN ('student', 'teacher') THEN member_user.`name`
                        WHEN LOWER(lm.`user_type`) IN ('staff', 'stuff') THEN member_admin.`name`
                        ELSE NULL
                    END AS `member_name`,
                    CASE
                        WHEN LOWER(lm.`user_type`) IN ('student', 'teacher') THEN member_user.`profile_image`
                        WHEN LOWER(lm.`user_type`) IN ('staff', 'stuff') THEN member_admin.`image`
                        ELSE NULL
                    END AS `member_image`,
                    creator.`name` AS `creator_name`,
                    COALESCE(issue_totals.`total_issued`, 0) AS `total_issued`,
                    COALESCE(issue_totals.`returned`, 0) AS `returned`
                FROM `library_members` lm
                $memberJoins
                LEFT JOIN `admin_users` creator
                    ON creator.`id` = lm.`created_by`
                    AND creator.`inst_id` = lm.`inst_id`
                LEFT JOIN (
                    SELECT
                        `inst_id`,
                        `member_id`,
                        COUNT(*) AS `total_issued`,
                        SUM(CASE WHEN `returned_on` IS NOT NULL AND `returned_on` <> '' THEN 1 ELSE 0 END) AS `returned`
                    FROM `library_books_issue`
                    GROUP BY `inst_id`, `member_id`
                ) issue_totals
                    ON issue_totals.`inst_id` = lm.`inst_id`
                    AND issue_totals.`member_id` = lm.`library_id`
                WHERE lm.`inst_id` = '$instituteId'$searchCondition
                ORDER BY lm.`created_at` DESC, lm.`id` DESC
                LIMIT $limit OFFSET $offset";

        $result = mysqli_query($conn, $sql);
        if ($result === false) {
            throw new RuntimeException(mysqli_error($conn));
        }

        $members = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $formattedCreatedAt = null;
            if ($row['created_at'] !== null && $row['created_at'] !== '') {
                $createdAt = DateTime::createFromFormat('Y-m-d H:i:s', $row['created_at']);
                $formattedCreatedAt = $createdAt instanceof DateTime
                    ? $createdAt->format('h:i A - j M, Y')
                    : $row['created_at'];
            }

            $members[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'user_type' => $row['user_type'],
                'directory' => in_array(strtolower((string) $row['user_type']), ['student', 'teacher'], true)
                    ? 'user'
                    : 'admin',
                'enroll_id' => $row['enroll_id'],
                'library_id' => $row['library_id'],
                'name' => $row['member_name'],
                'image' => $row['member_image'],
                'total_issued' => (int) $row['total_issued'],
                'returned' => (int) $row['returned'],
                'created_at' => $formattedCreatedAt,
                'created_by' => [
                    'id' => $row['created_by'],
                    'name' => $row['creator_name']
                ]
            ];
        }

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'Library members fetched successfully.',
            'totalCount' => $totalMembers,
            'currentPage' => $page,
            'members' => $members
        ]);
    } catch (Throwable $error) {
        error_log('Library members list failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => 500,
            'message' => 'Failed to fetch library members.'
        ]);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
