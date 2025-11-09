<?php
session_start();
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php'; // Path to autoload.php

/* ============================================
   ✅ Brute Force Protection Functions
============================================ */
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
    return $_SERVER['REMOTE_ADDR'];
}

function getLoginAttempts($conn, $ip_address, $email) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if ($tableCheck->num_rows == 0)
        return ['attempts' => 0, 'is_locked' => 0, 'locked_until' => null];

    $stmt = $conn->prepare("SELECT attempts, last_attempt, is_locked, locked_until FROM login_attempts WHERE ip_address = ? AND email = ?");
    if (!$stmt) return ['attempts' => 0, 'is_locked' => 0, 'locked_until' => null];
    $stmt->bind_param("ss", $ip_address, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $attempt = $result->fetch_assoc();
    $stmt->close();
    return $attempt ?: ['attempts' => 0, 'is_locked' => 0, 'locked_until' => null];
}

function recordFailedAttempt($conn, $ip_address, $email) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if ($tableCheck->num_rows == 0) return;

    $existing = getLoginAttempts($conn, $ip_address, $email);
    if ($existing['attempts'] > 0) {
        $new_attempts = $existing['attempts'] + 1;
        $is_locked = ($new_attempts >= 3) ? 1 : 0;
        $locked_until = ($new_attempts >= 5) ? date('Y-m-d H:i:s', strtotime('+30 seconds')) : null;
        $stmt = $conn->prepare("UPDATE login_attempts SET attempts=?, last_attempt=NOW(), is_locked=?, locked_until=? WHERE ip_address=? AND email=?");
        if ($stmt) {
            $stmt->bind_param("issss", $new_attempts, $is_locked, $locked_until, $ip_address, $email);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO login_attempts (ip_address, email, attempts, last_attempt) VALUES (?, ?, 1, NOW())");
        if ($stmt) {
            $stmt->bind_param("ss", $ip_address, $email);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function clearLoginAttempts($conn, $ip_address, $email) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if ($tableCheck->num_rows == 0) return;
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip_address=? AND email=?");
    if ($stmt) {
        $stmt->bind_param("ss", $ip_address, $email);
        $stmt->execute();
        $stmt->close();
    }
}

function isAccountLocked($conn, $ip_address, $email) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'login_attempts'");
    if ($tableCheck->num_rows == 0) return ['locked' => false];
    $attempt = getLoginAttempts($conn, $ip_address, $email);
    if ($attempt['is_locked'] && $attempt['locked_until']) {
        if (strtotime($attempt['locked_until']) > time()) {
            $remaining_time = strtotime($attempt['locked_until']) - time();
            return [
                'locked' => true,
                'message' => 'Account temporarily locked due to too many failed attempts. Please try again in ' . $remaining_time . ' seconds.',
                'locked_until' => $attempt['locked_until'],
                'remaining_seconds' => $remaining_time
            ];
        } else {
            clearLoginAttempts($conn, $ip_address, $email);
            return ['locked' => false];
        }
    }
    return ['locked' => false];
}

/* ============================================
   ✅ 2FA Functions
============================================ */

// Create two_fa_codes table if not exists
$create_table_sql = "CREATE TABLE IF NOT EXISTS two_fa_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL, 
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$conn->query($create_table_sql);

/**
 * Send 2FA code via email using PHPMailer
 */
function send2FACode($conn, $user_id, $email, $user_name, $user_role = 'admin') {
    try {
        // Generate 6-digit code
        $code = sprintf("%06d", mt_rand(1, 999999));
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes
        
        // Store code in database
        $sql = "INSERT INTO two_fa_codes (user_id, code, expires_at) VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE code = ?, expires_at = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("send2FACode prepare failed: " . $conn->error);
            return false;
        }
        $stmt->bind_param("issss", $user_id, $code, $expires, $code, $expires);
        $stmt->execute();
        $stmt->close();
        
        // Create PHPMailer instance
        $mail = new PHPMailer(true);
        
        try {
            // Server settings for Gmail SMTP
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'limhansjomel@gmail.com'; // Your Gmail address
            $mail->Password = 'strileckavrxylyi'; // Your Gmail app password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            
            // Recipients
            $mail->setFrom('noreply@umak.edu.ph', 'University of Makati');
            $mail->addAddress($email, $user_name);
            $mail->addReplyTo('noreply@umak.edu.ph', 'University of Makati');
            
            // Determine subject and content based on user role
            $role_display = ($user_role === 'super_admin') ? 'Super Administrator' : 'Administrator';
            $subject = "University of Makati - {$role_display} Login Verification Code";
            
            $email_body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
                    .container { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: 0 auto; }
                    .header { background: #0d1b4c; color: #FFD700; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { padding: 20px; }
                    .code { background: #f8f9fa; border: 2px dashed #0d1b4c; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 5px; margin: 20px 0; color: #0d1b4c; }
                    .footer { background: #f8f9fa; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; font-size: 12px; color: #666; }
                    .security-note { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin: 15px 0; font-size: 12px; }
                    .super-admin-badge { background: #dc2626; color: white; padding: 5px 10px; border-radius: 4px; font-size: 10px; font-weight: bold; display: inline-block; margin-left: 10px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>University of Makati</h2>
                        <h3>{$role_display} Login Verification " . ($user_role === 'super_admin' ? '<span class="super-admin-badge">SUPER ADMIN</span>' : '') . "</h3>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>$user_name</strong>,</p>
                        <p>A login attempt has been made to your {$role_display} account.</p>
                        <p>Please use the following verification code to complete your login:</p>
                        <div class='code'>$code</div>
                        <p>This code will expire in <strong>10 minutes</strong>.</p>
                        <div class='security-note'>
                            <strong>Security Notice:</strong> If you did not attempt to log in, please contact the IT department immediately and change your password.
                        </div>
                    </div>
                    <div class='footer'>
                        <p>University of Makati {$role_display} Portal<br>
                        This is an automated security message. Please do not reply to this email.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mail->Body = $email_body;
            
            // Plain text version for non-HTML email clients
            $mail->AltBody = "University of Makati {$role_display} Login Verification\n\nHello $user_name,\n\nA login attempt has been made to your {$role_display} account.\n\nYour verification code is: $code\n\nThis code will expire in 10 minutes.\n\nIf you did not attempt to log in, please contact the IT department immediately.\n\nUniversity of Makati {$role_display} Portal";
            
            $mail->send();
            error_log("2FA code sent successfully to {$role_display}: $email");
            return true;
            
        } catch (Exception $e) {
            error_log("PHPMailer Error for {$role_display}: {$mail->ErrorInfo}");
            
            // Fallback to basic mail() function if PHPMailer fails
            $subject = "University of Makati - {$role_display} Login Verification Code";
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: University of Makati <noreply@umak.edu.ph>" . "\r\n";
            
            if (mail($email, $subject, $email_body, $headers)) {
                error_log("2FA code sent via fallback mail() to {$role_display}: $email");
                return true;
            } else {
                error_log("Failed to send 2FA code to {$role_display}: $email");
                return false;
            }
        }
        
    } catch (Exception $e) {
        error_log("send2FACode exception for {$role_display}: " . $e->getMessage());
        return false;
    }
}

/**
 * Verify 2FA code
 */
function verify2FACode($conn, $user_id, $code) {
    try {
        $sql = "SELECT id FROM two_fa_codes WHERE user_id = ? AND code = ? AND expires_at > NOW()";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("verify2FACode prepare failed: " . $conn->error);
            return false;
        }
        $stmt->bind_param("is", $user_id, $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $valid = $result->num_rows > 0;
        $stmt->close();
        
        // Delete used code
        if ($valid) {
            $delete_sql = "DELETE FROM two_fa_codes WHERE user_id = ? AND code = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            if ($delete_stmt) {
                $delete_stmt->bind_param("is", $user_id, $code);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        
        return $valid;
    } catch (Exception $e) {
        error_log("verify2FACode exception: " . $e->getMessage());
        return false;
    }
}

/* ============================================
   ✅ Core Login Logic
============================================ */
if (!isset($conn) || $conn === null || $conn->connect_error) {
    die("Database connection failed. Please check db.php.");
}

$super_admin_email = "limhansjomel@gmail.com";
$super_admin_password = "Superadmin@12345";

$error = "";
$is_locked_state = false;
$lock_message = "";
$show_2fa_form = false;

$MAX_ATTEMPTS = 3;
$LOCKOUT_TIME = 30; // seconds

// Handle 2FA verification
if (isset($_POST['verify_2fa'])) {
    $code = $_POST['verification_code'] ?? '';
    $user_id = $_SESSION['2fa_user_id'] ?? 0;
    
    if (empty($code) || strlen($code) !== 6) {
        $error = "Please enter a valid 6-digit verification code.";
    } elseif (verify2FACode($conn, $user_id, $code)) {
        // 2FA successful - complete login
        $user_id = $_SESSION['2fa_user_id'];
        $user_name = $_SESSION['2fa_user_name'];
        $role = $_SESSION['2fa_role'];
        $email = $_SESSION['2fa_email'];
        $branch_id = $_SESSION['2fa_branch_id'] ?? null;
        $store_name = $_SESSION['2fa_store_name'] ?? null;
        
        // Clear 2FA session data
        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_user_name'], $_SESSION['2fa_role'], 
              $_SESSION['2fa_email'], $_SESSION['2fa_branch_id'], $_SESSION['2fa_store_name']);
        
        // Create final session
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_name'] = $user_name;
        $_SESSION['role'] = $role;
        $_SESSION['email'] = $email;
        $_SESSION['branch_id'] = $branch_id;
        $_SESSION['store_name'] = $store_name;
        $_SESSION['login_time'] = time();
        
        // Redirect based on role
        switch ($role) {
            case 'admin':
            case 'branch_admin':
                header("Location: ../admin/dashboard.php");
                break;
            case 'super_admin':
                header("Location: ../superadmin/Super_Admin.php");
                break;
            default:
                header("Location: ../admin/dashboard.php");
        }
        exit();
    } else {
        $error = "Invalid verification code. Please try again.";
        $ip_address = getClientIP();
        recordFailedAttempt($conn, $ip_address, $_SESSION['2fa_email'] ?? '');
    }
}

// Handle resend 2FA code
if (isset($_POST['resend_2fa'])) {
    $user_id = $_SESSION['2fa_user_id'] ?? 0;
    $email = $_SESSION['2fa_email'] ?? '';
    $user_name = $_SESSION['2fa_user_name'] ?? '';
    $user_role = $_SESSION['2fa_role'] ?? 'admin';
    
    if ($user_id && send2FACode($conn, $user_id, $email, $user_name, $user_role)) {
        $_SESSION['resend_success'] = "A new verification code has been sent to your email.";
    } else {
        $error = "Failed to send verification code. Please try again.";
    }
}

// Handle initial login
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST['verify_2fa']) && !isset($_POST['resend_2fa'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $ip_address = getClientIP();

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $lockCheck = isAccountLocked($conn, $ip_address, $email);
        if ($lockCheck['locked']) {
            $error = "⚠️ Account locked due to too many failed attempts. Please try again in " . $lockCheck['remaining_seconds'] . " seconds.";
            $is_locked_state = true;
            $lock_message = $error;
        } else {
            try {
                // Check for all admin types including super_admin
                $sql = "SELECT * FROM users WHERE email = ? AND (role='admin' OR role='branch_admin' OR role='super_admin') LIMIT 1";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result && $result->num_rows === 1) {
                        $user = $result->fetch_assoc();
                        if (password_verify($password, $user['password'])) {
                            clearLoginAttempts($conn, $ip_address, $email);
                            
                            // REQUIRE 2FA FOR ALL ADMIN USERS (including super_admin)
                            if (send2FACode($conn, $user['id'], $user['email'], $user['name'], $user['role'])) {
                                $_SESSION['2fa_user_id'] = $user['id'];
                                $_SESSION['2fa_user_name'] = $user['name'];
                                $_SESSION['2fa_role'] = $user['role'];
                                $_SESSION['2fa_email'] = $user['email'];
                                $_SESSION['2fa_branch_id'] = $user['branch_id'] ?? null;
                                $_SESSION['2fa_store_name'] = $user['store_name'] ?? null;
                                $show_2fa_form = true;
                            } else {
                                $error = "Failed to send verification code. Please try again or contact support.";
                            }
                        } else {
                            recordFailedAttempt($conn, $ip_address, $email);
                            $attempts = getLoginAttempts($conn, $ip_address, $email);
                            $remaining_attempts = $MAX_ATTEMPTS - $attempts['attempts'];
                            if ($remaining_attempts <= 0) {
                                $error = "⚠️ Too many failed attempts. Account locked for 30 seconds.";
                                $is_locked_state = true;
                            } else {
                                $error = "Invalid password. {$remaining_attempts} attempts remaining.";
                            }
                        }
                    } else {
                        // Check hardcoded super_admin credentials
                        if ($email === $super_admin_email && $password === $super_admin_password) {
                            clearLoginAttempts($conn, $ip_address, $email);
                            
                            // For hardcoded super_admin, we'll create a temporary user ID and send 2FA
                            $temp_user_id = -1; // Special ID for hardcoded super_admin
                            $user_name = 'Super Administrator';
                            $user_role = 'super_admin';
                            
                            if (send2FACode($conn, $temp_user_id, $email, $user_name, $user_role)) {
                                $_SESSION['2fa_user_id'] = $temp_user_id;
                                $_SESSION['2fa_user_name'] = $user_name;
                                $_SESSION['2fa_role'] = $user_role;
                                $_SESSION['2fa_email'] = $email;
                                $show_2fa_form = true;
                            } else {
                                $error = "Failed to send verification code. Please try again or contact support.";
                            }
                        } else {
                            recordFailedAttempt($conn, $ip_address, $email);
                            $error = "No administrator account found with this email.";
                        }
                    }
                    $stmt->close();
                } else {
                    $error = "Database query error. Please try again.";
                }
            } catch (Exception $e) {
                $error = "System error. Please try again later.";
                error_log("Admin login error: " . $e->getMessage());
            }
        }
    }
}

// Get current attempts for display
$current_ip = getClientIP();
$current_email = isset($_POST['email']) ? trim($_POST['email']) : '';
$attempts_info = getLoginAttempts($conn, $current_ip, $current_email);
$remaining_attempts_display = $MAX_ATTEMPTS - $attempts_info['attempts'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login - University of Makati</title>
<style>
   * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    font-family: Arial, sans-serif;
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    background: url('Umak_Dining_area.jpg') no-repeat center center fixed;
    background-size: cover;
    position: relative;
  }

  body::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.45);
    z-index: 0;
  }

  .login-box {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 12px;
    box-shadow: 0 3px 8px rgba(0,0,0,0.3);
    width: 90%;
    max-width: 800px;
    display: flex;
    overflow: hidden;
    position: relative;
    z-index: 1;
  }

  .left {
    background: linear-gradient(135deg, #0d1b4c 20%, #FFD700 50%, #0d1b4c 85%);
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    flex-direction: column;
    min-height: 500px;
  }

  .left img {
    max-width: 280px;
    height: auto;
    filter: drop-shadow(0 0 8px rgba(0,0,0,0.3));
  }

  .admin-badge {
    background: rgba(255, 215, 0, 0.9);
    color: #0d1b4c;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    margin-top: 15px;
    text-align: center;
  }

  .right {
    flex: 1;
    padding: 40px 30px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 500px;
  }

  h2 {
    margin-bottom: 20px;
    text-align: center;
    color: #0d1b4c;
    font-size: 24px;
    line-height: 1.3;
  }

  .input-group {
    position: relative;
    margin-bottom: 20px;
  }

  label {
    font-size: 12px;
    color: #666;
    margin-bottom: 5px;
    display: block;
    text-align: left;
    font-weight: 600;
  }

  input {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
    transition: border-color 0.3s;
  }

  input:focus {
    outline: none;
    border-color: #0d1b4c;
  }

  .password-toggle {
    position: absolute;
    right: 12px;
    top: 35px;
    background: none;
    border: none;
    cursor: pointer;
    color: #666;
    padding: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
  }

  button[type="submit"] {
    width: 100%;
    padding: 12px;
    background: #0d1b4c;
    color: #FFD700;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    margin-bottom: 10px;
    font-size: 16px;
    transition: background 0.3s;
  }

  button[type="submit"]:hover {
    background: #0a1540;
  }

  .error {
    color: #d63031;
    text-align: center;
    margin-bottom: 15px;
    background: #ffe6e6;
    padding: 12px;
    border-radius: 6px;
    border: 1px solid #ffcccc;
    font-size: 14px;
  }

  .success {
    color: #2e7d32;
    text-align: center;
    margin-bottom: 15px;
    background: #e8f5e8;
    padding: 12px;
    border-radius: 6px;
    border: 1px solid #c8e6c9;
    font-size: 14px;
  }

  .warning {
    color: #b45309;
    text-align: center;
    margin-bottom: 15px;
    background: #fffbeb;
    padding: 12px;
    border-radius: 6px;
    border: 1px solid #fcd34d;
    font-size: 14px;
  }

  .info {
    color: #0d1b4c;
    text-align: center;
    margin-bottom: 20px;
    background: #e6f2ff;
    padding: 12px;
    border-radius: 6px;
    border: 1px solid #cce0ff;
    font-size: 12px;
    line-height: 1.4;
  }

  .attempts-info {
    color: #0d1b4c;
    text-align: center;
    margin-bottom: 15px;
    background: #f0f8ff;
    padding: 10px;
    border-radius: 6px;
    border: 1px solid #b3d9ff;
    font-size: 12px;
    font-weight: 600;
  }

  .verification-info {
    background: #e8f5e8;
    border: 1px solid #c8e6c9;
    color: #2e7d32;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    text-align: center;
  }

  .super-admin-notice {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #dc2626;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: bold;
  }

  .resend-link {
    text-align: center;
    margin: 15px 0;
  }

  .resend-link button {
    background: none;
    border: none;
    color: #0d1b4c;
    text-decoration: underline;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
  }

  .resend-link button:hover {
    color: #0a1540;
  }

  .resend-link button:disabled {
    color: #ccc;
    cursor: not-allowed;
  }

  .info strong {
    display: block;
    margin-bottom: 5px;
  }

  .student-link {
    display: block;
    text-align: center;
    margin-top: 20px;
    padding: 10px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    color: #0d1b4c;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s;
  }

  .student-link:hover {
    background: #e9ecef;
    text-decoration: none;
  }

  a {
    display: block;
    text-align: center;
    margin-top: 15px;
    color: #0d1b4c;
    font-size: 14px;
    text-decoration: none;
    font-weight: 600;
  }

  a:hover {
    text-decoration: underline;
  }

  /* Responsive Design */
  @media (max-width: 768px) {
    .login-box {
      flex-direction: column;
      width: 95%;
      margin: 20px;
    }
    
    .left {
      min-height: 200px;
      padding: 20px;
    }
    
    .left img {
      max-width: 150px;
    }
    
    .right {
      padding: 30px 20px;
      min-height: auto;
    }
    
    h2 {
      font-size: 20px;
    }
  }

  @media (max-width: 480px) {
    .right {
      padding: 20px 15px;
    }
    
    h2 {
      font-size: 18px;
    }
    
    .info {
      font-size: 11px;
    }
  }
</style>
</head>
<body>
  <div class="login-box">
    <div class="left">
      <img src="6.png" alt="University of Makati Logo">
      <div class="admin-badge">Administrator Login Portal</div>
    </div>
    <div class="right">
      
      <?php if ($show_2fa_form): ?>
        <!-- 2FA Verification Form -->
        <h2>Two-Factor Verification</h2>
        
        <div class="verification-info">
          <strong>Verification Code Sent</strong><br>
          We've sent a 6-digit verification code to your email:<br>
          <strong><?php echo htmlspecialchars($_SESSION['2fa_email'] ?? ''); ?></strong><br>
          Please check your inbox and enter the code below.
        </div>

        <?php if (($_SESSION['2fa_role'] ?? '') === 'super_admin'): ?>
          <div class="super-admin-notice">
            ⚠️ SUPER ADMIN ACCESS - Highest Security Level
          </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['resend_success'])): ?>
          <div class="success"><?php echo htmlspecialchars($_SESSION['resend_success']); unset($_SESSION['resend_success']); ?></div>
        <?php endif; ?>

        <form method="POST" id="verifyForm">
          <input type="hidden" name="verify_2fa" value="1">
          <div class="input-group">
            <label for="verification_code">Verification Code</label>
            <input type="text" name="verification_code" id="verification_code" 
                   placeholder="Enter 6-digit code" maxlength="6" required 
                   pattern="[0-9]{6}" title="Please enter exactly 6 digits"
                   autocomplete="one-time-code">
          </div>

          <button type="submit">Verify Code</button>
        </form>

        <div class="resend-link">
          <form method="POST" style="display: inline;">
            <input type="hidden" name="resend_2fa" value="1">
            <button type="submit" id="resendBtn">Resend Verification Code</button>
          </form>
        </div>

        <a href="login.php">← Back to Login</a>

      <?php else: ?>
        <!-- Regular Login Form -->
        <h2>University of Makati<br>Administrator Login</h2>
        <div class="info">
          <strong>Security Features:</strong>
          • Maximum 3 login attempts<br>
          • 30-second lockout after failed attempts<br>
          • IP-based tracking<br>
          • 2FA verification for ALL admin accounts<br>
          • Super Admin requires additional verification
        </div>

        <?php if ($remaining_attempts_display < 3  && $remaining_attempts_display > 0): ?>
          <div class="attempts-info">
            ⚠️ You have <?= $remaining_attempts_display ?> attempt(s) remaining
          </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <div class="<?= strpos($error, 'locked') !== false ? 'warning' : 'error' ?>">
            <?= htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
          <div class="input-group">
            <label for="email">Email Address</label>
            <input type="text" name="email" id="email" placeholder="Enter administrator email"
                   value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                   <?= $is_locked_state ? 'disabled' : ''; ?> required>
          </div>

          <div class="input-group">
            <label for="password">Password</label>
            <input type="password" name="password" id="password" placeholder="Enter administrator password"
                   <?= $is_locked_state ? 'disabled' : ''; ?> required>
            <button type="button" class="password-toggle" id="togglePassword">👁️</button>
          </div>

          <button type="submit" <?= $is_locked_state ? 'disabled style="background:#ccc;color:#666;cursor:not-allowed;"' : ''; ?>>
            <?= $is_locked_state ? '🔒 Login Disabled (Locked)' : 'Admin Login'; ?>
          </button>
        </form>

        <a href="forgot_password.php">Forgot your password?</a>
        <a href="Studlogin.php" class="student-link">Student Login</a>
        <a href="../index.html" class="admin-link">Home</a>
      <?php endif; ?>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const togglePassword = document.getElementById('togglePassword');
      const passwordInput = document.getElementById('password');
      
      if (togglePassword) {
        togglePassword.addEventListener('click', function() {
          if (passwordInput.disabled) return;
          const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
          passwordInput.setAttribute('type', type);
          togglePassword.textContent = type === 'password' ? '👁️' : '🔒';
        });
      }

      // Auto-focus verification code input and auto-submit
      const verificationCodeInput = document.getElementById('verification_code');
      if (verificationCodeInput) {
        verificationCodeInput.focus();
        
        // Auto-advance and format
        verificationCodeInput.addEventListener('input', function(e) {
          this.value = this.value.replace(/[^0-9]/g, '');
          if (this.value.length === 6) {
            document.getElementById('verifyForm').submit();
          }
        });
      }

      // Prevent rapid resend clicks
      const resendBtn = document.getElementById('resendBtn');
      if (resendBtn) {
        resendBtn.addEventListener('click', function() {
          this.disabled = true;
          this.innerHTML = 'Sending...';
          setTimeout(() => {
            this.disabled = false;
            this.innerHTML = 'Resend Verification Code';
          }, 30000); // 30 seconds cooldown
        });
      }

      // Auto-refresh when lock time expires
      <?php if ($is_locked_state): ?>
        setTimeout(function() {
          window.location.reload();
        }, <?= $LOCKOUT_TIME * 1000 ?>);
      <?php endif; ?>
    });
  </script>
</body>
</html>