<?php

require_once __DIR__ . '/utils/env.php';
loadEnv(__DIR__ . '/.env');

$server = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME') ?: '';

$conn = mysqli_connect($server, $username, $password, $database);

if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
}
