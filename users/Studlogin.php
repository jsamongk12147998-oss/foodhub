<?php
session_start();
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php'; // Path to autoload.php

// Ensure DB connection exists
if (!isset($conn) || $conn === null || $conn->connect_error) {
    die("Database connection failed. Please check db.php.");
}

$error = "";

/**
 * Get client IP (safely handle X-Forwarded-For list)
 */
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

/**
 * Check whether an IP is blocked.
 */
function isIpBlocked($conn, $ip) {
    try {
        $block_time = 30;
        $maxAttempts = 5;
        $cutoff = date('Y-m-d H:i:s', time() - $block_time);

        $sql = "SELECT COALESCE(SUM(attempts), 0) AS attempts_sum 
                FROM login_attempts 
                WHERE ip_address = ? AND last_attempt > ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("isIpBlocked prepare failed: " . $conn->error);
            return false;
        }
        $stmt->bind_param("ss", $ip, $cutoff);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        return ((int)$data['attempts_sum'] >= $maxAttempts);
    } catch (Exception $e) {
        error_log("isIpBlocked exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Record a failed attempt
 */
function recordFailedAttempt($conn, $ip, $email) {
    try {
        $block_time = 30;
        $cutoff = date('Y-m-d H:i:s', time() - $block_time);

        $check_sql = "SELECT id, attempts, last_attempt FROM login_attempts WHERE ip_address = ? AND email = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_sql);
        if (!$check_stmt) {
            error_log("recordFailedAttempt prepare(check) failed: " . $conn->error);
            return false;
        }
        $check_stmt->bind_param("ss", $ip, $email);
        $check_stmt->execute();
        $res = $check_stmt->get_result();

        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $existingId = (int)$row['id'];
            $existingAttempts = (int)$row['attempts'];
            $lastAttempt = $row['last_attempt'];

            if ($lastAttempt !== null && $lastAttempt > $cutoff) {
                $newAttempts = $existingAttempts + 1;
            } else {
                $newAttempts = 1;
            }

            $update_sql = "UPDATE login_attempts SET attempts = ?, last_attempt = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if (!$update_stmt) {
                error_log("recordFailedAttempt prepare(update) failed: " . $conn->error);
                $check_stmt->close();
                return false;
            }
            $update_stmt->bind_param("ii", $newAttempts, $existingId);
            $update_stmt->execute();
            $update_stmt->close();
        } else {
            $insert_sql = "INSERT INTO login_attempts (ip_address, email, attempts, last_attempt) VALUES (?, ?, 1, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            if (!$insert_stmt) {
                error_log("recordFailedAttempt prepare(insert) failed: " . $conn->error);
                $check_stmt->close();
                return false;
            }
            $insert_stmt->bind_param("ss", $ip, $email);
            $insert_stmt->execute();
            $insert_stmt->close();
        }

        $check_stmt->close();
        return true;
    } catch (Exception $e) {
        error_log("recordFailedAttempt exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Clear failed attempts
 */
function clearFailedAttempts($conn, $ip, $email) {
    try {
        $sql = "DELETE FROM login_attempts WHERE ip_address = ? AND email = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("clearFailedAttempts prepare failed: " . $conn->error);
            return false;
        }
        $stmt->bind_param("ss", $ip, $email);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Exception $e) {
        error_log("clearFailedAttempts exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Get remaining attempts
 */
function getRemainingAttempts($conn, $ip) {
    try {
        $block_time = 30;
        $maxAttempts = 3;
        $cutoff = date('Y-m-d H:i:s', time() - $block_time);

        $sql = "SELECT COALESCE(SUM(attempts), 0) AS attempts_sum 
                FROM login_attempts 
                WHERE ip_address = ? AND last_attempt > ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("getRemainingAttempts prepare failed: " . $conn->error);
            return $maxAttempts;
        }
        $stmt->bind_param("ss", $ip, $cutoff);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        $used = (int)$data['attempts_sum'];
        return max(0, $maxAttempts - $used);
    } catch (Exception $e) {
        error_log("getRemainingAttempts exception: " . $e->getMessage());
        return 5;
    }
}

/**
 * Send 2FA code via email using PHPMailer
 */
function send2FACode($conn, $user_id, $email, $user_name) {
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
            
            /*
            $mail->isSMTP();
            $mail->Host = 'localhost';
            $mail->SMTPAuth = false;
            $mail->SMTPAutoTLS = false;
            $mail->Port = 25;
            */
            
         
            $mail->setFrom('noreply@umak.edu.ph', 'University of Makati');
            $mail->addAddress($email, $user_name);
            $mail->addReplyTo('noreply@umak.edu.ph', 'University of Makati');
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Your University of Makati Login Verification Code';
            
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
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>University of Makati</h2>
                        <h3>Login Verification Code</h3>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>$user_name</strong>,</p>
                        <p>You are attempting to log in to your University of Makati student account.</p>
                        <p>Please use the following verification code to complete your login:</p>
                        <div class='code'>$code</div>
                        <p>This code will expire in <strong>10 minutes</strong>.</p>
                        <p>If you did not attempt to log in, please ignore this email or contact the university IT department immediately.</p>
                    </div>
                    <div class='footer'>
                        <p>University of Makati Student Portal<br>
                        This is an automated message. Please do not reply to this email.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mail->Body = $email_body;
            
            // Plain text version for non-HTML email clients
            $mail->AltBody = "University of Makati Login Verification\n\nHello $user_name,\n\nYou are attempting to log in to your University of Makati student account.\n\nYour verification code is: $code\n\nThis code will expire in 10 minutes.\n\nIf you did not attempt to log in, please ignore this email.";
            
            $mail->send();
            error_log("2FA code sent successfully to: $email");
            return true;
            
        } catch (Exception $e) {
            error_log("PHPMailer Error: {$mail->ErrorInfo}");
            
            // Fallback to basic mail() function if PHPMailer fails
            $subject = "Your University of Makati Login Verification Code";
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: University of Makati <noreply@umak.edu.ph>" . "\r\n";
            
            if (mail($email, $subject, $email_body, $headers)) {
                error_log("2FA code sent via fallback mail() to: $email");
                return true;
            } else {
                error_log("Failed to send 2FA code to: $email");
                return false;
            }
        }
        
    } catch (Exception $e) {
        error_log("send2FACode exception: " . $e->getMessage());
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

// --- Main logic ---
$client_ip = getClientIP();

// Handle 2FA verification
if (isset($_POST['verify_2fa'])) {
    $code = $_POST['verification_code'] ?? '';
    $user_id = $_SESSION['2fa_user_id'] ?? 0;
    
    if (empty($code) || strlen($code) !== 6) {
        $error = "Please enter a valid 6-digit code.";
    } elseif (verify2FACode($conn, $user_id, $code)) {
        // 2FA successful - complete login
        $user_id = $_SESSION['2fa_user_id'];
        $user_name = $_SESSION['2fa_user_name'];
        $role = $_SESSION['2fa_role'];
        $email = $_SESSION['2fa_email'];
        
        // Clear 2FA session data
        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_user_name'], $_SESSION['2fa_role'], $_SESSION['2fa_email']);
        
        // Create final session
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_name'] = $user_name;
        $_SESSION['role'] = $role;
        $_SESSION['email'] = $email;
        $_SESSION['login_time'] = time();
        
        header("Location: ../users/UserInt.php");
        exit();
    } else {
        $error = "Invalid verification code. Please try again.";
        recordFailedAttempt($conn, $client_ip, $_SESSION['2fa_email'] ?? '');
    }
}

// Handle resend 2FA code
if (isset($_POST['resend_2fa'])) {
    $user_id = $_SESSION['2fa_user_id'] ?? 0;
    $email = $_SESSION['2fa_email'] ?? '';
    $user_name = $_SESSION['2fa_user_name'] ?? '';
    
    if ($user_id && send2FACode($conn, $user_id, $email, $user_name)) {
        $_SESSION['resend_success'] = "A new verification code has been sent to your email.";
    } else {
        $error = "Failed to send verification code. Please try again.";
    }
}

// Handle initial login
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST['verify_2fa']) && !isset($_POST['resend_2fa'])) {
    // Check block first
    if (isIpBlocked($conn, $client_ip)) {
        $error = "Too many failed login attempts. Please try again in 30 seconds.";
    } else {
        // Get inputs
        $email_raw = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $email = trim($email_raw);

        // Basic validation
        if (empty($email) || empty($password)) {
            $error = "Please enter both email and password.";
        } else {
            // Enforce @umak.edu.ph only
            if (!preg_match('/^[A-Za-z0-9._%+\-]+@umak\.edu\.ph$/i', $email)) {
                $error = "Please use your @umak.edu.ph email address.";
                recordFailedAttempt($conn, $client_ip, $email);
            } else {
                try {
                    $sql = "SELECT id, name, email, password, role, email_verified 
                            FROM users 
                            WHERE email = ? AND role = 'user' LIMIT 1";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        error_log("Login prepare failed: " . $conn->error);
                        $error = "Database error. Please try again later.";
                    } else {
                        $stmt->bind_param("s", $email);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        if ($result && $result->num_rows === 1) {
                            $user = $result->fetch_assoc();

                            if (empty($user['email_verified']) || (int)$user['email_verified'] === 0) {
                                $error = "Please verify your email before logging in. Check your inbox for the verification link.";
                                recordFailedAttempt($conn, $client_ip, $email);
                            } else {
                                // Verify password
                                if (password_verify($password, $user['password'])) {
                                    // Clear failed attempts
                                    clearFailedAttempts($conn, $client_ip, $email);
                                    
                                    // ALWAYS REQUIRE 2FA
                                    // Send 2FA code via email and show verification form
                                    if (send2FACode($conn, $user['id'], $user['email'], $user['name'])) {
                                        $_SESSION['2fa_user_id'] = $user['id'];
                                        $_SESSION['2fa_user_name'] = $user['name'];
                                        $_SESSION['2fa_role'] = $user['role'];
                                        $_SESSION['2fa_email'] = $user['email'];
                                        $show_2fa_form = true;
                                    } else {
                                        $error = "Failed to send verification code. Please try again or contact support.";
                                    }
                                } else {
                                    $error = "Invalid password.";
                                    recordFailedAttempt($conn, $client_ip, $email);
                                }
                            }
                        } else {
                            // No account found
                            $error = "No student account found with this email.";
                            recordFailedAttempt($conn, $client_ip, $email);
                        }
                        $stmt->close();
                    }
                } catch (Exception $e) {
                    error_log("Login exception: " . $e->getMessage());
                    $error = "System error. Please try again later.";
                }
            }
        }
    }
}

