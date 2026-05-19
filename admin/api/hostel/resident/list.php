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

    // -----------------------
    // PAGINATION
    // -----------------------
    $limit = 12;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
    $offset = ($page - 1) * $limit;

    // -----------------------
    // USER TYPE FILTER
    // REQUIRED
    // Student / Staff
    // -----------------------
    if (!isset($_GET['userType']) || empty($_GET['userType'])) {
        $data = [
            'status' => 400,
            'message' => 'User type is required.',
        ];

        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $userType = mysqli_real_escape_string($conn, $_GET['userType']);
    if ($userType !== 'Student' && $userType !== 'Staff') {
        $data = [
            'status' => 400,
            'message' => 'Invalid userType.',
        ];

        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $userTypeCondition = " AND hr.user_type = '$userType'";

    // -----------------------
    // SEARCH
    // -----------------------
    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
    $searchCondition = "";

    if (!empty($search)) {
        $searchCondition = " AND (hr.name LIKE '%$search%' OR hr.user_id LIKE '%$search%')";
    }

    // -----------------------
    // COUNT QUERY
    // -----------------------
    $countSql = "SELECT COUNT(*) AS total FROM hostel_residents hr WHERE hr.inst_id = '$instituteId' $userTypeCondition $searchCondition";
    $countResult = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $totalResidents = (int) $countRow['total'];

    // -----------------------
    // MAIN QUERY
    // -----------------------
    $sql = "SELECT

            -- HOSTEL RESIDENTS
            hr.*,

            -- ROOM DETAILS
            hro.floor_no,
            hro.room_no,
            hro.bed_count,
            hro.occupied,
            hro.type AS room_type,

            -- BUILDING DETAILS
            hb.name AS building_name,

            -- STUDENT DETAILS
            s.enrollment_id,

            su.id AS student_user_db_id,
            su.name AS student_name,
            su.profile_image AS student_profile_image,
            su.email AS student_email,
            su.phone AS student_phone,

            -- STUDENT CLASS/SECTION
            sfv_class.value AS class_name,
            sfv_section.value AS section_name,

            -- STAFF DETAILS (ADMIN STAFF)
            st.staff_id AS admin_staff_id,

            au.id AS admin_user_db_id,
            au.name AS admin_staff_name,
            au.image AS admin_staff_profile_image,
            au.email AS admin_staff_email,
            au.phone AS admin_staff_phone,
            au.user_role AS admin_staff_role,

            -- TEACHER DETAILS
            t.staff_id AS teacher_staff_id,

            tu.id AS teacher_user_db_id,
            tu.name AS teacher_name,
            tu.profile_image AS teacher_profile_image,
            tu.email AS teacher_email,
            tu.phone AS teacher_phone

        FROM hostel_residents hr

        -- ROOM
        LEFT JOIN hostel_rooms hro
            ON hr.room_id = hro.id

        -- BUILDING
        LEFT JOIN hostel_buildings hb
            ON hro.building_id = hb.id

        -- STUDENTS
        LEFT JOIN students s
            ON (
                hr.user_type = 'Student'
                AND hr.user_id = s.enrollment_id
            )

        LEFT JOIN users su
            ON s.user_id = su.id

        -- STUDENT FIELD VALUES (CLASS)
        LEFT JOIN student_field_values sfv_class
            ON (
                s.id = sfv_class.student_id
                AND sfv_class.field_name = 'Class / Standard'
            )

        -- STUDENT FIELD VALUES (SECTION)
        LEFT JOIN student_field_values sfv_section
            ON (
                s.id = sfv_section.student_id
                AND sfv_section.field_name = 'Section'
            )

        -- STAFFS TABLE
        LEFT JOIN staffs st
            ON (
                hr.user_type = 'Staff'
                AND hr.user_id = st.staff_id
            )

        LEFT JOIN admin_users au
            ON st.admin_id = au.id

        -- TEACHERS TABLE
        LEFT JOIN teachers t
            ON (
                hr.user_type = 'Staff'
                AND hr.user_id = t.staff_id
            )

        LEFT JOIN users tu
            ON t.user_id = tu.id

        WHERE hr.inst_id = '$instituteId'
        $userTypeCondition
        $searchCondition

        ORDER BY hr.id DESC

        LIMIT $limit OFFSET $offset
    ";

    $result = mysqli_query($conn, $sql);

    if ($result) {
        $residents = [];

        while ($row = mysqli_fetch_assoc($result)) {
            // -----------------------
            // COMMON RESIDENT DATA
            // -----------------------
            $resident = [
                'id' => $row['id'],
                'inst_id' => $row['inst_id'],
                'resident_name' => $row['name'],
                'user_id' => $row['user_id'],
                'user_type' => $row['user_type'],
                'room_id' => $row['room_id'],
                'food_preference' => $row['food_preference'],
                'status' => $row['status'] == 1,
            ];

            // -----------------------
            // STUDENT
            // -----------------------
            if ($row['user_type'] === 'Student') {
                // CLASS + SECTION
                $classSection = null;

                if (!empty($row['class_name']) && !empty($row['section_name'])) {
                    $classSection =
                        $row['class_name'] . ' - ' . $row['section_name'];
                } else if (!empty($row['class_name'])) {
                    $classSection = $row['class_name'];
                } else if (!empty($row['section_name'])) {
                    $classSection = $row['section_name'];
                }

                $resident['class_section'] = $classSection;
                $resident['resident_details'] = [
                    'type' => 'Student',
                    'enrollment_id' => $row['enrollment_id'],
                    'name' => $row['student_name'],
                    'profile_image' => $row['student_profile_image'],
                    'email' => $row['student_email'],
                    'phone' => $row['student_phone'],
                ];
            }

            // -----------------------
            // STAFF
            // -----------------------
            else {
                // ADMIN STAFF
                if (!empty($row['admin_user_db_id'])) {
                    $resident['role'] = $row['admin_staff_role'];
                    $resident['resident_details'] = [
                        'type' => 'Staff',
                        'staff_id' => $row['admin_staff_id'],
                        'name' => $row['admin_staff_name'],
                        'profile_image' => $row['admin_staff_profile_image'],
                        'email' => $row['admin_staff_email'],
                        'phone' => $row['admin_staff_phone'],
                    ];
                }

                // TEACHER
                else if (!empty($row['teacher_user_db_id'])) {
                    $resident['role'] = 'Teacher';
                    $resident['resident_details'] = [
                        'type' => 'Staff',
                        'staff_id' => $row['teacher_staff_id'],
                        'name' => $row['teacher_name'],
                        'profile_image' => $row['teacher_profile_image'],
                        'email' => $row['teacher_email'],
                        'phone' => $row['teacher_phone'],
                    ];
                }

                // FALLBACK
                else {
                    $resident['role'] = null;
                    $resident['resident_details'] = null;
                }
            }

            // -----------------------
            // ROOM DETAILS
            // -----------------------
            $resident['room_details'] = [
                'room_id' => $row['room_id'],
                'building_name' => $row['building_name'],
                'floor_no' => $row['floor_no'],
                'room_no' => $row['room_no'],
                'bed_count' => $row['bed_count'],
                'occupied' => $row['occupied'],
                'room_type' => $row['room_type'],
            ];

            $residents[] = $resident;
        }

        $data = [
            'status' => 200,
            'message' => 'Residents fetched successfully.',
            'totalCount' => $totalResidents,
            'currentPage' => $page,
            'residents' => $residents
        ];

        header("HTTP/1.0 200 OK");

        echo json_encode($data);
    } else {
        $data = [
            'status' => 500,
            'message' => 'Database error: ' . mysqli_error($conn)
        ];

        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode($data);
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
