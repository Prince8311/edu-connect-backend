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
    require __DIR__ . "/../../../PHPMailer/Exception.php";
    require __DIR__ . "/../../../PHPMailer/PHPMailer.php";
    require __DIR__ . "/../../../PHPMailer/SMTP.php";

    function findFieldValue(array $fields, array $keys, $sectionId = null)
    {
        $keys = array_map('strtolower', $keys);
        foreach ($fields as $field) {
            if (!isset($field['field_name'])) {
                continue;
            }

            if ($sectionId !== null) {
                $fieldSectionId = isset($field['section_id']) ? (string) $field['section_id'] : null;
                if ($fieldSectionId !== (string) $sectionId) {
                    continue;
                }
            }

            $fieldName = strtolower(trim($field['field_name']));
            if (in_array($fieldName, $keys, true)) {
                return $field['value'] ?? null;
            }
        }
        return null;
    }

    function getStudentFullName(array $studentFields, $sectionId = null)
    {
        $firstName = findFieldValue($studentFields, ['first name', 'first_name', 'firstname'], $sectionId) ?: '';
        $middleName = findFieldValue($studentFields, ['middle name', 'middle_name', 'middlename'], $sectionId) ?: '';
        $lastName = findFieldValue($studentFields, ['last name', 'last_name', 'lastname'], $sectionId) ?: '';

        $fullName = implode(' ', array_filter([
            $firstName,
            $middleName,
            $lastName
        ]));

        return $fullName ?: 'Student';
    }

    function getStudentFirstLastName(array $studentFields, $sectionId = null): string
    {
        $firstName = findFieldValue($studentFields, ['first name', 'first_name', 'firstname'], $sectionId) ?: '';
        $lastName = findFieldValue($studentFields, ['last name', 'last_name', 'lastname'], $sectionId) ?: '';

        return trim($firstName . ' ' . $lastName);
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

    function sendStudentEnrollmentEmail(string $email, string $studentName, string $enrollmentId, string $session, string $password): void
    {
        if (empty($email)) {
            return;
        }

        $studentName = $studentName ?: 'Student';
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

    function sendGuardianEnrollmentEmail(
        string $email,
        string $guardianName,
        string $studentName,
        string $enrollmentId,
        string $guardianLogin,
        ?string $guardianPassword,
        bool $isExistingAccount
    ): void {
        if (empty($email)) {
            return;
        }

        $guardianName = $guardianName ?: 'Parent/Guardian';
        $studentName = $studentName ?: 'Student';
        $mail = new PHPMailer(true);

        if ($guardianPassword !== null && $guardianPassword !== '') {
            $introHtml = '<p style="font-size:14px;line-height:1.6;">
                          This is to inform you that your child <strong>' . htmlspecialchars($studentName, ENT_QUOTES) . '</strong> has been successfully enrolled in our institution and a guardian account has been created for you.
                          </p>';
            $credentialsHtml = '<p style="font-size:14px;line-height:1.6;">
                                Guardian Login: <strong>' . htmlspecialchars($guardianLogin, ENT_QUOTES) . '</strong><br>
                                Password: <strong>' . htmlspecialchars($guardianPassword, ENT_QUOTES) . '</strong>
                                </p>';
        } else {
            $introHtml = '<p style="font-size:14px;line-height:1.6;">
                          This is to inform you that another child, <strong>' . htmlspecialchars($studentName, ENT_QUOTES) . '</strong>, has been successfully added to your existing guardian account in our institution.
                          </p>';
            $credentialsHtml = '<p style="font-size:14px;line-height:1.6;">
                                Your guardian account already exists. Please continue using your existing login credentials to access the updated student information.
                                </p>';
        }

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
            $mail->addAddress($email, $guardianName);
            $mail->Subject = $isExistingAccount
                ? 'Student record added to your guardian account'
                : 'Guardian account created and student record added';
            $mail->Body = '<!DOCTYPE html>
                            <html lang="en">
                                <head>
                                    <meta charset="UTF-8">
                                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                </head>
                                <body style="margin:0;padding:0;font-family:Arial,sans-serif;color:#333;">
                                    <div style="padding:20px;">
                                        <h2 style="color:#333;">Dear ' . htmlspecialchars($guardianName, ENT_QUOTES) . ',</h2>
                                        ' . $introHtml . '
                                        <p style="font-size:14px;line-height:1.6;">
                                        Enrollment ID: <strong>' . htmlspecialchars($enrollmentId, ENT_QUOTES) . '</strong>
                                        </p>
                                        ' . $credentialsHtml . '
                                        <p style="font-size:14px;line-height:1.6;">
                                        Please keep this information secure for future use.
                                        </p>
                                        <p style="font-size:14px;line-height:1.6;">Regards,<br>Shetty Ticket Counter Pvt. Ltd.</p>
                                    </div>
                                </body>
                            </html>';

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new \Exception('Failed to send guardian enrollment email: ' . $e->getMessage());
        }
    }

    $instituteId = $authResult['inst_id'];

    // Support both JSON body and multipart/form-data (FormData from frontend)
    $inputData = [];

    if (!empty($_POST)) {
        $inputData = $_POST;
    } else {
        $rawInput = file_get_contents("php://input");
        if ($rawInput !== false && trim($rawInput) !== '') {
            $decodedInput = json_decode($rawInput, true);
            if (is_array($decodedInput)) {
                $inputData = $decodedInput;
            }
        }
    }

    if (empty($inputData)) {
        $data = [
            'status' => 400,
            'message' => 'Empty request data'
        ];
        header("HTTP/1.0 400 Bad Request");
        echo json_encode($data);
        exit;
    }

    $students = [];
    $studentsInput = $inputData['students'] ?? [];
    if (is_string($studentsInput)) {
        $decodedStudents = json_decode($studentsInput, true);
        if (is_array($decodedStudents)) {
            $students = $decodedStudents;
        }
    } elseif (is_array($studentsInput)) {
        $students = $studentsInput;
    }

    $isBulkUploadInput = $inputData['isBulkUpload'] ?? false;
    if (is_bool($isBulkUploadInput)) {
        $isBulkUpload = $isBulkUploadInput;
    } elseif (is_string($isBulkUploadInput)) {
        $isBulkUpload = in_array(strtolower(trim($isBulkUploadInput)), ['1', 'true', 'yes', 'on'], true);
    } else {
        $isBulkUpload = (bool) $isBulkUploadInput;
    }

    if (empty($students)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "No students found in request"
        ]);
        exit;
    }

    mysqli_begin_transaction($conn);

    function generateEnrollmentId(string $instId, string $session): string
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

            $studentSectionId = 1;
            $guardianSectionId = 2;

            $studentName  = getStudentFullName($studentFields, $studentSectionId);
            $studentFirstLastName = getStudentFirstLastName($studentFields, $studentSectionId);
            $studentFirstName = findFieldValue($studentFields, ['first name', 'first_name', 'firstname'], $studentSectionId);
            $studentLastName = findFieldValue($studentFields, ['last name', 'last_name', 'lastname'], $studentSectionId);
            $studentEmail = findFieldValue($studentFields, ['email'], $studentSectionId);
            $studentPhone = findFieldValue($studentFields, ['contact no.', 'phone', 'mobile'], $studentSectionId);
            $guardianName  = findFieldValue($studentFields, ['name'], $guardianSectionId) ?: getStudentFullName($studentFields, $guardianSectionId);
            $guardianEmail = findFieldValue($studentFields, ['email'], $guardianSectionId);
            $guardianPhone = findFieldValue($studentFields, ['contact no.', 'phone', 'mobile'], $guardianSectionId);

            // Get session value from student_fields
            $studentSession = findFieldValue($studentFields, ['session', 'academic session', 'academic_session'], $studentSectionId);
            if (empty($studentSession)) {
                header("HTTP/1.0 400 Bad Request");
                echo json_encode([
                    "status" => 400,
                    "message" => "Session field is missing for student: $studentName"
                ]);
                exit;
            }

            $enrollmentId = generateEnrollmentId($instituteId, $studentSession);

            $plainPassword = generateRandomPassword(10);
            $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

            // Handle profile image upload (only when isBulkUpload is false and file is provided)
            $profileImageFileName = null;
            if (!$isBulkUpload && !empty($_FILES) && isset($_FILES['profile_image'])) {
                $uploadedFile = $_FILES['profile_image'];
                if ($uploadedFile['error'] === UPLOAD_ERR_OK) {
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
                    finfo_close($finfo);

                    if (in_array($mimeType, $allowedMimes)) {
                        $profileImagesDir = __DIR__ . '/../../../profile-images/student/';
                        if (!is_dir($profileImagesDir)) {
                            mkdir($profileImagesDir, 0755, true);
                        }

                        $fileExt = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
                        $currentTime = time();
                        $profileImageFileName = strtolower(trim($studentFirstName)) . '-profile-' . $currentTime . '.' . $fileExt;
                        $profileImagePath = $profileImagesDir . $profileImageFileName;

                        if (!move_uploaded_file($uploadedFile['tmp_name'], $profileImagePath)) {
                            throw new \Exception("Failed to save profile image");
                        }
                    } else {
                        throw new \Exception("Invalid image file format. Allowed: JPEG, PNG, GIF, WebP");
                    }
                }
            }

            $nameEsc  = mysqli_real_escape_string($conn, $studentName);
            $emailEsc = mysqli_real_escape_string($conn, $studentEmail);
            $phoneEsc = mysqli_real_escape_string($conn, $studentPhone);
            $passEsc  = mysqli_real_escape_string($conn, $hashedPassword);

            if (!empty($studentFirstName) && !empty($studentLastName) && !empty($studentPhone) && !empty($studentEmail)) {
                $instEsc = mysqli_real_escape_string($conn, $instituteId);
                $firstNameEsc = mysqli_real_escape_string($conn, strtolower(trim($studentFirstName)));
                $lastNameEsc = mysqli_real_escape_string($conn, strtolower(trim($studentLastName)));
                $phoneCheckEsc = mysqli_real_escape_string($conn, strtolower(trim($studentPhone)));
                $emailCheckEsc = mysqli_real_escape_string($conn, strtolower(trim($studentEmail)));

                $studentExistsSql = "SELECT sfv_first.student_id
                    FROM student_field_values sfv_first
                    INNER JOIN student_field_values sfv_last
                        ON sfv_first.inst_id = sfv_last.inst_id
                        AND sfv_first.student_id = sfv_last.student_id
                    INNER JOIN student_field_values sfv_contact
                        ON sfv_first.inst_id = sfv_contact.inst_id
                        AND sfv_first.student_id = sfv_contact.student_id
                    INNER JOIN student_field_values sfv_email
                        ON sfv_first.inst_id = sfv_email.inst_id
                        AND sfv_first.student_id = sfv_email.student_id
                    WHERE sfv_first.inst_id = '$instEsc'
                        AND sfv_first.section_id = '1'
                        AND sfv_last.section_id = '1'
                        AND sfv_contact.section_id = '1'
                        AND sfv_email.section_id = '1'
                        AND LOWER(TRIM(sfv_first.field_name)) IN ('first name', 'first_name', 'firstname')
                        AND LOWER(TRIM(sfv_last.field_name)) IN ('last name', 'last_name', 'lastname')
                        AND LOWER(TRIM(sfv_contact.field_name)) IN ('contact no.', 'phone', 'mobile')
                        AND LOWER(TRIM(sfv_email.field_name)) = 'email'
                        AND LOWER(TRIM(sfv_first.value)) = '$firstNameEsc'
                        AND LOWER(TRIM(sfv_last.value)) = '$lastNameEsc'
                        AND LOWER(TRIM(sfv_contact.value)) = '$phoneCheckEsc'
                        AND LOWER(TRIM(sfv_email.value)) = '$emailCheckEsc'
                    LIMIT 1";

                $studentExistsResult = mysqli_query($conn, $studentExistsSql);
                if ($studentExistsResult && mysqli_num_rows($studentExistsResult) > 0) {
                    header("HTTP/1.0 400 Bad Request");
                    echo json_encode([
                        "status" => 400,
                        "message" => "Student already exists. Please check the details and try again."
                    ]);
                    exit;
                }
            }

            // Check if student email/phone matches guardian email/phone
            $contactOverlap = (!empty($studentEmail) && !empty($guardianEmail) && strtolower(trim($studentEmail)) === strtolower(trim($guardianEmail)))
                || (!empty($studentPhone) && !empty($guardianPhone) && trim($studentPhone) === trim($guardianPhone));

            // Helper: upsert guardian user, returns guardian user_id
            $guardianNameEsc  = mysqli_real_escape_string($conn, $guardianName);
            $guardianEmailEsc = mysqli_real_escape_string($conn, $guardianEmail);
            $guardianPhoneEsc = mysqli_real_escape_string($conn, $guardianPhone);
            $guardianPlainPassword = generateRandomPassword(10);
            $guardianHashedPassword = password_hash($guardianPlainPassword, PASSWORD_DEFAULT);
            $guardianPassEsc = mysqli_real_escape_string($conn, $guardianHashedPassword);

            $guardianUserId = null;
            $isNewGuardianUser = false;

            if (!empty($guardianName) || !empty($guardianEmail) || !empty($guardianPhone)) {
                $guardianCheckClauses = [];
                if (!empty($guardianEmail)) {
                    $guardianCheckClauses[] = "LOWER(TRIM(email)) = '" . mysqli_real_escape_string($conn, strtolower(trim($guardianEmail))) . "'";
                }
                if (!empty($guardianPhone)) {
                    $guardianCheckClauses[] = "TRIM(phone) = '" . mysqli_real_escape_string($conn, trim($guardianPhone)) . "'";
                }

                $guardianCheckResult = null;
                if (!empty($guardianCheckClauses)) {
                    $guardianCheckSql = "SELECT id, name, user_type FROM users WHERE " . implode(' OR ', $guardianCheckClauses) . " LIMIT 1";
                    $guardianCheckResult = mysqli_query($conn, $guardianCheckSql);
                }

                if ($guardianCheckResult && mysqli_num_rows($guardianCheckResult) > 0) {
                    $guardianRow = mysqli_fetch_assoc($guardianCheckResult);
                    $existingGuardianName = strtolower(trim((string) ($guardianRow['name'] ?? '')));
                    $incomingGuardianName = strtolower(trim((string) $guardianName));

                    if ($incomingGuardianName === '' || $existingGuardianName !== $incomingGuardianName) {
                        header("HTTP/1.0 400 Bad Request");
                        echo json_encode([
                            "status" => 400,
                            "message" => "An account already exists with the same email or phone, but the guardian name does not match the provided details."
                        ]);
                        exit;
                    }

                    $existingUserType = $guardianRow['user_type'];
                    $guardianUserId = $guardianRow['id'];

                    if ($existingUserType === 'teacher') {
                        $newUserType = "teacher,guardian";
                        $updateTypeSql = "UPDATE users SET user_type = '$newUserType' WHERE id = '$guardianUserId'";
                        if (!mysqli_query($conn, $updateTypeSql)) {
                            throw new \Exception("Failed to update guardian user_type");
                        }
                    }
                    // if already 'guardian', do nothing
                } else {
                    // Insert new guardian user
                    $insertGuardianSql = "INSERT INTO users (inst_id, name, email, phone, user_type, password) VALUES ('$instituteId', '$guardianNameEsc', '$guardianEmailEsc', '$guardianPhoneEsc', 'guardian', '$guardianPassEsc')";
                    if (!mysqli_query($conn, $insertGuardianSql)) {
                        throw new \Exception("Failed to insert guardian user");
                    }
                    $guardianUserId = mysqli_insert_id($conn);
                    $isNewGuardianUser = true;
                }
            }

            if ($contactOverlap) {
                // Only guardian user is created; student user_id will be NULL
                $newUserId = null;
            } else {
                // Create student user as well
                if ($profileImageFileName !== null && $profileImageFileName !== '') {
                    $profileImageEsc = mysqli_real_escape_string($conn, $profileImageFileName);
                    $profileImageValue = "'$profileImageEsc'";
                } else {
                    $profileImageValue = "NULL";
                }
                $userSql = "INSERT INTO users (inst_id, name, profile_image, email, phone, user_type, password) VALUES ('$instituteId', '$nameEsc', $profileImageValue, '$emailEsc', '$phoneEsc', 'student', '$passEsc')";
                if (!mysqli_query($conn, $userSql)) {
                    throw new \Exception("Failed to insert student user");
                }
                $newUserId = mysqli_insert_id($conn);
            }

            $userIdValue   = ($newUserId !== null) ? "'$newUserId'" : "NULL";
            $guardianIdValue = ($guardianUserId !== null) ? "'$guardianUserId'" : "NULL";
            if ($profileImageFileName !== null && $profileImageFileName !== '') {
                $profileImageEsc = mysqli_real_escape_string($conn, $profileImageFileName);
                $profileImageValue = "'$profileImageEsc'";
            } else {
                $profileImageValue = "NULL";
            }

            $studentSql = "INSERT INTO students (profile_image, inst_id, user_id, guardian_id, enrollment_id, created_at) VALUES ($profileImageValue, '$instituteId', $userIdValue, $guardianIdValue, '$enrollmentId', NOW())";
            if (!mysqli_query($conn, $studentSql)) {
                throw new \Exception("Failed to insert student");
            }

            $studentId = mysqli_insert_id($conn);

            foreach ($studentFields as $field) {
                $sectionId = mysqli_real_escape_string($conn, $field['section_id']);
                $fieldName = mysqli_real_escape_string($conn, $field['field_name']);
                $value     = mysqli_real_escape_string($conn, $field['value']);

                $sql = "INSERT INTO student_field_values (inst_id, student_id, section_id, field_name, value) VALUES ('$instituteId', '$studentId', '$sectionId', '$fieldName', '$value')";
                if (!mysqli_query($conn, $sql)) {
                    throw new \Exception("Failed to insert field values");
                }
            }

            // if (!empty($studentEmail) && filter_var($studentEmail, FILTER_VALIDATE_EMAIL) && !preg_match('/dummy|test|example|invalid|@yourdomain|@domain|@mailinator|@tempmail|@fake|@sample/i', $studentEmail)) {
            //     sendStudentEnrollmentEmail(
            //         $studentEmail,
            //         $studentName,
            //         $enrollmentId,
            //         $studentSession,
            //         $plainPassword
            //     );
            // }

            // if (!empty($guardianEmail) && filter_var($guardianEmail, FILTER_VALIDATE_EMAIL) && !preg_match('/dummy|test|example|invalid|@yourdomain|@domain|@mailinator|@tempmail|@fake|@sample/i', $guardianEmail)) {
            //     $guardianLogin = !empty($guardianEmail) ? $guardianEmail : $guardianPhone;
            //     $guardianPasswordForMail = $isNewGuardianUser ? $guardianPlainPassword : null;

            //     sendGuardianEnrollmentEmail(
            //         $guardianEmail,
            //         $guardianName,
            //         $studentName,
            //         $enrollmentId,
            //         $guardianLogin,
            //         $guardianPasswordForMail,
            //         !$isNewGuardianUser
            //     );
            // }
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
