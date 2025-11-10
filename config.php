<?php
// config.php - Database Configuration

// Database Constants
define('DB_HOST', 'localhost');
define('DB_NAME', 'test');  // ← Change to your actual database name
define('DB_USER', 'root');  // ← Usually 'root' for XAMPP
define('DB_PASS', '');      // ← Usually empty for XAMPP
define('DB_CHARSET', 'utf8mb4');

// ✅ ADDED: DB_PATH constant that was missing
define('DB_PATH', __DIR__ . '/db/');

// Define important folder paths
define('ROOT_PATH', __DIR__ . '/');
define('USER_PATH', __DIR__ . '/users/');
define('SUPERADMIN_PATH', __DIR__ . '/superadmin/');
define('ADMIN_PATH', __DIR__ . '/admin/');
define('LOGIN_PATH', __DIR__ . '/login/');

// SMTP Configuration (for email)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'limhansjomel@gmail.com');    // ← Your Gmail
define('SMTP_PASSWORD', 'gvxpyarwhenrtdij');       // ← Your App Password
define('SMTP_FROM_EMAIL', 'limhansjomel@gmail.com');  // ← Your Gmail
define('SMTP_FROM_NAME', 'UMAK Foodhub');
define('SMTP_SECURE', 'tls');

// Site Configuration
define('SITE_NAME', 'UMAK Foodhub');
// In your config.php - CHANGE THIS LINE:
define('SITE_URL', 'https://www.umakfoodhub.kesug.com');  // Make sure it's HTTPS

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('Asia/Manila');
?>
