<?php

require __DIR__ . "/../../../../../utils/headers.php";
require __DIR__ . "/../../../../../utils/middleware.php";

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
    require __DIR__ . "/../../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    if (!isset($_GET['class']) && !isset($_GET['section'])) {
        $data = [
            'status' => 400,
            'message' => 'Class & section is required.'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $class = mysqli_real_escape_string($conn, $_GET['class']);
    $section = mysqli_real_escape_string($conn, $_GET['section']);

    $classTeacher = $class . "-" . $section;

    /*
    |--------------------------------------------------------------------------
    | Fetch Class Teacher
    |--------------------------------------------------------------------------
    */
    $classTeacherSql = "SELECT users.id, users.name, users.profile_image, users.email, users.phone FROM teachers INNER JOIN users ON teachers.user_id = users.id WHERE teachers.inst_id = '$instituteId' AND teachers.class_teacher = '$classTeacher' LIMIT 1";
    $classTeacherResult = mysqli_query($conn, $classTeacherSql);

    $classTeacherData = null;

    if ($classTeacherResult && mysqli_num_rows($classTeacherResult) > 0) {
        $classTeacherData = mysqli_fetch_assoc($classTeacherResult);
    }

    /*
    |--------------------------------------------------------------------------
    | Fetch Students
    |--------------------------------------------------------------------------
    */
    $studentQuery = "SELECT users.id, users.name, users.profile_image, users.email, users.phone, students.enrollment_id FROM students INNER JOIN users ON students.user_id = users.id INNER JOIN student_field_values class_field ON students.id = class_field.student_id INNER JOIN student_field_values section_field ON students.id = section_field.student_id WHERE students.inst_id = '$instituteId' AND class_field.field_name = 'Class / Standard' AND class_field.value = '$class' AND section_field.field_name = 'Section' AND section_field.value = '$section' GROUP BY students.id";
    $studentResult = mysqli_query($conn, $studentQuery);

    $students = [];

    if ($studentResult && mysqli_num_rows($studentResult) > 0) {
        while ($row = mysqli_fetch_assoc($studentResult)) {
            $students[] = $row;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fetch Subjects
    |--------------------------------------------------------------------------
    */
    $subjectsQuery = "SELECT * FROM class_wise_subjects WHERE inst_id = '$instituteId' AND class = '$class' AND section = '$section'";
    $subjectsResult = mysqli_query($conn, $subjectsQuery);

    $subjects = [];
    $totalClassStudents = count($students);

    if ($subjectsResult && mysqli_num_rows($subjectsResult) > 0) {
        while ($subjectRow = mysqli_fetch_assoc($subjectsResult)) {

            /*
            |--------------------------------------------------------------------------
            | Mandatory Status
            |--------------------------------------------------------------------------
            */
            $isMandatory = $subjectRow['is_mandatory'] == 1;

            /*
            |--------------------------------------------------------------------------
            | Student Count
            |--------------------------------------------------------------------------
            */
            if ($isMandatory) {
                $studentCount = $totalClassStudents;
            } else {
                $studentIds = array_filter(
                    array_map('trim', explode(',', $subjectRow['students']))
                );
                $studentCount = count($studentIds);
            }

            /*
            |--------------------------------------------------------------------------
            | Subject Teacher
            |--------------------------------------------------------------------------
            */
            $subjectTeacher = null;

            if (!empty($subjectRow['subject_teacher'])) {
                $teacherId = mysqli_real_escape_string(
                    $conn,
                    $subjectRow['subject_teacher']
                );

                $teacherQuery = "SELECT teachers.id AS id, users.name, users.profile_image, users.email, users.phone, teachers.staff_id FROM teachers INNER JOIN users ON teachers.user_id = users.id WHERE teachers.id = '$teacherId' LIMIT 1";
                $teacherResult = mysqli_query($conn, $teacherQuery);

                if ($teacherResult && mysqli_num_rows($teacherResult) > 0) {
                    $subjectTeacher = mysqli_fetch_assoc($teacherResult);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | Co Teachers
        |--------------------------------------------------------------------------
        */
            $coTeachers = [];

            if (!empty($subjectRow['co_teachers'])) {
                $coTeacherIds = array_filter(
                    array_map('trim', explode(',', $subjectRow['co_teachers']))
                );

                if (!empty($coTeacherIds)) {
                    $coTeacherIdsString = implode(',', $coTeacherIds);

                    $coTeacherQuery = "SELECT teachers.id AS id, users.name, users.profile_image, users.email, users.phone, teachers.staff_id FROM teachers INNER JOIN users ON teachers.user_id = users.id WHERE teachers.id IN ($coTeacherIdsString)";
                    $coTeacherResult = mysqli_query($conn, $coTeacherQuery);

                    if ($coTeacherResult && mysqli_num_rows($coTeacherResult) > 0) {
                        while ($coTeacherRow = mysqli_fetch_assoc($coTeacherResult)) {
                            $coTeachers[] = $coTeacherRow;
                        }
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Final Subject Object
            |--------------------------------------------------------------------------
            */
            $subjects[] = [
                'id' => $subjectRow['id'],
                'subject' => $subjectRow['subject'],
                'is_mandatory' => $isMandatory,
                'students_count' => $studentCount,
                'subject_teacher' => $subjectTeacher,
                'co_teachers' => $coTeachers
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fetch Attendance Type for this Class
    |--------------------------------------------------------------------------
    | Check institution_attendance_settings table for a row matching the
    | current institute and where the classes column contains this class
    | (comma-separated). Use FIND_IN_SET to match the value.
    */
    $attendanceType = null;
    $classEscaped = mysqli_real_escape_string($conn, $class);
    $attendanceSql = "SELECT attendance_type FROM institution_attendance_settings WHERE inst_id = '$instituteId' AND FIND_IN_SET('$classEscaped', classes) LIMIT 1";
    $attendanceResult = mysqli_query($conn, $attendanceSql);
    if ($attendanceResult && mysqli_num_rows($attendanceResult) > 0) {
        $attendanceRow = mysqli_fetch_assoc($attendanceResult);
        $attendanceType = $attendanceRow['attendance_type'];
    }

    $data = [
        'status' => 200,
        'message' => 'Class details fetched successfully.',
        'data' => [
            'class_teacher' => $classTeacherData,
            'students' => $students,
            'subjects' => $subjects,
            'attendance_type' => $attendanceType
        ]
    ];

    header("HTTP/1.0 200 OK");
    echo json_encode($data);
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
