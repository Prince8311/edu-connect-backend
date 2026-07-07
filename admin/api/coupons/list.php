<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

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

    require __DIR__ . "/../../../_db-connect.php";
    global $conn;

    // -------------------------
    // Validate Type
    // -------------------------
    $type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';

    if (!in_array($type, ['general', 'private'])) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "Type must be either 'general' or 'private'."
        ]);
        exit;
    }

    // -------------------------
    // Pagination
    // -------------------------
    $limit = 12;

    $page = isset($_GET['page']) &&
        is_numeric($_GET['page']) &&
        $_GET['page'] > 0
        ? (int)$_GET['page']
        : 1;

    $offset = ($page - 1) * $limit;

    // -------------------------
    // Search
    // -------------------------
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $searchCondition = "";

    if (!empty($search)) {
        $search = mysqli_real_escape_string($conn, $search);
        $searchCondition = " AND code LIKE '%$search%'";
    }

    // -------------------------
    // Total Count
    // -------------------------
    $countSql = "SELECT COUNT(*) AS total
                 FROM coupons
                 WHERE type='$type'
                 $searchCondition";

    $countResult = mysqli_query($conn, $countSql);

    if (!$countResult) {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => mysqli_error($conn)
        ]);
        exit;
    }

    $countRow = mysqli_fetch_assoc($countResult);
    $totalCoupons = (int)$countRow['total'];

    // -------------------------
    // Fetch Coupons
    // -------------------------
    if ($type == "general") {

        $sql = "SELECT
                    code,
                    type,
                    bill_amount_range,
                    target_bill_amount,
                    offer_type,
                    offer_value,
                    offer_unit,
                    offer_limit,
                    validity_type,
                    count_type,
                    count_value,
                    validity_date,
                    status
                FROM coupons
                WHERE type='general'
                $searchCondition
                ORDER BY id DESC
                LIMIT $limit OFFSET $offset";
    } else {
        $sql = "SELECT
                    c.code,
                    c.type,
                    c.bill_amount_range,
                    c.target_bill_amount,
                    c.offer_type,
                    c.offer_value,
                    c.offer_unit,
                    c.offer_limit,
                    c.validity_type,
                    c.count_type,
                    c.count_value,
                    c.validity_date,
                    c.status,

                    i.inst_id,
                    i.inst_name,
                    i.phone,
                    i.email

                FROM coupons c

                LEFT JOIN institutions i
                    ON c.inst_id = i.inst_id

                WHERE c.type='private'
                $searchCondition

                ORDER BY c.id DESC

                LIMIT $limit OFFSET $offset";
    }

    $result = mysqli_query($conn, $sql);

    if ($result) {
        $coupons = [];

        while ($row = mysqli_fetch_assoc($result)) {
            // -------------------------
            // Capitalize enum values
            // -------------------------
            if (!empty($row['type'])) {
                $row['type'] = ucfirst($row['type']);
            }

            if (!empty($row['bill_amount_range'])) {
                $row['bill_amount_range'] = ucfirst($row['bill_amount_range']);
            }

            if (!empty($row['offer_type'])) {
                $row['offer_type'] = ucfirst($row['offer_type']);
            }

            if (!empty($row['offer_unit'])) {
                $row['offer_unit'] = ucfirst($row['offer_unit']);
            }

            if (!empty($row['validity_type'])) {
                $row['validity_type'] = ucfirst($row['validity_type']);
            }

            if (!empty($row['count_type'])) {
                $row['count_type'] = ucwords(str_replace('_', ' ', $row['count_type']));
            }

            // Status as boolean
            $row['status'] = (bool)$row['status'];

            // -------------------------
            // Private Coupon
            // -------------------------
            if ($type == "private") {
                $institution = [
                    "inst_id"   => $row['inst_id'],
                    "inst_name" => $row['inst_name'],
                    "phone"     => $row['phone'],
                    "email"     => $row['email']
                ];

                unset($row['inst_id']);
                unset($row['inst_name']);
                unset($row['phone']);
                unset($row['email']);

                $row['institution'] = $institution;
            }

            $coupons[] = $row;
        }

        header("HTTP/1.0 200 OK");
        echo json_encode([
            "status" => 200,
            "message" => "Coupons fetched successfully.",
            "totalCount" => $totalCoupons,
            "currentPage" => $page,
            "data" => $coupons
        ]);
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => mysqli_error($conn)
        ]);
    }
} else {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ]);
}
