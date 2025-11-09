<?php
// Include the config file to get constants like DB_HOST, DB_NAME, etc.
require_once __DIR__ . '/config.php';

$conn = null; // Initialize the database connection object

try {
    // 1. Check for Render's DATABASE_URL environment variable (PostgreSQL)
    $db_url = getenv('DATABASE_URL');
    
    if ($db_url) {
        // --- Render PostgreSQL Connection (Production) ---
        // Parse the connection string: postgres://user:password@host:port/dbname
        $url_parts = parse_url($db_url);
        
        $host = $url_parts['host'];
        $port = $url_parts['port'];
        $user = $url_parts['user'];
        $pass = $url_parts['pass'];
        $name = ltrim($url_parts['path'], '/'); 

        // Construct the DSN for PDO PostgreSQL
        $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

        // Required SSL/TLS connection options for Render
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::PGSQL_ATTR_SSL_MODE => 'require'
        ];

        $conn = new PDO($dsn, $user, $pass, $options);
        
        // This line is for debugging; remove it in a final app
        // echo "✅ Connected to Render PostgreSQL database.";

    } else {
        // --- Local MySQL Connection (Development/Fallback) ---
        // Use constants defined in config.php
        
        // Construct the DSN for PDO MySQL
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        // Use constants from config.php
        $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        // This line is for debugging; remove it in a final app
        // echo "✅ Connected to local MySQL database.";
    }
    
} catch (PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage());
}

// $conn is now the PDO connection object you will use for all database queries.
?>