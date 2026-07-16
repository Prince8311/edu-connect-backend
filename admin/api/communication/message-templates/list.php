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

if ($requestMethod !== 'GET') {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed'
    ]);
    exit;
}

require __DIR__ . "/../../../../_db-connect.php";
global $conn;

function formatTemplateUpdatedAt($dateTime)
{
    if (empty($dateTime)) {
        return null;
    }

    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return $dateTime;
    }

    return date('g:iA - j M, Y', $timestamp);
}

// -----------------------
// PAGINATION
// -----------------------
$limit = 12;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && (int) $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// -----------------------
// TYPE FILTER (required)
// active -> active
// requested -> pending
// -----------------------
$rawType = $_GET['type'] ?? null;
if ($rawType === null || trim((string) $rawType) === '') {
    header("HTTP/1.0 400 Bad Request");
    echo json_encode([
        'status' => 400,
        'message' => "Query param 'type' is required. Allowed values: active, requested"
    ]);
    exit;
}

$type = strtolower(trim((string) $rawType));
if (!in_array($type, ['active', 'requested'], true)) {
    header("HTTP/1.0 400 Bad Request");
    echo json_encode([
        'status' => 400,
        'message' => "Invalid type. Allowed values: active, requested"
    ]);
    exit;
}

$statusFilter = $type === 'active' ? 'active' : 'pending';
$statusCondition = " AND cmt.status = '$statusFilter'";

// -----------------------
// SEARCH (optional)
// -----------------------
$searchCondition = '';
if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $search = mysqli_real_escape_string($conn, trim($_GET['search']));
    $searchCondition = " AND (cmt.template_title LIKE '%$search%' OR cmt.template_body LIKE '%$search%')";
}

// -----------------------
// COUNT QUERY
// -----------------------
$countSql = "SELECT COUNT(*) AS total FROM `communication_msg_templates` cmt WHERE 1=1 $statusCondition $searchCondition";
$countResult = mysqli_query($conn, $countSql);
$countRow = mysqli_fetch_assoc($countResult);
$totalRecords = (int) $countRow['total'];
$totalPages = (int) ceil($totalRecords / $limit);

// -----------------------
// MAIN QUERY
// -----------------------
$sql = "SELECT
        cmt.id,
        cmt.template_id,
        cmt.template_title,
        cmt.template_body,
        cmt.balance,
        cmt.status,
        cmt.updated_at,

        -- approved_by (always super_admin)
        ab.id  AS approved_by_id,
        ab.name AS approved_by_name,

        -- created_by
        cb.id        AS created_by_id,
        cb.name      AS created_by_name,
        cb.user_type AS created_by_user_type,
        cb.inst_id   AS created_by_inst_id

    FROM `communication_msg_templates` cmt

    LEFT JOIN `admin_users` ab
        ON cmt.approved_by = ab.id

    LEFT JOIN `admin_users` cb
        ON cmt.created_by = cb.id

    WHERE 1=1
    $statusCondition
    $searchCondition

    ORDER BY cmt.id DESC

    LIMIT $limit OFFSET $offset";

$result = mysqli_query($conn, $sql);

if (!$result) {
    header("HTTP/1.0 500 Internal Server Error");
    echo json_encode([
        'status' => 500,
        'message' => 'Database error: ' . mysqli_error($conn)
    ]);
    exit;
}

$templates = [];

while ($row = mysqli_fetch_assoc($result)) {

    // -----------------------
    // approved_by object
    // -----------------------
    $approvedBy = null;
    if (!empty($row['approved_by_id'])) {
        $approvedBy = [
            'id'   => $row['approved_by_id'],
            'name' => $row['approved_by_name']
        ];
    }

    // -----------------------
    // created_by object
    // -----------------------
    $createdBy = null;
    if (!empty($row['created_by_id'])) {
        if ($row['created_by_user_type'] === 'super_admin') {
            $createdBy = [
                'id'   => $row['created_by_id'],
                'name' => $row['created_by_name']
            ];
        } elseif ($row['created_by_user_type'] === 'inst_admin' && !empty($row['created_by_inst_id'])) {
            $instIdEsc = mysqli_real_escape_string($conn, $row['created_by_inst_id']);
            $instSql = "SELECT `inst_id`, `inst_name` FROM `institutions` WHERE `inst_id` = '$instIdEsc' LIMIT 1";
            $instResult = mysqli_query($conn, $instSql);
            $instRow = $instResult ? mysqli_fetch_assoc($instResult) : null;

            $createdBy = [
                'user_id'   => $row['created_by_id'],
                'name'      => $row['created_by_name'],
                'inst_id'   => $row['created_by_inst_id'],
                'inst_name' => $instRow ? $instRow['inst_name'] : null
            ];
        } else {
            $createdBy = [
                'id'   => $row['created_by_id'],
                'name' => $row['created_by_name']
            ];
        }
    }

    $templates[] = [
        'id'             => $row['id'],
        'template_id'    => $row['template_id'],
        'template_title' => $row['template_title'],
        'template_body'  => $row['template_body'],
        'balance'        => $row['balance'],
        'status'         => ucfirst($row['status']),
        'updated_at'     => formatTemplateUpdatedAt($row['updated_at']),
        'approved_by'    => $approvedBy,
        'created_by'     => $createdBy
    ];
}

header("HTTP/1.0 200 OK");
echo json_encode([
    'status' => 200,
    'message' => 'Templates fetched successfully',
    'totalCount' => $totalRecords,
    'currentPage' => $page,
    'templates' => $templates,
]);
