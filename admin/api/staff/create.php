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

    function sendStaffEnrollmentEmail(
        string $email,
        string $staffName,
        string $staffId,
        string $password
    ): void {
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

    $staffs = [];
    $staffsInput = $inputData['staffs'] ?? [];
    if (is_string($staffsInput)) {
        $decodedStaffs = json_decode($staffsInput, true);
        if (is_array($decodedStaffs)) {
            $staffs = $decodedStaffs;
        }
    } elseif (is_array($staffsInput)) {
        $staffs = $staffsInput;
    }

    $staffType = isset($inputData['staffType']) ? trim((string) $inputData['staffType']) : '';

    $isBulkUploadInput = $inputData['isBulkUpload'] ?? false;
    if (is_bool($isBulkUploadInput)) {
        $isBulkUpload = $isBulkUploadInput;
    } elseif (is_string($isBulkUploadInput)) {
        $isBulkUpload = in_array(strtolower(trim($isBulkUploadInput)), ['1', 'true', 'yes', 'on'], true);
    } else {
        $isBulkUpload = (bool) $isBulkUploadInput;
    }

    if (empty($staffs)) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode([
            "status" => 400,
            "message" => "No staffs found in request"
        ]);
        exit;
    }

    mysqli_begin_transaction($conn);

    function generateStaffId(string $instId)
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

            $staffName  = getStaffFullName($staffFields);
            $staffFirstName = findFieldValue($staffFields, ['first name', 'first_name', 'firstname']);
            $staffLastName = findFieldValue($staffFields, ['last name', 'last_name', 'lastname']);
            $staffEmail = findFieldValue($staffFields, ['email']);
            $staffPhone = findFieldValue($staffFields, ['contact no.', 'phone', 'mobile']);
            $staffRole  = findFieldValue($staffFields, ['role', 'user role', 'Role']);

            $staffId = generateStaffId($instituteId);

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

                    if (in_array($mimeType, $allowedMimes, true)) {
                        $profileFolder = ($staffType === 'teaching') ? 'teacher' : 'admin';
                        $profileImagesDir = __DIR__ . '/../../../profile-images/' . $profileFolder . '/';
                        if (!is_dir($profileImagesDir)) {
                            mkdir($profileImagesDir, 0755, true);
                        }

                        $fileExt = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
                        $currentTime = time();
                        $fileBaseName = strtolower(trim((string) $staffFirstName));
                        if ($fileBaseName === '') {
                            $fileBaseName = 'staff';
                        }
                        $profileImageFileName = $fileBaseName . '-profile-' . $currentTime . '.' . strtolower($fileExt);
                        $profileImagePath = $profileImagesDir . $profileImageFileName;

                        if (!move_uploaded_file($uploadedFile['tmp_name'], $profileImagePath)) {
                            throw new \Exception("Failed to save profile image");
                        }
                    } else {
                        throw new \Exception("Invalid image file format. Allowed: JPEG, PNG, GIF, WebP");
                    }
                }
            }

            $nameEsc  = mysqli_real_escape_string($conn, $staffName ?? '');
            $emailEsc = mysqli_real_escape_string($conn, $staffEmail ?? '');
            $phoneEsc = mysqli_real_escape_string($conn, $staffPhone ?? '');
            $passEsc  = mysqli_real_escape_string($conn, $hashedPassword ?? '');
            $roleEsc  = mysqli_real_escape_string($conn, $staffRole ?? '');
            $staffTypeEsc = mysqli_real_escape_string($conn, $staffType ?? '');

            if (!in_array($staffTypeEsc, ['teaching', 'non-teaching'], true)) {
                header("HTTP/1.0 400 Bad Request");
                echo json_encode([
                    "status" => 400,
                    "message" => "Invalid staffType: $staffType"
                ]);
                exit;
            }

            if (!empty($staffFirstName) && !empty($staffLastName) && !empty($staffPhone) && !empty($staffEmail)) {
                $instEsc = mysqli_real_escape_string($conn, $instituteId);
                $firstNameEsc = mysqli_real_escape_string($conn, strtolower(trim($staffFirstName)));
                $lastNameEsc = mysqli_real_escape_string($conn, strtolower(trim($staffLastName)));
                $phoneCheckEsc = mysqli_real_escape_string($conn, strtolower(trim($staffPhone)));
                $emailCheckEsc = mysqli_real_escape_string($conn, strtolower(trim($staffEmail)));

                $staffExistsSql = "SELECT sfv_first.staff_id
                    FROM staff_field_values sfv_first
                    INNER JOIN staff_field_values sfv_last
                        ON sfv_first.inst_id = sfv_last.inst_id
                        AND sfv_first.staff_id = sfv_last.staff_id
                        AND sfv_first.staff_type = sfv_last.staff_type
                    INNER JOIN staff_field_values sfv_contact
                        ON sfv_first.inst_id = sfv_contact.inst_id
                        AND sfv_first.staff_id = sfv_contact.staff_id
                        AND sfv_first.staff_type = sfv_contact.staff_type
                    INNER JOIN staff_field_values sfv_email
                        ON sfv_first.inst_id = sfv_email.inst_id
                        AND sfv_first.staff_id = sfv_email.staff_id
                        AND sfv_first.staff_type = sfv_email.staff_type
                    WHERE sfv_first.inst_id = '$instEsc'
                        AND sfv_first.staff_type = '$staffTypeEsc'
                        AND LOWER(TRIM(sfv_first.field_name)) IN ('first name', 'first_name', 'firstname')
                        AND LOWER(TRIM(sfv_last.field_name)) IN ('last name', 'last_name', 'lastname')
                        AND LOWER(TRIM(sfv_contact.field_name)) IN ('contact no.', 'phone', 'mobile')
                        AND LOWER(TRIM(sfv_email.field_name)) = 'email'
                        AND LOWER(TRIM(sfv_first.value)) = '$firstNameEsc'
                        AND LOWER(TRIM(sfv_last.value)) = '$lastNameEsc'
                        AND LOWER(TRIM(sfv_contact.value)) = '$phoneCheckEsc'
                        AND LOWER(TRIM(sfv_email.value)) = '$emailCheckEsc'
                    LIMIT 1";

                $staffExistsResult = mysqli_query($conn, $staffExistsSql);
                if ($staffExistsResult && mysqli_num_rows($staffExistsResult) > 0) {
                    header("HTTP/1.0 400 Bad Request");
                    echo json_encode([
                        "status" => 400,
                        "message" => "Staff already exists for this staff type. Please check the details and try again."
                    ]);
                    exit;
                }
            }

            if ($staffTypeEsc === 'teaching') {
                $teacherCheckClauses = [];
                if (!empty($staffName)) {
                    $teacherCheckClauses[] = "LOWER(TRIM(name)) = '" . mysqli_real_escape_string($conn, strtolower(trim($staffName))) . "'";
                }
                if (!empty($staffEmail)) {
                    $teacherCheckClauses[] = "LOWER(TRIM(email)) = '" . mysqli_real_escape_string($conn, strtolower(trim($staffEmail))) . "'";
                }
                if (!empty($staffPhone)) {
                    $teacherCheckClauses[] = "TRIM(phone) = '" . mysqli_real_escape_string($conn, trim($staffPhone)) . "'";
                }

                if (!empty($teacherCheckClauses)) {
                    $teacherCheckSql = "SELECT id, user_type FROM users WHERE " . implode(' AND ', $teacherCheckClauses) . " LIMIT 1";
                    $teacherCheckResult = mysqli_query($conn, $teacherCheckSql);

                    if ($teacherCheckResult && mysqli_num_rows($teacherCheckResult) > 0) {
                        $teacherRow = mysqli_fetch_assoc($teacherCheckResult);
                        $existingUserType = strtolower(trim((string) ($teacherRow['user_type'] ?? '')));
                        $newTeacherUserType = null;

                        if ($existingUserType === 'teacher') {
                            header("HTTP/1.0 400 Bad Request");
                            echo json_encode([
                                "status" => 400,
                                "message" => "Teacher already exists with the same name, email and phone"
                            ]);
                            exit;
                        }

                        if ($existingUserType === 'guardian') {
                            $newTeacherUserType = "'guardian','teacher'";
                        }

                        if ($newTeacherUserType !== null) {
                            $teacherUserId = mysqli_real_escape_string($conn, $teacherRow['id']);
                            if ($profileImageFileName !== null && $profileImageFileName !== '') {
                                $profileImageEsc = mysqli_real_escape_string($conn, $profileImageFileName);
                                $updateTypeSql = "UPDATE users SET user_type = '$newTeacherUserType', profile_image = '$profileImageEsc' WHERE id = '$teacherUserId'";
                            } else {
                                $updateTypeSql = "UPDATE users SET user_type = '$newTeacherUserType' WHERE id = '$teacherUserId'";
                            }
                            if (!mysqli_query($conn, $updateTypeSql)) {
                                header("HTTP/1.0 500 Internal Server Error");
                                echo json_encode([
                                    "status" => 500,
                                    "message" => "Failed to update teacher user_type"
                                ]);
                                exit;
                            }

                            $newUserId = $teacherRow['id'];
                        } else {
                            if ($profileImageFileName !== null && $profileImageFileName !== '') {
                                $profileImageEsc = mysqli_real_escape_string($conn, $profileImageFileName);
                                $profileImageValue = "'$profileImageEsc'";
                            } else {
                                $profileImageValue = "NULL";
                            }
                            $userSql = "INSERT INTO users (name, profile_image, email, phone, user_type, password) VALUES ('$nameEsc', $profileImageValue, '$emailEsc', '$phoneEsc', 'teacher', '$passEsc')";
                            if (!mysqli_query($conn, $userSql)) {
                                header("HTTP/1.0 500 Internal Server Error");
                                echo json_encode([
                                    "status" => 500,
                                    "message" => "Failed to insert user"
                                ]);
                                exit;
                            }
                            $newUserId = mysqli_insert_id($conn);
                        }
                    } else {
                        if ($profileImageFileName !== null && $profileImageFileName !== '') {
                            $profileImageEsc = mysqli_real_escape_string($conn, $profileImageFileName);
                            $profileImageValue = "'$profileImageEsc'";
                        } else {
                            $profileImageValue = "NULL";
                        }
                        $userSql = "INSERT INTO users (name, profile_image, email, phone, user_type, password) VALUES ('$nameEsc', $profileImageValue, '$emailEsc', '$phoneEsc', 'teacher', '$passEsc')";
                        if (!mysqli_query($conn, $userSql)) {
                            header("HTTP/1.0 500 Internal Server Error");
                            echo json_encode([
                                "status" => 500,
                                "message" => "Failed to insert user"
                            ]);
                            exit;
                        }
                        $newUserId = mysqli_insert_id($conn);
                    }
                } else {
                    if ($profileImageFileName !== null && $profileImageFileName !== '') {
                        $profileImageEsc = mysqli_real_escape_string($conn, $profileImageFileName);
                        $profileImageValue = "'$profileImageEsc'";
                    } else {
                        $profileImageValue = "NULL";
                    }
                    $userSql = "INSERT INTO users (name, profile_image, email, phone, user_type, password) VALUES ('$nameEsc', $profileImageValue, '$emailEsc', '$phoneEsc', 'teacher', '$passEsc')";
                    if (!mysqli_query($conn, $userSql)) {
                        header("HTTP/1.0 500 Internal Server Error");
                        echo json_encode([
                            "status" => 500,
                            "message" => "Failed to insert user"
                        ]);
                        exit;
                    }
                    $newUserId = mysqli_insert_id($conn);
                }

                $staffSql = "INSERT INTO teachers (inst_id, user_id, staff_id, created_at) VALUES ('$instituteId', '$newUserId', '$staffId', NOW())";
                if (!mysqli_query($conn, $staffSql)) {
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode([
                        "status" => 500,
                        "message" => "Failed to insert teacher"
                    ]);
                    exit;
                }
                $staffDataId = mysqli_insert_id($conn);
            } else {
                $adminCheckClauses = [];
                if (!empty($staffName)) {
                    $adminCheckClauses[] = "LOWER(TRIM(name)) = '" . mysqli_real_escape_string($conn, strtolower(trim($staffName))) . "'";
                }
                if (!empty($staffEmail)) {
                    $adminCheckClauses[] = "LOWER(TRIM(email)) = '" . mysqli_real_escape_string($conn, strtolower(trim($staffEmail))) . "'";
                }
                if (!empty($staffPhone)) {
                    $adminCheckClauses[] = "TRIM(phone) = '" . mysqli_real_escape_string($conn, trim($staffPhone)) . "'";
                }

                if (!empty($adminCheckClauses)) {
                    $adminCheckSql = "SELECT id FROM admin_users WHERE " . implode(' AND ', $adminCheckClauses) . " LIMIT 1";
                    $adminCheckResult = mysqli_query($conn, $adminCheckSql);

                    if ($adminCheckResult && mysqli_num_rows($adminCheckResult) > 0) {
                        header("HTTP/1.0 400 Bad Request");
                        echo json_encode([
                            "status" => 400,
                            "message" => "Staff already exists with the same name, email and phone"
                        ]);
                        exit;
                    }
                }

                if ($profileImageFileName !== null && $profileImageFileName !== '') {
                    $profileImageEsc = mysqli_real_escape_string($conn, $profileImageFileName);
                    $profileImageValue = "'$profileImageEsc'";
                } else {
                    $profileImageValue = "NULL";
                }

                $userSql = "INSERT INTO admin_users (name, inst_id, image, email, phone, password, status, user_type, user_role) VALUES ('$nameEsc', '$instituteId', $profileImageValue, '$emailEsc', '$phoneEsc', '$passEsc', 1, 'inst_admin', '$roleEsc')";
                if (!mysqli_query($conn, $userSql)) {
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode([
                        "status" => 500,
                        "message" => "Failed to insert admin user"
                    ]);
                    exit;
                }
                $newAdminId = mysqli_insert_id($conn);

                $staffSql = "INSERT INTO staffs (inst_id, admin_id, staff_id, created_at) VALUES ('$instituteId', '$newAdminId', '$staffId', NOW())";
                if (!mysqli_query($conn, $staffSql)) {
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode([
                        "status" => 500,
                        "message" => "Failed to insert staff"
                    ]);
                    exit;
                }
                $staffDataId = mysqli_insert_id($conn);
            }

            foreach ($staffFields as $field) {
                $sectionId = mysqli_real_escape_string($conn, $field['section_id']);
                $fieldName = mysqli_real_escape_string($conn, $field['field_name']);
                $value     = mysqli_real_escape_string($conn, $field['value']);

                $sql = "INSERT INTO staff_field_values (inst_id, staff_id, staff_type, section_id, field_name, value) VALUES ('$instituteId', '$staffDataId', '$staffTypeEsc', '$sectionId', '$fieldName', '$value')";
                if (!mysqli_query($conn, $sql)) {
                    header("HTTP/1.0 500 Internal Server Error");
                    echo json_encode([
                        "status" => 500,
                        "message" => "Failed to insert field values"
                    ]);
                    exit;
                }
            }

            if (!empty($staffEmail) && filter_var($staffEmail, FILTER_VALIDATE_EMAIL) && !preg_match('/dummy|test|example|invalid|@yourdomain|@domain|@mailinator|@tempmail|@fake|@sample/i', $staffEmail)) {
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