// For display
$remaining_attempts = getRemainingAttempts($conn, $client_ip);
$is_blocked = isIpBlocked($conn, $client_ip);
$show_2fa_form = $show_2fa_form ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Login - University of Makati</title>
<style>
  /* Your existing CSS styles remain the same */
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, sans-serif; height: 100vh; display: flex; justify-content: center; align-items: center; background: url('Umak_Dining_area.jpg') no-repeat center center fixed; background-size: cover; position: relative; }
  body::before { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.45); z-index: 0; }
  .login-box { background: rgba(255, 255, 255, 0.95); border-radius: 12px; box-shadow: 0 3px 8px rgba(0,0,0,0.3); width: 90%; max-width: 800px; display: flex; overflow: hidden; position: relative; z-index: 1; }
  .left { background: linear-gradient(135deg, #0d1b4c 20%, #FFD700 50%, #0d1b4c 85%); flex: 1; display: flex; align-items: center; justify-content: center; padding: 20px; flex-direction: column; min-height: 500px; }
  .left img { max-width: 280px; height: auto; filter: drop-shadow(0 0 8px rgba(0,0,0,0.3)); }
  .student-badge { background: rgba(255, 215, 0, 0.9); color: #0d1b4c; padding: 8px 15px; border-radius: 20px; font-size: 12px; font-weight: bold; margin-top: 15px; text-align: center; }
  .right { flex: 1; padding: 40px 30px; display: flex; flex-direction: column; justify-content: center; min-height: 500px; }
  h2 { margin-bottom: 20px; text-align: center; color: #0d1b4c; font-size: 24px; line-height: 1.3; }
  .input-group { position: relative; margin-bottom: 20px; }
  label { font-size: 12px; color: #666; margin-bottom: 5px; display: block; text-align: left; font-weight: 600; }
  input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; box-sizing: border-box; transition: border-color 0.3s; }
  input:focus { outline: none; border-color: #0d1b4c; }
  .password-toggle { position: absolute; right: 12px; top: 35px; background: none; border: none; cursor: pointer; color: #666; padding: 0; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
  button[type="submit"] { width: 100%; padding: 12px; background: #0d1b4c; color: #FFD700; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; margin-bottom: 10px; font-size: 16px; transition: background 0.3s; }
  button[type="submit"]:hover { background: #0a1540; }
  .error { color: #d63031; text-align: center; margin-bottom: 15px; background: #ffe6e6; padding: 12px; border-radius: 6px; border: 1px solid #ffcccc; font-size: 14px; }
  .success { color: #2e7d32; text-align: center; margin-bottom: 15px; background: #e8f5e8; padding: 12px; border-radius: 6px; border: 1px solid #c8e6c9; font-size: 14px; }
  .warning { color: #e67e22; text-align: center; margin-bottom: 15px; background: #fff4e6; padding: 12px; border-radius: 6px; border: 1px solid #ffd8b3; font-size: 14px; }
  .info { color: #0d1b4c; text-align: center; margin-bottom: 20px; background: #e6f2ff; padding: 12px; border-radius: 6px; border: 1px solid #cce0ff; font-size: 12px; line-height: 1.4; }
  .info strong { display: block; margin-bottom: 5px; }
  .admin-link { display: block; text-align: center; margin-top: 20px; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; color: #0d1b4c; text-decoration: none; font-weight: 600; transition: all 0.3s; }
  .admin-link:hover { background: #e9ecef; text-decoration: none; }
  a { display: block; text-align: center; margin-top: 15px; color: #0d1b4c; font-size: 14px; text-decoration: none; font-weight: 600; }
  a:hover { text-decoration: underline; }
  .verification-info { background: #e8f5e8; border: 1px solid #c8e6c9; color: #2e7d32; padding: 15px; border-radius: 6px; margin-bottom: 20px; text-align: center; }
  .resend-link { text-align: center; margin: 15px 0; }
  .resend-link button { background: none; border: none; color: #0d1b4c; text-decoration: underline; cursor: pointer; font-size: 14px; font-weight: 600; }
  .resend-link button:hover { color: #0a1540; }
  .resend-link button:disabled { color: #ccc; cursor: not-allowed; }
  @media (max-width: 768px) { .login-box { flex-direction: column; width: 95%; margin: 20px; } .left { min-height: 200px; padding: 20px; } .left img { max-width: 150px; } .right { padding: 30px 20px; min-height: auto; } h2 { font-size: 20px; } }
  @media (max-width: 480px) { .right { padding: 20px 15px; } h2 { font-size: 18px; } .info { font-size: 11px; } }
</style>
</head>
<body>
  <div class="login-box">
    <div class="left">
      <img src="6.png" alt="University of Makati Logo">
      <div class="student-badge">Student Login Portal</div>
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

        <a href="Studlogin.php">← Back to Login</a>

      <?php else: ?>
        <!-- Regular Login Form -->
        <h2>University of Makati<br>Student Login</h2>
        <div class="info">
          <strong>Student Login Requirements:</strong>
          • @umak.edu.ph accounts only<br>
          • Email verification required<br>
          • 30-second lockout after 3 failed attempts<br>
          • 2FA verification required for all logins
        </div>

        <?php if (!empty($error)): ?>
          <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($is_blocked): ?>
          <div class="warning">
            <strong>Account Temporarily Locked</strong><br>
            Too many failed login attempts. Please try again in 30 seconds.
          </div>
        <?php elseif ($remaining_attempts < 3): ?>
          <div class="warning">
            <strong>Security Notice</strong><br>
            You have <?php echo $remaining_attempts; ?> login attempt(s) remaining before temporary lockout.
          </div>
        <?php endif; ?>

        <form method="POST" id="loginForm" <?php echo $is_blocked ? 'onsubmit="return false;"' : ''; ?>>
          <div class="input-group">
            <label for="email">Email Address</label>
            <input type="text" name="email" id="email" placeholder="Enter your @umak.edu.ph email" 
                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                   required <?php echo $is_blocked ? 'disabled' : ''; ?>>
          </div>

          <div class="input-group">
            <label for="password">Password</label>
            <input type="password" name="password" id="password" placeholder="Enter your password" required 
                   <?php echo $is_blocked ? 'disabled' : ''; ?>>
            <button type="button" class="password-toggle" id="togglePassword" <?php echo $is_blocked ? 'disabled' : ''; ?>>👁️</button>
          </div>

          <button type="submit" <?php echo $is_blocked ? 'disabled style="background: #ccc; cursor: not-allowed;"' : ''; ?>>
            <?php echo $is_blocked ? 'Login Temporarily Disabled' : 'Student Login'; ?>
          </button>
        </form>

        <a href="signup.php" <?php echo $is_blocked ? 'style="color: #ccc; pointer-events: none;"' : ''; ?>>Don't have an account? Register here</a>
        <a href="forgot_password.php" <?php echo $is_blocked ? 'style="color: #ccc; pointer-events: none;"' : ''; ?>>Forgot your password?</a>
        
        <a href="admin_login.php" class="admin-link">Administrator Login</a>
        <a href="../index.html" class="admin-link">Home</a>
      <?php endif; ?>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const togglePassword = document.getElementById('togglePassword');
      const passwordInput = document.getElementById('password');
      
      if (togglePassword && !togglePassword.disabled) {
        togglePassword.addEventListener('click', function() {
          const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
          passwordInput.setAttribute('type', type);
          togglePassword.textContent = type === 'password' ? '👁️' : '🔒';
        });
      }

      const emailField = document.getElementById('email');
      if (emailField && !emailField.disabled) {
        emailField.focus();
      }

      const loginForm = document.getElementById('loginForm');
      if (loginForm && !loginForm.hasAttribute('onsubmit')) {
        loginForm.addEventListener('submit', function(e) {
          const email = document.getElementById('email').value.trim();
          const password = document.getElementById('password').value.trim();
          if (!email || !password) {
            e.preventDefault();
            alert('Please fill in both email and password fields.');
            return false;
          }
          return true;
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

      // Auto-refresh when lock time expires (30 seconds)
      <?php if ($is_blocked): ?>
        setTimeout(function() {
          window.location.reload();
        }, 30000);
      <?php endif; ?>
    });
  </script>
</body>
</html>