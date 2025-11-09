<?php
session_start();
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

// Load PHPMailer
require_once(__DIR__ . '/../vendor/autoload.php');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Ensure DB connection exists
if (!isset($conn) || $conn === null || $conn->connect_error) {
    die("Database connection failed. Please check db.php.");
}

// Create password_resets table if it doesn't exist
$createTableSQL = "
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (email)
)";
$conn->query($createTableSQL);

$error = "";
$success = "";
$step = isset($_GET['step']) ? $_GET['step'] : 'request';

/**
 * Generate a secure random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Send password reset email using PHPMailer
 */
function sendResetEmail($email, $token) {
    $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/forgot_password.php?step=reset&token=" . $token;
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings - UPDATED WITH MORE RELIABLE SETTINGS
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'limhansjomel@gmail.com'; // Your Gmail
        $mail->Password   = 'strileckavrxylyi';       // Your App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // More permissive SSL settings for local development
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Reduced debugging for production
        $mail->SMTPDebug = SMTP::DEBUG_OFF; // Changed from DEBUG_SERVER
        
        // Recipients - FIXED TYPO IN EMAIL CONTENT
        $mail->setFrom('noreply@umak.edu.ph', 'University of Makati');
        $mail->addAddress($email);
        $mail->addReplyTo('noreply@umak.edu.ph', 'University of Makati');
        
        // Content - FIXED "Hava" TYPO
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - University of Makati';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0d1b4c; color: #FFD700; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { background: #0d1b4c; color: #FFD700; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; padding-top: 20px; border-top: 1px solid #ddd; }
                .link-box { background: #eee; padding: 15px; border-radius: 5px; margin: 20px 0; word-break: break-all; font-family: monospace; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>University of Makati</h2>
                </div>
                <div class='content'>
                    <h3>Password Reset Request</h3>
                    <p>Hello,</p>
                    <p>We received a request to reset your password for your University of Makati account.</p>
                    <p>Click the button below to reset your password:</p>
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='$reset_link' class='button'>Reset Password</a>
                    </p>
                    <p>Or copy and paste this link in your browser:</p>
                    <div class='link-box'>$reset_link</div>
                    <p><strong>This link will expire in 1 hour for security reasons.</strong></p>
                    <p>If you didn't request this reset, please ignore this email. Your account remains secure.</p>
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " University of Makati. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Password Reset Request\n\n" .
                        "Click this link to reset your password:\n" .
                        "$reset_link\n\n" .
                        "This link expires in 1 hour.\n\n" .
                        "If you didn't request this reset, please ignore this email.";
        
        if ($mail->send()) {
            return true;
        } else {
            error_log("Email sending failed for: $email");
            return false;
        }
    } catch (Exception $e) {
        error_log("Email Exception for $email: " . $e->getMessage());
        $_SESSION['email_error'] = $e->getMessage();
        return false;
    }
}

/**
 * Validate password strength
 */
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($step === 'request') {
        // Step 1: Request reset
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $error = "Please enter your email address.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            try {
                // Check if email exists in database - FIXED TABLE NAME ISSUE
                $sql = "SELECT id, email, name FROM users WHERE email = ? LIMIT 1";
                $stmt = $conn->prepare($sql);
                
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                // Always show success message for security, even if email doesn't exist
                $success_message = "If an account with this email exists, password reset instructions have been sent.";
                
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    // Generate reset token
                    $token = generateToken();
                    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    // Delete any existing tokens for this user
                    $delete_sql = "DELETE FROM password_resets WHERE email = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    if ($delete_stmt) {
                        $delete_stmt->bind_param("s", $email);
                        $delete_stmt->execute();
                        $delete_stmt->close();
                    }
                    
                    // Insert new token
                    $insert_sql = "INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    
                    if ($insert_stmt && $insert_stmt->bind_param("sss", $email, $token, $expires) && $insert_stmt->execute()) {
                        // Send email
                        if (sendResetEmail($email, $token)) {
                            $success = "✅ Password reset instructions have been sent to your email. Please check your inbox (and spam folder).";
                        } else {
                            // Don't show technical error to user, use generic message
                            $error = "❌ Failed to send email. Please try again or contact support.";
                        }
                    } else {
                        $error = "Database error. Please try again.";
                    }
                    
                    if ($insert_stmt) {
                        $insert_stmt->close();
                    }
                } else {
                    $success = $success_message;
                }
                
                if ($stmt) {
                    $stmt->close();
                }
                
            } catch (Exception $e) {
                error_log("Password reset error: " . $e->getMessage());
                $error = "System error. Please try again later.";
            }
        }
    } elseif ($step === 'reset') {
        // Step 2: Reset password
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($password) || empty($confirm_password)) {
            $error = "Please fill in all fields.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            $password_errors = validatePassword($password);
            if (!empty($password_errors)) {
                $error = implode("<br>", $password_errors);
            } else {
                try {
                    // Verify token
                    $sql = "SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1";
                    $stmt = $conn->prepare($sql);
                    
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    
                    $stmt->bind_param("s", $token);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 1) {
                        $reset_request = $result->fetch_assoc();
                        $email = $reset_request['email'];
                        
                        // Update password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $update_sql = "UPDATE users SET password = ? WHERE email = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        
                        if ($update_stmt && $update_stmt->bind_param("ss", $hashed_password, $email) && $update_stmt->execute()) {
                            // Delete used token
                            $delete_sql = "DELETE FROM password_resets WHERE token = ?";
                            $delete_stmt = $conn->prepare($delete_sql);
                            if ($delete_stmt) {
                                $delete_stmt->bind_param("s", $token);
                                $delete_stmt->execute();
                                $delete_stmt->close();
                            }
                            
                            $success = "✅ Password reset successfully! You can now login with your new password.";
                            $step = 'success';
                        } else {
                            $error = "Failed to update password. Please try again.";
                        }
                        
                        if ($update_stmt) {
                            $update_stmt->close();
                        }
                    } else {
                        $error = "Invalid or expired reset token. Please request a new reset link.";
                    }
                    
                    if ($stmt) {
                        $stmt->close();
                    }
                    
                } catch (Exception $e) {
                    error_log("Password reset error: " . $e->getMessage());
                    $error = "System error. Please try again later.";
                }
            }
        }
    }
}

