<?php
$env = parse_ini_file(__DIR__ . '/.env');
if (!$env) {
    die("Environment file not found or invalid.");
}
$host = $env['DB_HOST'] ?? 'localhost';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';
$db   = $env['DB_NAME'] ?? '';
$port = $env['DB_PORT'] ?? 3306;
$conn = new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
