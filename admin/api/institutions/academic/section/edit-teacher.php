<?php

require __DIR__ . "/../../../../../utils/headers.php";
require __DIR__ . "/../../../../../utils/middleware.php";

$authResult = adminAuthenticateRequest();
if (!$authResult['authenticated']) {
    header("HTTP/1.0 " . $authResult['status']);
    echo json_encode([
        'status' => $authResult['status'],
        'message' => $authResult['message']
    ]);
    exit;
}

if ($requestMethod === 'POST') {
    require __DIR__ . "/../../../../../_db-connect.php";
    global $conn;
    $instituteId = $authResult['inst_id'];

    $intent = $_GET['intent'] ?? '';
    $type = $_GET['type'] ?? '';

    $allowedIntents = ['add', 'update', 'delete'];
    $allowedTypes = ['class_teacher', 'subject_teacher', 'co_teacher'];

    if (!in_array($intent, $allowedIntents)) {
        header("HTTP/1.0 400 Bad Request");

        echo json_encode([
            'status' => 400,
            'message' => 'Invalid intent'
        ]);

        exit;
    }

    if (!in_array($type, $allowedTypes)) {
        header("HTTP/1.0 400 Bad Request");

        echo json_encode([
            'status' => 400,
            'message' => 'Invalid type'
        ]);

        exit;
    }

    $inputData = json_decode(file_get_contents("php://input"), true);
    $teacherId = mysqli_real_escape_string($conn, $inputData['teacherId']);
    $class = mysqli_real_escape_string($conn, $inputData['class']);
    $section = mysqli_real_escape_string($conn, $inputData['section']);

    if ($type === 'class_teacher') {
        $checkSql = "SELECT * FROM `teachers` WHERE `id`='$teacherId'";
        $checkResult = mysqli_query($conn, $checkSql);
        if ($checkResult) {
            if (mysqli_num_rows($checkResult) === 0) {
                $data = [
                    'status' => 409,
                    'message' => "This teacher doesn't exist."
                ];
                header("HTTP/1.0 409 Not exists");
                echo json_encode($data);
                exit;
            } else {
                $teacherData = mysqli_fetch_assoc($checkResult);
                $classSection = $teacherData['class_teacher'];
                if (!empty($classSection)) {
                    $data = [
                        'status' => 409,
                        'message' => "This teacher is already assigned as class teacher for {$classSection}."
                    ];

                    header("HTTP/1.0 409 Conflict");
                    echo json_encode($data);
                    exit;
                }
            }
        } else {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }
        $classTeacher = $class . '-' . $section;
        if ($intent === 'add') {
            $updateSql = "UPDATE `teachers` SET `class_teacher`='$classTeacher' WHERE `id`='$teacherId'";
            $updateResult = mysqli_query($conn, $updateSql);
            if ($updateResult) {
                $data = [
                    'status' => 200,
                    'message' => 'Class teacher added successfully.'
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
        } else if ($intent === 'delete') {
            $updateSql = "UPDATE `teachers` SET `class_teacher`=NULL WHERE `id`='$teacherId'";
            $updateResult = mysqli_query($conn, $updateSql);
            if ($updateResult) {
                $data = [
                    'status' => 200,
                    'message' => 'Class teacher removed successfully.'
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
        }
    } else if ($type === 'subject_teacher') {
        $subject = mysqli_real_escape_string($conn, $inputData['subject']);
        $checkSql = "SELECT * FROM `class_wise_subjects` WHERE `inst_id`='$instituteId' AND `subject`='$subject' AND `class`='$class' AND `section`='$section'";
        $checkResult = mysqli_query($conn, $checkSql);

        if ($checkResult) {
            if (mysqli_num_rows($checkResult) === 0) {
                $data = [
                    'status' => 409,
                    'message' => "This class subject doesn't exist."
                ];

                header("HTTP/1.0 409 Not exists");
                echo json_encode($data);
                exit;
            }

            $classSubjectData = mysqli_fetch_assoc($checkResult);
            $existingTeacher = $classSubjectData['subject_teacher'];
        } else {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }

        if ($intent === 'add') {
            $updateSql = "UPDATE `class_wise_subjects` SET `subject_teacher`='$teacherId' WHERE `inst_id`='$instituteId' AND `subject`='$subject' AND `class`='$class' AND `section`='$section'";
            $updateResult = mysqli_query($conn, $updateSql);

            if ($updateResult) {
                $message = !empty($existingTeacher)
                    ? 'Subject teacher updated successfully.'
                    : 'Subject teacher added successfully.';

                $data = [
                    'status' => 200,
                    'message' => $message
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
        } else if ($intent === 'delete') {
            $updateSql = "UPDATE `class_wise_subjects` SET `subject_teacher`=NULL WHERE `inst_id`='$instituteId' AND `subject`='$subject' AND `class`='$class' AND `section`='$section'";
            $updateResult = mysqli_query($conn, $updateSql);

            if ($updateResult) {
                $data = [
                    'status' => 200,
                    'message' => 'Subject teacher removed successfully.'
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
        }
    } else if ($type === 'co_teacher') {
        $subject = mysqli_real_escape_string($conn, $inputData['subject']);
        $teacherIds = json_decode($teacherId, true);

        if (!is_array($teacherIds)) {
            $data = [
                'status' => 400,
                'message' => 'Invalid co-teacher data.'
            ];
            header("HTTP/1.0 400 Bad Request");
            echo json_encode($data);
            exit;
        }

        $teacherIds = array_map('intval', $teacherIds);
        $checkSql = "SELECT * FROM `class_wise_subjects` WHERE `inst_id`='$instituteId' AND `subject`='$subject' AND `class`='$class' AND `section`='$section'";
        $checkResult = mysqli_query($conn, $checkSql);

        if ($checkResult) {
            if (mysqli_num_rows($checkResult) === 0) {
                $data = [
                    'status' => 409,
                    'message' => "This class subject doesn't exist."
                ];
                header("HTTP/1.0 409 Not exists");
                echo json_encode($data);
                exit;
            }

            $classSubjectData = mysqli_fetch_assoc($checkResult);
            $existingCoTeachers = $classSubjectData['co_teachers'];
            $existingIds = [];

            if (!empty($existingCoTeachers)) {
                $existingIds = array_map('intval', explode(',', $existingCoTeachers));
            }

            $finalIds = array_unique(array_merge($existingIds, $teacherIds));
            $finalIdsString = implode(',', $finalIds);

            if ($intent === 'add') {
                $updateSql = "UPDATE `class_wise_subjects` SET `co_teachers`='$finalIdsString' WHERE `inst_id`='$instituteId' AND `subject`='$subject' AND `class`='$class' AND `section`='$section'";
                $updateResult = mysqli_query($conn, $updateSql);

                if ($updateResult) {
                    $data = [
                        'status' => 200,
                        'message' => 'Co-teachers added successfully.'
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
            } else if ($intent === 'delete') {
                $remainingIds = array_diff($existingIds, $teacherIds);
                $remainingIdsString = !empty($remainingIds) ? implode(',', $remainingIds) : NULL;
                $updateValue = $remainingIdsString !== NULL ? "'$remainingIdsString'" : "NULL";

                $updateSql = "UPDATE `class_wise_subjects` SET `co_teachers`=$updateValue WHERE `inst_id`='$instituteId' AND `subject`='$subject' AND `class`='$class' AND `section`='$section'";
                $updateResult = mysqli_query($conn, $updateSql);

                if ($updateResult) {
                    $data = [
                        'status' => 200,
                        'message' => 'Co-teachers removed successfully.'
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
            }
        } else {
            $data = [
                'status' => 500,
                'message' => 'Database error: ' . mysqli_error($conn)
            ];
            header("HTTP/1.0 500 Internal Server Error");
            echo json_encode($data);
            exit;
        }
    }
} else {
    $data = [
        'status' => 405,
        'message' => $requestMethod . ' Method Not Allowed',
    ];
    header("HTTP/1.0 405 Method Not Allowed");
    echo json_encode($data);
}