// Handle token verification for reset page
if ($step === 'reset' && isset($_GET['token'])) {
    $token = $_GET['token'];
    
    try {
        $sql = "SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $error = "Invalid or expired reset token. Please request a new reset link.";
                $step = 'invalid';
            }
            $stmt->close();
        } else {
            $error = "Database error. Please try again.";
            $step = 'invalid';
        }
    } catch (Exception $e) {
        error_log("Token verification error: " . $e->getMessage());
        $error = "System error. Please try again later.";
        $step = 'invalid';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - University of Makati</title>
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

  .password-box {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 12px;
    box-shadow: 0 3px 8px rgba(0,0,0,0.3);
    width: 90%;
    max-width: 500px;
    padding: 40px 30px;
    position: relative;
    z-index: 1;
  }

  h2 {
    margin-bottom: 20px;
    text-align: center;
    color: #0d1b4c;
    font-size: 24px;
    line-height: 1.3;
  }

  .subtitle {
    text-align: center;
    color: #666;
    margin-bottom: 30px;
    font-size: 14px;
  }

  .step-indicator {
    display: flex;
    justify-content: center;
    margin-bottom: 30px;
  }

  .step {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin: 0 15px;
  }

  .step-circle {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #ddd;
    color: #666;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-bottom: 5px;
  }

  .step.active .step-circle {
    background: #0d1b4c;
    color: #FFD700;
  }

  .step.completed .step-circle {
    background: #28a745;
    color: white;
  }

  .step-text {
    font-size: 12px;
    color: #666;
    text-align: center;
  }

  .step.active .step-text {
    color: #0d1b4c;
    font-weight: bold;
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

  .btn-secondary {
    width: 100%;
    padding: 12px;
    background: #6c757d;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    margin-bottom: 10px;
    font-size: 16px;
    text-decoration: none;
    display: block;
    text-align: center;
    transition: background 0.3s;
  }

  .btn-secondary:hover {
    background: #545b62;
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
    color: #155724;
    text-align: center;
    margin-bottom: 15px;
    background: #d4edda;
    padding: 12px;
    border-radius: 6px;
    border: 1px solid #c3e6cb;
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

  .password-requirements {
    color: #666;
    font-size: 12px;
    margin-bottom: 15px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 5px;
    border-left: 4px solid #0d1b4c;
  }

  .password-requirements ul {
    margin-left: 20px;
  }

  .login-link {
    display: block;
    text-align: center;
    margin-top: 20px;
    color: #0d1b4c;
    font-size: 14px;
    text-decoration: none;
    font-weight: 600;
  }

  .login-link:hover {
    text-decoration: underline;
  }

  /* Responsive Design */
  @media (max-width: 768px) {
    .password-box {
      width: 95%;
      padding: 30px 20px;
    }
    
    h2 {
      font-size: 20px;
    }
    
    .step {
      margin: 0 10px;
    }
  }

  @media (max-width: 480px) {
    .password-box {
      padding: 20px 15px;
    }
    
    h2 {
      font-size: 18px;
    }
    
    .step-text {
      font-size: 10px;
    }
  }
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

  .password-box {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 12px;
    box-shadow: 0 3px 8px rgba(0,0,0,0.3);
    width: 90%;
    max-width: 500px;
    padding: 40px 30px;
    position: relative;
    z-index: 1;
  }

  /* ... rest of your CSS remains unchanged ... */
</style>
</head>
<body>
  <div class="password-box">
    <h2>Password Recovery</h2>
    
    <!-- Step Indicator -->
    <div class="step-indicator">
      <div class="step <?= $step === 'request' ? 'active' : ($step === 'reset' || $step === 'success' ? 'completed' : '') ?>">
        <div class="step-circle">1</div>
        <div class="step-text">Request Reset</div>
      </div>
      <div class="step <?= $step === 'reset' ? 'active' : ($step === 'success' ? 'completed' : '') ?>">
        <div class="step-circle">2</div>
        <div class="step-text">Reset Password</div>
      </div>
      <div class="step <?= $step === 'success' ? 'active' : '' ?>">
        <div class="step-circle">3</div>
        <div class="step-text">Complete</div>
      </div>
    </div>

    <?php if (!empty($error)): ?>
      <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
      <div class="success"><?= $success ?></div>
    <?php endif; ?>

    <!-- Step 1: Request Reset -->
    <?php if ($step === 'request'): ?>
      <div class="subtitle">Enter your email to receive password reset instructions</div>
      
      <form method="POST">
        <div class="input-group">
          <label for="email">Email Address</label>
          <input type="email" name="email" id="email" placeholder="Enter your email address" 
                 value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" required>
        </div>

        <button type="submit">Send Reset Link</button>
      </form>
      
      <a href="Studlogin.php" class="login-link">← Back to Login</a>

    <!-- Step 2: Reset Password -->
    <?php elseif ($step === 'reset' && isset($_GET['token'])): ?>
      <div class="subtitle">Create your new password</div>
      
      <div class="password-requirements">
        <strong>Password Requirements:</strong>
        <ul>
          <li>At least 8 characters long</li>
          <li>Contains uppercase and lowercase letters</li>
          <li>Contains at least one number</li>
          <li>Contains at least one special character</li>
        </ul>
      </div>
      
      <form method="POST">
        <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token']) ?>">
        
        <div class="input-group">
          <label for="password">New Password</label>
          <input type="password" name="password" id="password" placeholder="Enter new password" required>
          <button type="button" class="password-toggle" id="togglePassword">👁️</button>
        </div>

        <div class="input-group">
          <label for="confirm_password">Confirm New Password</label>
          <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required>
          <button type="button" class="password-toggle" id="toggleConfirmPassword">👁️</button>
        </div>

        <button type="submit">Reset Password</button>
      </form>
      
      <a href="forgot_password.php" class="login-link">← Request new reset link</a>

    <!-- Step 3: Success -->
    <?php elseif ($step === 'success'): ?>
      <div class="subtitle">Your password has been reset successfully</div>
      
      <div class="info">
        You can now login with your new password. Make sure to keep it secure and don't share it with anyone.
      </div>
      
      <a href="Studlogin.php" class="btn-secondary">Go to Login</a>

    <!-- Invalid Token -->
    <?php elseif ($step === 'invalid'): ?>
      <div class="subtitle">Invalid or expired reset link</div>
      
      <div class="error">
        The password reset link is invalid or has expired. Please request a new reset link.
      </div>
      
      <a href="forgot_password.php" class="btn-secondary">Request New Reset Link</a>
      <a href="Studlogin.php" class="login-link">← Back to Login</a>
    <?php endif; ?>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Password toggle functionality
      const togglePassword = document.getElementById('togglePassword');
      const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
      const passwordInput = document.getElementById('password');
      const confirmPasswordInput = document.getElementById('confirm_password');
      
      if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
          const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
          passwordInput.setAttribute('type', type);
          togglePassword.textContent = type === 'password' ? '👁️' : '🔒';
        });
      }
      
      if (toggleConfirmPassword && confirmPasswordInput) {
        toggleConfirmPassword.addEventListener('click', function() {
          const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
          confirmPasswordInput.setAttribute('type', type);
          toggleConfirmPassword.textContent = type === 'password' ? '👁️' : '🔒';
        });
      }

      // Focus on email field when page loads
      const emailField = document.getElementById('email');
      if (emailField) {
        emailField.focus();
      }
    });
  </script>
</body>
</html>