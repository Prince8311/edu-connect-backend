<?php

require_once __DIR__ . '/../utils/env.php';

class Database
{
    private ?mysqli $conn = null;

    public function __construct()
    {
        $this->loadEnv();
    }

    private function loadEnv(): void
    {
        $envPath = __DIR__ . '/../.env';
        loadEnv($envPath);
    }

    private function ensureDatabaseExists(): void
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $username = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';
        $database = getenv('DB_NAME');

        if (empty($database)) {
            throw new Exception('DB_NAME is not set in .env');
        }

        $connection = mysqli_connect($host, $username, $password);
        if (!$connection) {
            throw new Exception('Could not connect to MySQL server: ' . mysqli_connect_error());
        }

        $sql = sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
            mysqli_real_escape_string($connection, $database)
        );

        if (!mysqli_query($connection, $sql)) {
            $error = mysqli_error($connection);
            mysqli_close($connection);
            throw new Exception('Failed to create database: ' . $error);
        }

        mysqli_close($connection);
    }

    public function getConnection(): mysqli
    {
        if ($this->conn instanceof mysqli) {
            return $this->conn;
        }

        $this->ensureDatabaseExists();

        $host = getenv('DB_HOST') ?: 'localhost';
        $username = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';
        $database = getenv('DB_NAME');

        $this->conn = mysqli_connect($host, $username, $password, $database);
        if (!$this->conn) {
            throw new Exception('Could not connect to database: ' . mysqli_connect_error());
        }

        mysqli_set_charset($this->conn, 'utf8mb4');

        return $this->conn;
    }

    public function runQueries(array $queries): void
    {
        $conn = $this->getConnection();

        foreach ($queries as $sql) {
            if (!mysqli_query($conn, $sql)) {
                throw new Exception('Migration query failed: ' . mysqli_error($conn) . ' | SQL: ' . $sql);
            }
        }
    }

    public function migrate(): array
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS `institutions` (
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
            "CREATE TABLE IF NOT EXISTS `admin_users` (
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
            "CREATE TABLE IF NOT EXISTS `admin_auth_tokens` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `admin_id` INT UNSIGNED NOT NULL,
                `auth_token` TEXT NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX (`admin_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `roles_permissions` (
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
            "CREATE TABLE IF NOT EXISTS `users` (
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

        $this->runQueries($queries);

        return [
            'success' => true,
            'message' => 'Tables created or already exist.',
            'tables' => ['institutions', 'admin_users', 'admin_auth_tokens', 'roles_permissions', 'users']
        ];
    }

    public function seed(): array
    {
        $conn = $this->getConnection();

        $adminEmail = 'superadmin@educonnekt.in';
        $adminPhone = '9999999999';
        $adminPassword = password_hash('SuperAdmin@123', PASSWORD_DEFAULT);

        $existingAdmin = mysqli_query($conn, "SELECT id FROM admin_users WHERE email = '$adminEmail' LIMIT 1");
        if (!$existingAdmin) {
            throw new Exception('Seed check failed: ' . mysqli_error($conn));
        }

        if (mysqli_num_rows($existingAdmin) === 0) {
            $seedSql = "INSERT INTO admin_users (name, email, phone, password, status, user_type, user_role) VALUES ('Super Admin','$adminEmail','$adminPhone','$adminPassword',1,'super_admin','Super Admin')";
            if (!mysqli_query($conn, $seedSql)) {
                throw new Exception('Seed insert failed: ' . mysqli_error($conn));
            }
        }

        $createDefaultRole = "INSERT IGNORE INTO roles_permissions (role_name, permissions, status, created_by) VALUES ('Super Admin', 'all', 1, 'super_admin')";
        if (!mysqli_query($conn, $createDefaultRole)) {
            throw new Exception('Role seed failed: ' . mysqli_error($conn));
        }

        return [
            'success' => true,
            'message' => 'Seed data inserted successfully.'
        ];
    }
}
