<?php

header('Content-Type: application/json');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Only GET and POST methods are allowed.',
    ]);
    exit;
}

require_once __DIR__ . '/../_db-connect.php';

global $conn;

if (empty($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection not available.',
    ]);
    exit;
}

$queries = [
    'institutions' => "CREATE TABLE IF NOT EXISTS `institutions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `inst_id` VARCHAR(50) NOT NULL UNIQUE,
        `inst_name` VARCHAR(255) NOT NULL,
        `phone` VARCHAR(20) DEFAULT NULL,
        `email` VARCHAR(255) DEFAULT NULL,
        `receipt_prefix` VARCHAR(50) DEFAULT NULL,
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        `location` TEXT DEFAULT NULL,
        `deactive_date` DATETIME DEFAULT NULL,
        `image` VARCHAR(255) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'admin_users' => "CREATE TABLE IF NOT EXISTS `admin_users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(200) DEFAULT NULL,
        `inst_id` VARCHAR(50) DEFAULT NULL,
        `image` VARCHAR(255) DEFAULT NULL,
        `email` VARCHAR(255) DEFAULT NULL,
        `phone` VARCHAR(20) DEFAULT NULL,
        `password` VARCHAR(255) DEFAULT NULL,
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        `mail_otp` VARCHAR(10) DEFAULT NULL,
        `mail_otp_expires_at` VARCHAR(50) DEFAULT NULL,
        `phone_otp` VARCHAR(50) DEFAULT NULL,
        `phone_otp_expires_at` VARCHAR(50) DEFAULT NULL,
        `user_type` ENUM('super_admin','inst_admin') NOT NULL DEFAULT 'inst_admin',
        `user_role` VARCHAR(150) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_email` (`email`),
        UNIQUE KEY `unique_phone` (`phone`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'admin_auth_tokens' => "CREATE TABLE IF NOT EXISTS `admin_auth_tokens` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `admin_id` INT UNSIGNED NOT NULL,
        `auth_token` TEXT NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX (`admin_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'roles_permissions' => "CREATE TABLE IF NOT EXISTS `roles_permissions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `role_name` VARCHAR(100) NOT NULL,
        `permissions` TEXT DEFAULT NULL,
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` ENUM('super_admin','inst_admin') NOT NULL,
        `inst_id` VARCHAR(50) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_role` (`role_name`,`created_by`,`inst_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'users' => "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(200) DEFAULT NULL,
        `profile_image` VARCHAR(255) DEFAULT NULL,
        `email` VARCHAR(255) DEFAULT NULL,
        `phone` VARCHAR(20) DEFAULT NULL,
        `password` VARCHAR(255) DEFAULT NULL,
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        `mail_otp` VARCHAR(10) DEFAULT NULL,
        `mail_otp_expires_at` VARCHAR(50) DEFAULT NULL,
        `phone_otp` VARCHAR(50) DEFAULT NULL,
        `phone_otp_expires_at` VARCHAR(50) DEFAULT NULL,
        `is_mail_verified` TINYINT(1) NOT NULL DEFAULT 0,
        `is_phone_verified` TINYINT(1) NOT NULL DEFAULT 0,
        `user_type` VARCHAR(50) NOT NULL DEFAULT 'student',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_email` (`email`),
        UNIQUE KEY `unique_phone` (`phone`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
];

$created = [];
$errors = [];

foreach ($queries as $table => $sql) {
    if (mysqli_query($conn, $sql)) {
        $created[] = $table;
    } else {
        $errors[$table] = mysqli_error($conn);
    }
}

$seed = isset($_GET['seed']) && $_GET['seed'] === '1';

if ($seed) {
    $adminEmail = 'superadmin@educonnekt.in';
    $adminPhone = '9999999999';
    $adminPassword = password_hash('SuperAdmin@123', PASSWORD_DEFAULT);

    $existsSql = "SELECT id FROM admin_users WHERE email = '$adminEmail' LIMIT 1";
    $existsResult = mysqli_query($conn, $existsSql);

    if ($existsResult && mysqli_num_rows($existsResult) === 0) {
        $insertSql = "INSERT INTO admin_users (name, email, phone, password, status, user_type, user_role) VALUES ('Super Admin','$adminEmail','$adminPhone','$adminPassword',1,'super_admin','Super Admin')";
        if (mysqli_query($conn, $insertSql)) {
            $created[] = 'seed_admin_user';
        } else {
            $errors['seed_admin_user'] = mysqli_error($conn);
        }
    }
}

$response = [
    'success' => empty($errors),
    'created_tables' => $created,
    'errors' => $errors,
];

if ($seed) {
    $response['seed'] = true;
}

if (!empty($errors)) {
    http_response_code(500);
}

echo json_encode($response);
