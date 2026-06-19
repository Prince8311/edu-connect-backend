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
    $instituteId = $authResult['inst_id'];

    $isForm = isset($_GET['isForm']) && $_GET['isForm'] === 'true';
    $limit = 12;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0
        ? (int) $_GET['page']
        : 1;
    $showAll = isset($_GET['showAll']) && $_GET['showAll'] === 'true';
    $offset = ($page - 1) * $limit;

    // -----------------------
    // SEARCH CONDITION + class-section mode
    // -----------------------
    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
    $searchCondition = "";

    // Optional: fetch class-section wise subjects instead of institution_subjects
    $classSectionWise = isset($_GET['classSectionWise']) && $_GET['classSectionWise'] === 'true';
    $classVal = '';
    $sectionVal = '';
    if ($classSectionWise) {
        $classVal = isset($_GET['class']) ? mysqli_real_escape_string($conn, $_GET['class']) : '';
        $sectionVal = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : '';
        if ($classVal === '' || $sectionVal === '') {
            $data = [
                'status' => 400,
                'message' => 'class and section are required when classSectionWise is true'
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }
    }

    // choose subject column and table based on mode
    $subjectColumn = $classSectionWise ? 'subject' : 'subject_name';
    $tableName = $classSectionWise ? 'class_wise_subjects' : 'institution_subjects';

    if (!empty($search)) {
        $searchCondition = " AND `$subjectColumn` LIKE '%$search%'";
    }

    // -----------------------
    // COUNT QUERY
    // -----------------------
    $countSql = "SELECT COUNT(*) AS total FROM `" . $tableName . "` WHERE `inst_id` = '$instituteId'";
    if ($classSectionWise) {
        $countSql .= " AND `class` = '$classVal' AND `section` = '$sectionVal'";
    }
    $countSql .= " $searchCondition";
    $countResult  = mysqli_query($conn, $countSql);
    $countRow = mysqli_fetch_assoc($countResult);
    $totalSubjects = (int) $countRow['total'];

    // -----------------------
    // DATA QUERY (with LIMIT)
    // -----------------------
    if ($classSectionWise) {
        // only subject column requested from class_wise_subjects
        if ($showAll || $isForm) {
            $sql = "SELECT `subject` FROM `class_wise_subjects` WHERE `inst_id` = '$instituteId' AND `class` = '$classVal' AND `section` = '$sectionVal' $searchCondition ORDER BY id ASC";
        } else {
            $sql = "SELECT `subject` FROM `class_wise_subjects` WHERE `inst_id` = '$instituteId' AND `class` = '$classVal' AND `section` = '$sectionVal' $searchCondition ORDER BY id ASC LIMIT $limit OFFSET $offset";
        }
    } else {
        if ($showAll || $isForm) {
            $sql = "SELECT `id`, `inst_id`, `subject_name` FROM `institution_subjects` WHERE `inst_id` = '$instituteId' $searchCondition ORDER BY id ASC";
        } else {
            $sql = "SELECT `id`, `inst_id`, `subject_name` FROM `institution_subjects` WHERE `inst_id` = '$instituteId' $searchCondition ORDER BY id ASC LIMIT $limit OFFSET $offset";
        }
    }
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $subjects = mysqli_fetch_all($result, MYSQLI_ASSOC);

        if ($classSectionWise) {
            // return only subject values (strings)
            $subjects = array_map(function ($item) {
                return $item['subject'];
            }, $subjects);

            if ($isForm) {
                $subjects = array_values(array_unique($subjects));
                $data = [
                    'status' => 200,
                    'message' => 'Subjects fetched for form (class-section wise).',
                    'subjects' => $subjects
                ];
            } else {
                $data = [
                    'status' => 200,
                    'message' => 'Class-section subjects fetched.',
                    'totalCount' => $totalSubjects,
                    'currentPage' => $showAll ? null : $page,
                    'subjects' => $subjects
                ];
            }
        } else {
            if ($isForm) {
                $subjects = array_map(function ($item) {
                    return $item['subject_name'];
                }, $subjects);

                $subjects = array_values(array_unique($subjects));

                $data = [
                    'status' => 200,
                    'message' => 'Subjects fetched for form.',
                    'data' => $subjects
                ];
            } else {
                $data = [
                    'status' => 200,
                    'message' => 'All subjects fetched.',
                    'totalCount' => $totalSubjects,
                    'currentPage' => $showAll ? null : $page,
                    'subjects' => $subjects
                ];
            }
        }
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
