<?php

require __DIR__ . "/../../../../utils/headers.php";
require __DIR__ . "/../../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    $data = [
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ];

    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode($data);
    exit;
}

if ($requestMethod === 'GET') {
    require __DIR__ . "/../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    if (!isset($_GET['userType'])) {
        $data = [
            'status' => 400,
            'message' => 'User type is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $userType = mysqli_real_escape_string($conn, trim($_GET['userType']));

    if (!in_array($userType, ['Student', 'Staff'], true)) {
        $data = [
            'status' => 400,
            'message' => "Invalid userType: $userType."
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    // Search filter
    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';

    if ($userType === 'Student') {
        $searchCondition = '';
        if (!empty($search)) {
            // Search by first, middle, last name (student_field_values)
            $searchCondition = " AND ("
                . "MAX(CASE WHEN sfv.field_name = 'First Name' THEN sfv.value END) LIKE '%$search%'"
                . " OR MAX(CASE WHEN sfv.field_name = 'Middle Name' THEN sfv.value END) LIKE '%$search%'"
                . " OR MAX(CASE WHEN sfv.field_name = 'Last Name' THEN sfv.value END) LIKE '%$search%')";
        }
        $query = "SELECT s.enrollment_id AS user_id, u.profile_image, "
            . "MAX(CASE WHEN sfv.field_name = 'First Name' THEN sfv.value END) AS first_name, "
            . "MAX(CASE WHEN sfv.field_name = 'Middle Name' THEN sfv.value END) AS middle_name, "
            . "MAX(CASE WHEN sfv.field_name = 'Last Name' THEN sfv.value END) AS last_name, "
            . "MAX(CASE WHEN sfv.field_name = 'Class / Standard' THEN sfv.value END) AS class, "
            . "MAX(CASE WHEN sfv.field_name = 'Section' THEN sfv.value END) AS section "
            . "FROM students s "
            . "JOIN users u ON u.id = s.user_id "
            . "LEFT JOIN student_field_values sfv ON sfv.student_id = s.id "
            . "WHERE s.inst_id = '$instituteId' "
            . "GROUP BY s.id, s.enrollment_id, u.profile_image "
            . "HAVING 1=1 $searchCondition "
            . "ORDER BY s.id DESC";

        $result = mysqli_query($conn, $query);

        if (!$result) {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        $users = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $fullName = trim(
                ($row['first_name'] ?? '') . ' ' .
                    ($row['middle_name'] ?? '') . ' ' .
                    ($row['last_name'] ?? '')
            );

            $users[] = [
                'user_id' => $row['user_id'],
                'user_type' => 'Student',
                'profile_image' => $row['profile_image'],
                'name' => $fullName,
                'class' => $row['class'],
                'section' => $row['section']
            ];
        }

        header("HTTP/1.0 200 OK");
        echo json_encode([
            'status' => 200,
            'message' => 'Student users fetched successfully',
            'users' => $users
        ]);
        exit;
    }

    if ($userType === 'Staff') {
        $searchCondition = '';
        if (!empty($search)) {
            $searchCondition = " AND name LIKE '%$search%'";
        }
        $teacherQuery = "SELECT t.staff_id AS user_id, u.profile_image, u.name, 'Teacher' AS role, 'Staff' AS user_type FROM teachers t JOIN users u ON u.id = t.user_id WHERE t.inst_id = '$instituteId' AND u.user_type = 'teacher' $searchCondition";
        $adminQuery = "SELECT s.staff_id AS user_id, au.image AS profile_image, au.name, au.user_role AS role, 'Staff' AS user_type FROM staffs s JOIN admin_users au ON au.id = s.admin_id WHERE s.inst_id = '$instituteId' AND au.user_type = 'inst_admin' $searchCondition";
        $query = "($teacherQuery) UNION ALL ($adminQuery) ORDER BY user_id DESC";

        $result = mysqli_query($conn, $query);

        if (!$result) {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        $users = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $users[] = [
                'user_id' => $row['user_id'],
                'user_type' => $row['user_type'],
                'profile_image' => $row['profile_image'],
                'name' => $row['name'],
                'role' => $row['role']
            ];
        }

        header("HTTP/1.0 200 OK");
        echo json_encode([
            'status' => 200,
            'message' => 'Staff users fetched successfully',
            'users' => $users
        ]);
        exit;
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
