<?php

require __DIR__ . "/../../../utils/headers.php";
require __DIR__ . "/../../../utils/middleware.php";

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
    require __DIR__ . "/../../../_db-connect.php";
    global $conn;

    require_once __DIR__ . "/../../../PHPMailer/Exception.php";
    require_once __DIR__ . "/../../../PHPMailer/PHPMailer.php";
    require_once __DIR__ . "/../../../PHPMailer/SMTP.php";

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

    function getStaffFullName(array $staffFields)
    {
        $firstName = findFieldValue($staffFields, ['first name', 'first_name', 'firstname']) ?: '';
        $middleName = findFieldValue($staffFields, ['middle name', 'middle_name', 'middlename']) ?: '';
        $lastName = findFieldValue($staffFields, ['last name', 'last_name', 'lastname']) ?: '';
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

    function sendStaffEnrollmentEmail($email, $staffName, $staffId, $password)
    {
        if (empty($email)) {
            return;
        }

        $staffName = $staffName ?: 'Student';
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = getenv('SMTP_HOST');
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('SMTP_MAIL');
            $mail->Password   = getenv('SMTP_PASSWORD');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = getenv('SMTP_PORT');
            $mail->CharSet    = 'UTF-8';

            $mail->isHTML(true);
            $mail->setFrom(getenv('SMTP_MAIL'), getenv('SMTP_MAIL'));
            $mail->addAddress($email, $staffName);
            $mail->Subject = 'Staff record created successfully';
            $mail->Body = '<!DOCTYPE html>
                            <html lang="en">
                                <head>
                                    <meta charset="UTF-8">
                                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                </head>
                                <body style="margin:0;padding:0;font-family:Arial,sans-serif;color:#333;">
                                    <div style="padding:20px;">
                                        <h2 style="color:#333;">Welcome, ' . htmlspecialchars($staffName, ENT_QUOTES) . '</h2>
                                        <p style="font-size:14px;line-height:1.6;">
                                        Your staff record has been created successfully.
                                        </p>
                                        <p style="font-size:14px;line-height:1.6;">
                                        Staff ID: <strong>' . htmlspecialchars($staffId, ENT_QUOTES) . '</strong><br>
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

    $staffs = $inputData['staffs'] ?? [];
    $staffType = mysqli_real_escape_string($conn, $inputData['staffType']);
    $isBulkUpload = $inputData['isBulkUpload'] ?? false;

    if (empty($students)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "No staffs found in request"
        ]);
        exit;
    }

    mysqli_begin_transaction($conn);

    function generateStaffId($instId)
    {
        $letters = strtoupper(preg_replace("/[^A-Za-z]/", "", $instId));

        if (strlen($letters) < 2) {
            $letters = "INSTITUTE";
        }

        $chars = str_split($letters);
        shuffle($chars);
        $firstTwo = $chars[0] . $chars[1];
        $lastFour = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return $firstTwo . $lastFour;
    }

    try {
        foreach ($staffs as $staff) {
            $staffFields = $staff['staff_fields'] ?? [];

            $staffName  = getStudentFullName($staffFields);
            $staffEmail = findFieldValue($staffFields, ['email']);
            $staffPhone = findFieldValue($staffFields, ['contact no.', 'phone', 'mobile']);

            $staffId = generateStaffId($instituteId);

            $plainPassword = generateRandomPassword(10);
            $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

            $nameEsc  = mysqli_real_escape_string($conn, $staffName);
            $emailEsc = mysqli_real_escape_string($conn, $staffEmail);
            $phoneEsc = mysqli_real_escape_string($conn, $staffPhone);
            $passEsc  = mysqli_real_escape_string($conn, $hashedPassword);

            if (!empty($emailEsc)) {
                $check = mysqli_query($conn, "SELECT id FROM users WHERE email = '$emailEsc' LIMIT 1");
                if (mysqli_num_rows($check) > 0) {
                    header("HTTP/1.0 400 Bad Request");
                    echo json_encode([
                        "status" => 400,
                        "message" => "Email already exists: $staffEmail"
                    ]);
                    exit;
                }
            }

            $userSql = "INSERT INTO users (name, email, phone, user_type, password) VALUES ('$nameEsc', '$emailEsc', '$phoneEsc', 'staff', '$passEsc')";
            if (!mysqli_query($conn, $userSql)) {
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode([
                    "status" => 500,
                    "message" => "Failed to insert user"
                ]);
                exit;
            }
            $newUserId = mysqli_insert_id($conn);

            $staffSql = "INSERT INTO staffs (inst_id, user_id, staff_id, staff_type, created_at) VALUES ('$instituteId', '$newUserId', '$staffId','$staffType', NOW())";
            if (!mysqli_query($conn, $studentSql)) {
                header("HTTP/1.0 500 Internal Server Error");
                echo json_encode([
                    "status" => 500,
                    "message" => "Failed to insert student"
                ]);
                exit;
            }

            $staffDataId = mysqli_insert_id($conn);

            foreach ($studentFields as $field) {
                $sectionId = mysqli_real_escape_string($conn, $field['section_id']);
                $fieldName = mysqli_real_escape_string($conn, $field['field_name']);
                $value     = mysqli_real_escape_string($conn, $field['value']);

                $sql = "INSERT INTO staff_field_values (inst_id, staff_id, section_id, field_name, value) VALUES ('$instituteId', '$staffDataId', '$sectionId', '$fieldName', '$value')";
                if (!mysqli_query($conn, $sql)) {
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode([
                        "status" => 500,
                        "message" => "Failed to insert field values"
                    ]);
                    exit;
                }
            }

            if (!empty($staffEmail)) {
                sendStaffEnrollmentEmail(
                    $staffEmail,
                    $staffName,
                    $staffId,
                    $plainPassword
                );
            }
        }

        mysqli_commit($conn);
        $message = $isBulkUpload ? 'Staffs uploaded successfully' : 'Staff uploaded successfully';
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
