<?php
// users/signup.php
session_start();

// 🔹 Include config and database connection FIRST
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

// If db/db.php didn't set $conn properly, handle it
if (!isset($conn) || !$conn) {
    die("❌ Database connection failed. Please check your db/db.php settings.");
}

$error = "";

// ✅ Capture error message from Google OAuth redirect
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']); // clear it after showing
}

// ✅ Validate UMAK email
function isUmakEmail($email) {
    $domain = strtolower(substr(strrchr($email, "@"), 1));
    return $domain === 'umak.edu.ph';
}

// ✅ Validate password - UPDATED TO MATCH IMAGE REQUIREMENTS
function isPasswordStrong($password) {
    if (strlen($password) < 11) return "Password must be at least 11 characters long.";
    if (!preg_match('/[A-Z]/', $password)) return "Password must contain at least one uppercase letter (A-Z).";
    if (!preg_match('/[a-z]/', $password)) return "Password must contain at least one lowercase letter (a-z).";
    if (!preg_match('/[0-9]/', $password)) return "Password must contain at least one number (0-9).";
    if (!preg_match('/[!@#$%^&*()_=+\[\]{};:<.>]/', $password)) return "Password must contain at least one special character (!@#$%^&*()_=+[]{};:<.>).";
    if (preg_match('/\s/', $password)) return "No spaces allowed in password.";
    return true;
}

// ✅ Check for existing email in BOTH temp_users and users tables
function emailExists($email, $conn) {
    if (!$conn) {
        error_log("⚠️ emailExists() called without a valid database connection.");
        return false;
    }

    // Check in temp_users table
    $sql_temp = "SELECT id FROM temp_users WHERE email = ?";
    $stmt_temp = $conn->prepare($sql_temp);
    if ($stmt_temp) {
        $stmt_temp->bind_param("s", $email);
        $stmt_temp->execute();
        $result_temp = $stmt_temp->get_result();
        if ($result_temp->num_rows > 0) {
            return true;
        }
    }

    // Check in users table
    $sql_users = "SELECT id FROM users WHERE email = ?";
    $stmt_users = $conn->prepare($sql_users);
    if ($stmt_users) {
        $stmt_users->bind_param("s", $email);
        $stmt_users->execute();
        $result_users = $stmt_users->get_result();
        return $result_users->num_rows > 0;
    }
    
    return false;
}

// ✅ Generate 6-digit OTP
function generateOTP() {
    return sprintf("%06d", mt_rand(1, 999999));
}

// ✅ Include PHPMailer + SMTP config
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../smtp_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ✅ Send OTP email using Gmail SMTP
function sendOTPEmail($email, $name, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email, $name);

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your UMAK FoodHub Account - OTP Required';

        $mail->Body = "
        <html><body style='font-family:Arial,sans-serif;'>
        <h2>Hello $name,</h2>
        <p>Thank you for registering with <strong>UMAK FoodHub</strong>!</p>
        <p>Your verification code is:</p>
        <div style='background:#0d1b4c;color:#ffcc00;font-size:24px;padding:10px;text-align:center;border-radius:8px;letter-spacing:5px;'>
            <strong>$otp</strong>
        </div>
        <p>This code will expire in <strong>10 minutes</strong>.</p>
        <p>If you didn't request this verification, please ignore this email.</p>
        <hr><small>University of Makati FoodHub Team</small>
        </body></html>";

        $mail->AltBody = "Hello $name,\n\nYour verification code is: $otp\n\nThis code will expire in 10 minutes.\n\nUMAK FoodHub Team.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("❌ Email failed: " . $mail->ErrorInfo);
        return false;
    }
}

