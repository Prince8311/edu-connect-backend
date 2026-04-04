<?php

require "../../../utils/headers.php";
require "../../../utils/middleware.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

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
    require "../../../_db-connect.php";
    global $conn;
    require "../../../PHPMailer/Exception.php";
    require "../../../PHPMailer/PHPMailer.php";
    require "../../../PHPMailer/SMTP.php";

    function findFieldValue(array $fields, array $keys)
    {
        $keys = array_map('strtolower', $keys);
        foreach ($fields as $field) {
            if (!isset($field['field_name'])) {
                continue;
            }
            $fieldName = strtolower(trim($field['field_name']));
            if (in_array($fieldName, $keys, true)) {
                return $field['value'] ?? null;
            }
        }
        return null;
    }

    function getStudentFullName(array $studentFields)
    {
        $firstName = findFieldValue($studentFields, ['first name', 'first_name', 'firstname']) ?: '';
        $middleName = findFieldValue($studentFields, ['middle name', 'middle_name', 'middlename']) ?: '';
        $lastName = findFieldValue($studentFields, ['last name', 'last_name', 'lastname']) ?: '';
        $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
        return $fullName ?: 'Student';
    }

    function generateRandomPassword($length = 10)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    function sendStudentEnrollmentEmail($email, $studentName, $enrollmentId, $session, $password)
    {
        if (empty($email)) {
            return;
        }

        $studentName = $studentName ?: 'Student';
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = 'mail.ticketbay.in';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'noreply@ticketbay.in';
            $mail->Password   = 'abhay$ticketbay@2024';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet    = 'UTF-8';

            $mail->isHTML(true);
            $mail->setFrom('noreply@ticketbay.in', 'noreply@ticketbay.in');
            $mail->addAddress($email, $studentName);
            $mail->Subject = 'Student record created successfully';
            $mail->Body = '<!DOCTYPE html>
                            <html lang="en">
                                <head>
                                    <meta charset="UTF-8">
                                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                </head>
                                <body style="margin:0;padding:0;font-family:Arial,sans-serif;color:#333;">
                                    <div style="padding:20px;">
                                        <h2 style="color:#333;">Welcome, ' . htmlspecialchars($studentName, ENT_QUOTES) . '</h2>
                                        <p style="font-size:14px;line-height:1.6;">
                                        Your student record has been created successfully.
                                        </p>
                                        <p style="font-size:14px;line-height:1.6;">
                                        Enrollment ID: <strong>' . htmlspecialchars($enrollmentId, ENT_QUOTES) . '</strong><br>
                                        Session: <strong>' . htmlspecialchars($session, ENT_QUOTES) . '</strong><br>
                                        Password: <strong>' . htmlspecialchars($password, ENT_QUOTES) . '</strong>
                                        </p>
                                        <p style="font-size:14px;line-height:1.6;">
                                        Please keep this information for your records.
                                        </p>
                                        <p style="font-size:14px;line-height:1.6;">Regards,<br>Shetty Ticket Counter Pvt. Ltd.</p>
                                    </div>
                                </body>
                            </html>';

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new \Exception('Failed to send enrollment email: ' . $e->getMessage());
        }
    }

    $userId = mysqli_real_escape_string($conn, $authResult['userId']);

    $inputData = json_decode(file_get_contents("php://input"), true);
    if (empty($inputData)) {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $adminSql = "SELECT i.inst_id FROM `admin_users` a JOIN institutions i ON a.id = i.admin_id WHERE a.id = '$userId' LIMIT 1";
    $adminResult = mysqli_query($conn, $adminSql);

    if (!$adminResult || mysqli_num_rows($adminResult) === 0) {
        header("HTTP/1.0 401 Unauthorized");
        echo json_encode([
            "status" => 401,
            "message" => "Invalid token or institute not found"
        ]);
        exit;
    }

    $adminData = mysqli_fetch_assoc($adminResult);
    $instituteId = $adminData['inst_id'];

    $students = $inputData['students'] ?? [];
    $session = mysqli_real_escape_string($conn, $inputData['session']);
    $isBulkUpload = $inputData['isBulkUpload'] ?? false;

    if (empty($students)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "No students found in request"
        ]);
        exit;
    }

    mysqli_begin_transaction($conn);

    function generateEnrollmentId($instId, $session)
    {
        // get 2 letters from institute id
        $instLetters = strtoupper(substr(preg_replace("/[^A-Za-z]/", "", $instId), 0, 2));

        if (strlen($instLetters) < 2) {
            $instLetters = "IN";
        }

        // session 2026-27 → 2627
        $sessionDigits = substr(str_replace("-", "", $session), -4);

        // random numbers
        $rand1 = rand(1, 9);
        $rand2 = rand(1, 9);

        return $rand1 . $instLetters[0] . $rand2 . $instLetters[1] . $sessionDigits;
    }

    try {
        foreach ($students as $student) {
            $studentFields = $student['student_fields'] ?? [];

            $studentName  = getStudentFullName($studentFields);
            $studentEmail = findFieldValue($studentFields, ['email']);
            $studentPhone = findFieldValue($studentFields, ['contact no.', 'phone', 'mobile']);

            $enrollmentId = generateEnrollmentId($instituteId, $session);

            $plainPassword = generateRandomPassword(10);
            $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

            $nameEsc  = mysqli_real_escape_string($conn, $studentName);
            $emailEsc = mysqli_real_escape_string($conn, $studentEmail);
            $phoneEsc = mysqli_real_escape_string($conn, $studentPhone);
            $passEsc  = mysqli_real_escape_string($conn, $hashedPassword);

            if (!empty($emailEsc)) {
                $check = mysqli_query($conn, "SELECT id FROM users WHERE email = '$emailEsc' LIMIT 1");
                if (mysqli_num_rows($check) > 0) {
                    header("HTTP/1.0 400 Bad Request");
                    echo json_encode([
                        "status" => 400,
                        "message" => "Email already exists: $studentEmail"
                    ]);
                    exit;
                }
            }

            $userSql = "INSERT INTO users (name, email, phone, user_type, password) VALUES ('$nameEsc', '$emailEsc', '$phoneEsc', 'student', '$passEsc')";
            if (!mysqli_query($conn, $userSql)) {
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode([
                    "status" => 500,
                    "message" => "Failed to insert user"
                ]);
                exit;
            }
            $newUserId = mysqli_insert_id($conn);

            $studentSql = "INSERT INTO students (inst_id, user_id, enrollment_id, created_at) VALUES ('$instituteId', '$newUserId', '$enrollmentId', NOW())";
            if (!mysqli_query($conn, $studentSql)) {
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode([
                    "status" => 500,
                    "message" => "Failed to insert student"
                ]);
                exit;
            }

            $studentId = mysqli_insert_id($conn);

            foreach ($studentFields as $field) {
                $sectionId = mysqli_real_escape_string($conn, $field['section_id']);
                $fieldName = mysqli_real_escape_string($conn, $field['field_name']);
                $value     = mysqli_real_escape_string($conn, $field['value']);

                $sql = "INSERT INTO student_field_values (inst_id, student_id, section_id, field_name, value) VALUES ('$instituteId', '$studentId', '$sectionId', '$fieldName', '$value')";
                if (!mysqli_query($conn, $sql)) {
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode([
                        "status" => 500,
                        "message" => "Failed to insert field values"
                    ]);
                    exit;
                }
            }

            if (!empty($studentEmail)) {
                sendStudentEnrollmentEmail(
                    $studentEmail,
                    $studentName,
                    $enrollmentId,
                    $session,
                    $plainPassword
                );
            }
        }

        mysqli_commit($conn);
        $message = $isBulkUpload ? 'Students uploaded successfully' : 'Student uploaded successfully';
        header("HTTP/1.0 200 OK");
        echo json_encode([
            "status" => 200,
            "message" => $message
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        header("HTTP/1.0 500 Internal Server Error");
        echo json_encode([
            "status" => 500,
            "message" => "Transaction failed",
            "error" => $e->getMessage()
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
