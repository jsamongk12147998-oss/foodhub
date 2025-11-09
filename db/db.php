<?php
require_once 'config.php'; // Load your configuration constants

// 💡 FIX FOR YELLOW WARNING: 
// This manually defines the constant for your local IDE/Linter 
// if the PostgreSQL PDO driver is not loaded.
if (!defined('PDO::PGSQL_ATTR_SSL_MODE')) {
    define('PDO::PGSQL_ATTR_SSL_MODE', 12); 
}
// ----------------------------

$conn = null;

if (getenv('DATABASE_URL')) {
    // 1. RENDER (PRODUCTION) - Use PostgreSQL PDO
    $db_url = getenv('postgresql://test_hkpr_user:QtzXuQlVyM0Vs52VYTNcLx3aMNkJEFYd@dpg-d48e4l3e5dus73c5np1g-a/test_hkpr');
    
    // Parse the secure connection string
    $url_parts = parse_url($db_url);

    $host = $url_parts['host'];
    $port = $url_parts['port'];
    $user = $url_parts['user'];
    $pass = $url_parts['pass'];
    $name = ltrim($url_parts['path'], '/');

    // DSN: The typo 'GSQL' has been corrected to 'PGSQL'
    $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

    // Required SSL/TLS connection options for Render
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // Correct constant: PDO::PGSQL_ATTR_SSL_MODE
        PDO::PGSQL_ATTR_SSL_MODE => 'require' 
    ];

    try {
        $conn = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        die("❌ Render DB connection failed: " . $e->getMessage());
    }

} else {
    // 2. LOCAL (DEVELOPMENT) - Use MySQL PDO
    // Note: It's better to use PDO consistently, even locally.
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $user = DB_USER;
    $pass = DB_PASS;
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $conn = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        die("❌ Local DB connection failed: " . $e->getMessage());
    }
}
// $conn is now your database connection object (PDO instance)
?>
