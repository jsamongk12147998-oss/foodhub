<?php
// check_phpmailer.php
echo "=== Checking PHPMailer Installation ===\n\n";

// Check if vendor directory exists
if (file_exists('vendor/autoload.php')) {
    echo "✅ vendor/autoload.php exists\n";
    
    require_once 'vendor/autoload.php';
    
    // Check if PHPMailer classes are available
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        echo "✅ PHPMailer class is available\n";
    } else {
        echo "❌ PHPMailer class NOT found\n";
    }
    
    if (class_exists('PHPMailer\PHPMailer\SMTP')) {
        echo "✅ SMTP class is available\n";
    } else {
        echo "❌ SMTP class NOT found\n";
    }
    
    if (class_exists('PHPMailer\PHPMailer\Exception')) {
        echo "✅ Exception class is available\n";
    } else {
        echo "❌ Exception class NOT found\n";
    }
    
} else {
    echo "❌ vendor/autoload.php not found - PHPMailer not installed\n";
}
?>