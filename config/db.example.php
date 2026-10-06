<?php
// Copy this file to config/db.php and fill in your own values. config/db.php is gitignored.
// config/db.php — database connection for Antaaya Villas CRM
//
// LOCAL (XAMPP) testing: DB_HOST is the remote Bluehost IP, since your PC
// connects to Bluehost over the internet.
// LIVE (uploaded to Bluehost): DB_HOST must be changed to 'localhost',
// since on Bluehost's own server 'localhost' correctly means "this machine."
// >>> Don't forget to switch this line when you upload to the server. <<<

$DB_HOST = 'localhost';          // remote DB IP for local dev, 'localhost' on the live server
$DB_NAME = 'your_database_name';
$DB_USER = 'your_database_user';
$DB_PASS = 'your_database_password';

// One clock for the whole CRM — India time — whatever the server's own default is: PHP's date()
// (Lead History entries, document uploads, handover times…) and MySQL's NOW() / CURRENT_TIMESTAMP.
date_default_timezone_set('Asia/Kolkata');

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec("SET time_zone = '+05:30'"); // India has no daylight saving, so a fixed offset is exact
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed.']));
}