// ✅ Handle manual registration
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = "user";

    if (!isUmakEmail($email)) {
        $error = "Only University of Makati email accounts (@umak.edu.ph) are allowed.";
    } elseif (emailExists($email, $conn)) {
        $error = "This email is already registered. Please use a different one or login.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $passwordCheck = isPasswordStrong($password);
        if ($passwordCheck !== true) $error = $passwordCheck;
    }

    if (empty($error)) {
        $otp = generateOTP();
        $otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // ✅ Store in temporary table first
        $sql = "INSERT INTO temp_users (name, email, password, role, verification_otp, otp_expires_at, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ssssss", $name, $email, $hashed_password, $role, $otp, $otp_expires);
            if ($stmt->execute()) {
                if (sendOTPEmail($email, $name, $otp)) {
                    $_SESSION['temp_user_id'] = $stmt->insert_id;
                    $_SESSION['verify_email'] = $email;
                    $_SESSION['verify_name'] = $name;
                    $_SESSION['success'] = "Registration successful! We've sent a verification code to your UMAK email.";
                    header("Location: verify_otp.php");
                    exit();
                } else {
                    // If email fails, delete the temp record
                    $delete_sql = "DELETE FROM temp_users WHERE id = ?";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $stmt->insert_id);
                    $delete_stmt->execute();
                    
                    $error = "Failed to send verification email. Please try again.";
                }
            } else {
                $error = "Registration failed. Please try again.";
            }
        } else {
            $error = "Database error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Sign Up - University of Makati</title>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Segoe UI', Arial, sans-serif;
    }

    body {
      background: url('Umak_Dining_area.jpg') no-repeat center center/cover;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      backdrop-filter: blur(4px);
    }

    .signup-container {
      background: #fff;
      display: flex;
      flex-wrap: wrap;
      width: 900px;
      max-width: 95%;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 0 30px rgba(0, 0, 0, 0.3);
      margin: 20px;
    }

    .signup-left {
      flex: 1;
      min-width: 300px;
      background: linear-gradient(135deg, #04124a 20%, #ffcc00 50%, #04124a 80%);
      display: flex;
      justify-content: center;
      align-items: center;
      flex-direction: column;
      color: white;
      text-align: center;
      padding: 20px;
    }

    .signup-left img {
      width: 200px;
      margin-bottom: 20px;
    }

    .signup-right {
      flex: 1;
      min-width: 300px;
      padding: 40px 50px;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
    }

    h2 {
      color: #0d1b4c;
      text-align: center;
      margin-bottom: 20px;
      font-size: 22px;
      font-weight: bold;
    }

    .error, .success {
      padding: 10px;
      border-radius: 6px;
      text-align: center;
      font-size: 14px;
      margin-bottom: 15px;
    }

    .error {
      background: #ffeaa7;
      color: #d63031;
      border: 1px solid #fab1a0;
    }

    .success {
      background: #55efc4;
      color: #006a4e;
      border: 1px solid #00b894;
    }

    .requirements {
      background: #f8f9fa;
      border: 1px solid #e9ecef;
      border-radius: 8px;
      padding: 12px;
      font-size: 12px;
      color: #444;
      margin-bottom: 15px;
    }

    .requirements h4 {
      margin-bottom: 6px;
      color: #0d1b4c;
    }

    .requirements ul {
      list-style: none;
      padding-left: 0;
    }

    .requirements li {
      margin-bottom: 4px;
      padding-left: 16px;
      position: relative;
      transition: all 0.3s ease;
    }

    .requirements li:before {
      content: "✓";
      color: #0d1b4c;
      font-weight: bold;
      position: absolute;
      left: 0;
      transition: all 0.3s ease;
    }

    .requirements li.requirement-met {
      color: #00b894;
    }

    .requirements li.requirement-met:before {
      color: #00b894;
    }

    form {
      display: flex;
      flex-direction: column;
      gap: 12px;
      flex-grow: 1;
    }

    .input-group {
      position: relative;
    }

    input {
      padding: 12px 40px 12px 12px;
      border: 1px solid #ccc;
      border-radius: 8px;
      font-size: 14px;
      width: 100%;
    }

    input:focus {
      border-color: #0d1b4c;
      outline: none;
    }

    .toggle-btn {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      padding: 0;
    }

    .toggle-btn svg {
      width: 20px;
      height: 20px;
      stroke: #0d1b4c;
      stroke-width: 2;
      fill: none;
      transition: stroke 0.3s;
    }

    .toggle-btn:hover svg {
      stroke: #ffcc00;
    }

    .match-text {
      font-size: 12px;
      text-align: right;
      margin-top: -5px;
    }

    button[type="submit"] {
      background-color: #0d1b4c;
      color: #ffcc00;
      border: none;
      border-radius: 8px;
      padding: 12px;
      font-weight: bold;
      font-size: 15px;
      cursor: pointer;
      transition: 0.3s;
      margin-top: 5px;
    }

    button[type="submit"]:hover {
      background-color: #09103a;
    }

    .login-link {
      text-align: center;
      font-size: 14px;
      color: #0d1b4c;
      text-decoration: none;
      margin-top: 15px;
      display: block;
    }

    .login-link:hover {
      text-decoration: underline;
    }

    .verification-notice {
      background: #e6f2ff;
      border: 1px solid #cce0ff;
      border-radius: 8px;
      padding: 12px;
      font-size: 12px;
      color: #0d1b4c;
      margin-bottom: 15px;
      text-align: center;
    }

    @media (max-width: 768px) {
      .signup-container {
        flex-direction: column;
        height: auto;
      }

      .signup-left, .signup-right {
        width: 100%;
      }

      .signup-right {
        padding: 30px 25px;
      }
    }
  </style>
</head>
<body>
  <div class="signup-container">
    <div class="signup-left">
      <img src="Foodhub_notext.png" alt="UMAK Foodhub Logo">
      <h1>University of Makati</h1>
      <p>Student Registration Portal</p>
    </div>

    <div class="signup-right">
      <h2>Create Student Account</h2>

      <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>
      <?php if (isset($_GET['success']) && $_GET['success'] == 1) echo "<div class='success'>Registration successful! Please login.</div>"; ?>

      <div class="verification-notice">
        <strong>📧 Email Verification Required</strong><br>
        After registration, you'll receive a 6-digit code in your UMAK Gmail to verify your account. Your account will only be created after successful verification.
      </div>

      <div class="requirements">
        <h4>Password Requirements:</h4>
        <ul>
          <li id="req-length">At least 11 characters long</li>
          <li id="req-uppercase">At least one uppercase letter (A-Z)</li>
          <li id="req-lowercase">At least one lowercase letter (a-z)</li>
          <li id="req-number">At least one number (0-9)</li>
          <li id="req-special">At least one special character (!@#$%^&*()_=+[]{};:<.>)</li>
          <li id="req-spaces">No spaces allowed</li>
        </ul>
      </div>

      <form method="POST" id="signupForm">
        <input type="text" name="name" placeholder="Full Name (as per university records)" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
        <input type="email" name="email" placeholder="Email Address (@umak.edu.ph)" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">

        <div class="input-group">
          <input type="password" name="password" id="password" placeholder="Create Password" required onkeyup="checkPasswordRequirements()">
          <button type="button" class="toggle-btn" onclick="togglePassword('password', this)">
            <svg viewBox="0 0 24 24"><path d="M1.5 12s4.5-7.5 10.5-7.5 10.5 7.5 10.5 7.5-4.5 7.5-10.5 7.5S1.5 12 1.5 12z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>

        <div class="input-group">
          <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required onkeyup="checkPasswordMatch()">
          <button type="button" class="toggle-btn" onclick="togglePassword('confirm_password', this)">
            <svg viewBox="0 0 24 24"><path d="M1.5 12s4.5-7.5 10.5-7.5 10.5 7.5 10.5 7.5-4.5 7.5-10.5 7.5S1.5 12 1.5 12z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <div id="passwordMatch" class="match-text"></div>

        <button type="submit">Create Account</button>
      </form>

      <a href="Studlogin.php" class="login-link">Already have an account? Login here</a>

     <a href="../index.html" class="login-link">Home</a>

    </div>
  </div>

  <script>
    function checkPasswordRequirements() {
      const password = document.getElementById('password').value;
      
      // Check each requirement
      const hasLength = password.length >= 11;
      const hasUppercase = /[A-Z]/.test(password);
      const hasLowercase = /[a-z]/.test(password);
      const hasNumber = /[0-9]/.test(password);
      const hasSpecial = /[!@#$%^&*()_=+\[\]{};:<.>]/.test(password);
      const hasNoSpaces = !/\s/.test(password);
      
      // Update requirement indicators
      updateRequirement('req-length', hasLength);
      updateRequirement('req-uppercase', hasUppercase);
      updateRequirement('req-lowercase', hasLowercase);
      updateRequirement('req-number', hasNumber);
      updateRequirement('req-special', hasSpecial);
      updateRequirement('req-spaces', hasNoSpaces);
      
      // Also check password match if confirm password has value
      if (document.getElementById('confirm_password').value) {
        checkPasswordMatch();
      }
    }
    
    function updateRequirement(elementId, isMet) {
      const element = document.getElementById(elementId);
      if (isMet) {
        element.classList.add('requirement-met');
      } else {
        element.classList.remove('requirement-met');
      }
    }

    function checkPasswordMatch() {
      const password = document.getElementById('password').value;
      const confirmPassword = document.getElementById('confirm_password').value;
      const matchText = document.getElementById('passwordMatch');
      if (confirmPassword === '') {
        matchText.textContent = '';
      } else if (password === confirmPassword) {
        matchText.textContent = '✅ Passwords match';
        matchText.style.color = '#00b894';
      } else {
        matchText.textContent = '❌ Passwords do not match';
        matchText.style.color = '#d63031';
      }
    }

    function togglePassword(fieldId, button) {
      const input = document.getElementById(fieldId);
      const svg = button.querySelector("svg");
      if (input.type === "password") {
        input.type = "text";
        svg.innerHTML = `
          <path d="M3 3l18 18M9.88 9.88A3 3 0 0112 9a3 3 0 013 3c0 .45-.1.88-.28 1.26m-1.62 1.62A3 3 0 0112 15a3 3 0 01-3-3c0-.45.1-.88.28-1.26m-6.28 1.26s4.5 7.5 10.5 7.5c2.52 0 4.74-1.12 6.47-2.87"/>
          <path d="M22.5 12s-4.5-7.5-10.5-7.5a10.5 10.5 0 00-3.18.5"/>
        `;
      } else {
        input.type = "password";
        svg.innerHTML = `
          <path d="M1.5 12s4.5-7.5 10.5-7.5 10.5 7.5 10.5 7.5-4.5 7.5-10.5 7.5S1.5 12 1.5 12z"/>
          <circle cx="12" cy="12" r="3"/>
        `;
      }
    }
  </script>
</body>
</html>