<?php

$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_user = getenv('DB_USER') ?: 'scheduler';
$db_pass = getenv('DB_PASS') ?: 'scheduler123';
$db_name = getenv('DB_NAME') ?: 'api_scheduler';

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $tz = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'timezone'")->fetchColumn();
    if ($tz) date_default_timezone_set($tz);
} catch (PDOException $e) {
    if (php_sapi_name() === 'cli') {
        die("DB Connection failed: " . $e->getMessage() . "\n");
    } else {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed']));
    }
}
