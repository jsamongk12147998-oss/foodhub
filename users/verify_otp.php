<?php
// users/verify_otp.php
session_start();

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');
 // Resend email
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/../smtp_config.php';
        
        use PHPMailer\PHPMailer\PHPMailer;
        use PHPMailer\PHPMailer\Exception;
        
if (!isset($conn) || !$conn) {
    die("❌ Database connection failed.");
}

// Redirect if no temp user data
if (!isset($_SESSION['temp_user_id']) || !isset($_SESSION['verify_email'])) {
    header("Location: signup.php");
    exit();
}

$error = "";
$success = "";

if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['warning'])) {
    $success = $_SESSION['warning'];
    unset($_SESSION['warning']);
}

// Handle OTP verification
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $entered_otp = trim($_POST['otp']);
    $temp_user_id = $_SESSION['temp_user_id'];
    $email = $_SESSION['verify_email'];
    
    if (empty($entered_otp)) {
        $error = "Please enter the OTP code.";
    } else {
        // Check if OTP is valid and not expired
        $sql = "SELECT * FROM temp_users WHERE id = ? AND email = ? AND verification_otp = ? AND otp_expires_at > NOW()";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $temp_user_id, $email, $entered_otp);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $temp_user = $result->fetch_assoc();
            
            // ✅ Move to main users table
            $insert_sql = "INSERT INTO users (name, email, password, role, email_verified, created_at) 
                          VALUES (?, ?, ?, ?, 1, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ssss", $temp_user['name'], $temp_user['email'], $temp_user['password'], $temp_user['role']);
            
            if ($insert_stmt->execute()) {
                // ✅ Delete from temp table
                $delete_sql = "DELETE FROM temp_users WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("i", $temp_user_id);
                $delete_stmt->execute();
                
                // ✅ Clear session
                unset($_SESSION['temp_user_id']);
                unset($_SESSION['verify_email']);
                unset($_SESSION['verify_name']);
                
                $_SESSION['success'] = "Account verified successfully! You can now login.";
                header("Location: Studlogin.php?verified=1");
                exit();
            } else {
                $error = "Failed to create account. Please try again.";
            }
        } else {
            $error = "Invalid or expired OTP code. Please try again.";
        }
    }
}

// Resend OTP functionality
if (isset($_POST['resend_otp'])) {
    $temp_user_id = $_SESSION['temp_user_id'];
    $email = $_SESSION['verify_email'];
    $name = $_SESSION['verify_name'];
    
    // Generate new OTP
    $new_otp = sprintf("%06d", mt_rand(1, 999999));
    $new_otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    // Update OTP in temp_users table
    $update_sql = "UPDATE temp_users SET verification_otp = ?, otp_expires_at = ? WHERE id = ? AND email = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ssis", $new_otp, $new_otp_expires, $temp_user_id, $email);
    
    if ($update_stmt->execute()) {
       
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
            $mail->Subject = 'New Verification Code - UMAK FoodHub';

            $mail->Body = "
            <html><body style='font-family:Arial,sans-serif;'>
            <h2>Hello $name,</h2>
            <p>Here is your new verification code:</p>
            <div style='background:#0d1b4c;color:#ffcc00;font-size:24px;padding:10px;text-align:center;border-radius:8px;letter-spacing:5px;'>
                <strong>$new_otp</strong>
            </div>
            <p>This code will expire in <strong>10 minutes</strong>.</p>
            <hr><small>University of Makati FoodHub Team</small>
            </body></html>";

            $mail->send();
            $success = "New verification code sent to your email!";
        } catch (Exception $e) {
            $error = "Failed to send new code. Please try again.";
        }
    } else {
        $error = "Failed to generate new OTP. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify Email - UMAK FoodHub</title>
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

    .verify-container {
      background: #fff;
      width: 450px;
      max-width: 95%;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 0 30px rgba(0, 0, 0, 0.3);
    }

    .verify-header {
      background: linear-gradient(135deg, #04124a, #0d1b4c);
      color: white;
      text-align: center;
      padding: 30px 20px;
    }

    .verify-header img {
      width: 80px;
      margin-bottom: 15px;
    }

    .verify-body {
      padding: 30px;
    }

    h2 {
      color: #0d1b4c;
      text-align: center;
      margin-bottom: 20px;
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

    .info-box {
      background: #e6f2ff;
      border: 1px solid #cce0ff;
      border-radius: 8px;
      padding: 15px;
      margin-bottom: 20px;
      text-align: center;
    }

    .email-display {
      font-weight: bold;
      color: #0d1b4c;
      margin: 10px 0;
    }

    .otp-input {
      width: 100%;
      padding: 15px;
      border: 2px solid #0d1b4c;
      border-radius: 8px;
      font-size: 18px;
      text-align: center;
      letter-spacing: 5px;
      margin-bottom: 15px;
    }

    .otp-input:focus {
      outline: none;
      border-color: #ffcc00;
    }

    .verify-btn {
      background-color: #0d1b4c;
      color: #ffcc00;
      border: none;
      border-radius: 8px;
      padding: 15px;
      font-weight: bold;
      font-size: 16px;
      cursor: pointer;
      width: 100%;
      transition: 0.3s;
      margin-bottom: 15px;
    }

    .verify-btn:hover {
      background-color: #09103a;
    }

    .resend-btn {
      background: transparent;
      color: #0d1b4c;
      border: 1px solid #0d1b4c;
      border-radius: 8px;
      padding: 12px;
      font-size: 14px;
      cursor: pointer;
      width: 100%;
      transition: 0.3s;
    }

    .resend-btn:hover {
      background: #0d1b4c;
      color: white;
    }

    .back-link {
      text-align: center;
      display: block;
      margin-top: 15px;
      color: #0d1b4c;
      text-decoration: none;
    }

    .back-link:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>
  <div class="verify-container">
    <div class="verify-header">
      <img src="Foodhub_notext.png" alt="UMAK FoodHub Logo">
      <h1>Email Verification</h1>
    </div>
    
    <div class="verify-body">
      <h2>Enter Verification Code</h2>
      
      <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>
      <?php if (!empty($success)) echo "<div class='success'>$success</div>"; ?>
      
      <div class="info-box">
        <p>We sent a 6-digit code to:</p>
        <div class="email-display"><?php echo htmlspecialchars($_SESSION['verify_email']); ?></div>
        <p>Enter the code below to verify your account.</p>
      </div>

      <form method="POST">
        <input type="text" name="otp" class="otp-input" placeholder="000000" maxlength="6" required pattern="[0-9]{6}" title="Enter 6-digit code">
        <button type="submit" class="verify-btn">Verify Account</button>
      </form>

      <form method="POST">
        <button type="submit" name="resend_otp" class="resend-btn">Resend Verification Code</button>
      </form>

      <a href="signup.php" class="back-link">Back to Sign Up</a>
    </div>
  </div>

  <script>
    // Auto-focus OTP input
    document.querySelector('.otp-input').focus();
    
    // Auto-tab between OTP digits (if you change to individual inputs)
    document.querySelector('.otp-input').addEventListener('input', function(e) {
      if (this.value.length === 6) {
        this.form.submit();
      }
    });
  </script>
</body>
</html>