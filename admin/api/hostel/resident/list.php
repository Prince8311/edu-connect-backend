<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message'],
    ]);
    exit;
}

if ($requestMethod !== 'GET') {
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode([
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ]);
    exit;
}

require __DIR__ . "/../../../../_db-connect.php";
global $conn;

$respond = function ($status, $message, $extra = []) {
    $statusTexts = [200 => 'OK', 400 => 'Bad Request', 500 => 'Internal Server Error'];
    header("HTTP/1.0 $status " . ($statusTexts[$status] ?? ''));
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
};

$instituteId = mysqli_real_escape_string($conn, (string) $authResult['inst_id']);
$limit = 12;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;
$offset = ($page - 1) * $limit;

$userType = trim((string) ($_GET['userType'] ?? ''));
if ($userType === '') {
    $respond(400, 'User type is required.');
}
if (!in_array($userType, ['Student', 'Staff'], true)) {
    $respond(400, "Invalid userType. Allowed values are Student or Staff.");
}

// The Staff list contains both teaching and non-teaching residents.
$userTypeCondition = $userType === 'Student'
    ? "hr.`user_type` = 'Student'"
    : "hr.`user_type` IN ('Teacher', 'Staff')";

$search = mysqli_real_escape_string($conn, trim((string) ($_GET['search'] ?? '')));
$searchCondition = '';
if ($search !== '') {
    if ($userType === 'Student') {
        $searchCondition = " AND (su.`name` LIKE '%$search%' OR hr.`user_id` LIKE '%$search%' OR s.`enrollment_id` LIKE '%$search%')";
    } else {
        $searchCondition = " AND (tu.`name` LIKE '%$search%' OR au.`name` LIKE '%$search%' OR hr.`user_id` LIKE '%$search%' OR t.`staff_id` LIKE '%$search%' OR st.`staff_id` LIKE '%$search%')";
    }
}

$joins = "
    LEFT JOIN `hostel_rooms` hro
        ON hro.`id` = hr.`room_id`
        AND hro.`inst_id` = hr.`inst_id`
    LEFT JOIN `hostel_buildings` hb
        ON hb.`id` = hro.`building_id`
        AND hb.`inst_id` = hr.`inst_id`
    LEFT JOIN `students` s
        ON hr.`user_type` = 'Student'
        AND s.`user_id` = hr.`user_id`
        AND s.`inst_id` = hr.`inst_id`
    LEFT JOIN `users` su
        ON hr.`user_type` = 'Student'
        AND su.`id` = hr.`user_id`
        AND su.`inst_id` = hr.`inst_id`
    LEFT JOIN `teachers` t
        ON hr.`user_type` = 'Teacher'
        AND t.`user_id` = hr.`user_id`
        AND t.`inst_id` = hr.`inst_id`
    LEFT JOIN `users` tu
        ON hr.`user_type` = 'Teacher'
        AND tu.`id` = hr.`user_id`
        AND tu.`inst_id` = hr.`inst_id`
    LEFT JOIN `staffs` st
        ON hr.`user_type` = 'Staff'
        AND st.`admin_id` = hr.`user_id`
        AND st.`inst_id` = hr.`inst_id`
    LEFT JOIN `admin_users` au
        ON hr.`user_type` = 'Staff'
        AND au.`id` = hr.`user_id`
        AND au.`inst_id` = hr.`inst_id`
";

$countSql = "SELECT COUNT(DISTINCT hr.`id`) AS `total`
    FROM `hostel_residents` hr
    $joins
    WHERE hr.`inst_id` = '$instituteId'
        AND $userTypeCondition
        $searchCondition";
$countResult = mysqli_query($conn, $countSql);
if (!$countResult) {
    $respond(500, 'Database error: ' . mysqli_error($conn));
}
$totalResidents = (int) mysqli_fetch_assoc($countResult)['total'];

$sql = "SELECT
        hr.`id`,
        hr.`user_type`,
        hr.`bed_no`,
        hr.`food_preference`,
        hr.`status`,
        hro.`floor_no`,
        hro.`room_no`,
        hb.`name` AS `building_name`,
        s.`enrollment_id`,
        su.`name` AS `student_name`,
        su.`profile_image` AS `student_image`,
        su.`phone` AS `student_phone`,
        (SELECT sfv.`value`
            FROM `student_field_values` sfv
            WHERE sfv.`inst_id` = hr.`inst_id`
                AND sfv.`student_id` = s.`id`
                AND sfv.`section_id` = 1
                AND sfv.`field_name` = 'Class / Standard'
            LIMIT 1) AS `class_name`,
        (SELECT sfv.`value`
            FROM `student_field_values` sfv
            WHERE sfv.`inst_id` = hr.`inst_id`
                AND sfv.`student_id` = s.`id`
                AND sfv.`section_id` = 1
                AND sfv.`field_name` = 'Section'
            LIMIT 1) AS `section_name`,
        t.`staff_id` AS `teacher_staff_id`,
        tu.`name` AS `teacher_name`,
        tu.`profile_image` AS `teacher_image`,
        tu.`phone` AS `teacher_phone`,
        st.`staff_id` AS `staff_id`,
        au.`name` AS `staff_name`,
        au.`image` AS `staff_image`,
        au.`phone` AS `staff_phone`,
        au.`user_role` AS `staff_role`
    FROM `hostel_residents` hr
    $joins
    WHERE hr.`inst_id` = '$instituteId'
        AND $userTypeCondition
        $searchCondition
    ORDER BY hr.`id` DESC
    LIMIT $limit OFFSET $offset";

$result = mysqli_query($conn, $sql);
if (!$result) {
    $respond(500, 'Database error: ' . mysqli_error($conn));
}

$formatFloor = function ($floorNo) {
    if ($floorNo === null || $floorNo === '') {
        return null;
    }

    return (int) $floorNo;
};

$residents = [];
while ($row = mysqli_fetch_assoc($result)) {
    if ($row['user_type'] === 'Student') {
        $classSectionParts = array_values(array_filter(
            [$row['class_name'], $row['section_name']],
            function ($value) {
                return $value !== null && $value !== '';
            }
        ));

        $name = $row['student_name'];
        $userDetails = [
            'image' => $row['student_image'],
            'phone' => $row['student_phone'],
            'class_section' => empty($classSectionParts) ? null : implode(' - ', $classSectionParts),
            'enrollment_id' => $row['enrollment_id'],
        ];
    } elseif ($row['user_type'] === 'Teacher') {
        $name = $row['teacher_name'];
        $userDetails = [
            'image' => $row['teacher_image'],
            'phone' => $row['teacher_phone'],
            'role' => 'Teacher',
            'staff_id' => $row['teacher_staff_id'],
        ];
    } else {
        $name = $row['staff_name'];
        $userDetails = [
            'image' => $row['staff_image'],
            'phone' => $row['staff_phone'],
            'role' => $row['staff_role'],
            'staff_id' => $row['staff_id'],
        ];
    }

    $residents[] = [
        'id' => $row['id'],
        'name' => $name,
        'directory' => in_array($row['user_type'], ['Student', 'Teacher'], true) ? 'user' : 'admin',
        'food_preference' => $row['food_preference'],
        'status' => $row['status'],
        'user_details' => $userDetails,
        'room' => [
            'number' => $row['room_no'],
            'bed_no' => $row['bed_no'],
            'floor' => $formatFloor($row['floor_no']),
            'building' => $row['building_name'],
        ],
    ];
}

$respond(200, 'Residents fetched successfully.', [
    'totalCount' => $totalResidents,
    'currentPage' => $page,
    'residents' => $residents,
]);
