<?php
// set_password.php
session_start();
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

if (!isset($_SESSION['google_user'])) {
    header("Location: signup.php");
    exit();
}

$error = "";
$user = $_SESSION['google_user'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($password !== $confirm_password) {
        $error = "Passwords do not match. Please re-enter your password.";
    } else {
        $passwordCheck = isPasswordStrong($password);
        if ($passwordCheck !== true) {
            $error = $passwordCheck;
        } else {
            // Complete registration
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (name, email, password, role, google_id, email_verified) VALUES (?, ?, ?, 'user', ?, 1)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssss", $user['name'], $user['email'], $hashed_password, $user['google_id']);
            
            if ($stmt->execute()) {
                // Clear session and redirect to login
                unset($_SESSION['google_user']);
                header("Location: Studlogin.php?success=1");
                exit();
            } else {
                $error = "Registration failed. Please try again. Error: " . $stmt->error;
            }
        }
    }
}

// Function to validate password strength
function isPasswordStrong($password) {
    if (strlen($password) < 11) return "Password must be at least 11 characters long";
    if (!preg_match('/[A-Z]/', $password)) return "Password must contain at least one capital letter";
    if (!preg_match('/[a-z]/', $password)) return "Password must contain at least one lowercase letter";
    if (!preg_match('/[0-9]/', $password)) return "Password must contain at least one number";
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) return "Password must contain at least one special character";
    return true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Set Password - University of Makati</title>
  <style>
    /* Copy the same CSS styles from your signup.php */
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif; }
    body { background: url('cafe.jpg') no-repeat center center/cover; display: flex; justify-content: center; align-items: center; height: 100vh; backdrop-filter: blur(4px); }
    .password-container { background: #fff; width: 450px; padding: 40px; border-radius: 10px; box-shadow: 0 0 30px rgba(0,0,0,0.3); }
    h2 { color: #0d1b4c; text-align: center; margin-bottom: 20px; }
    .user-info { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
    .error { background: #ffeaa7; color: #d63031; padding: 10px; border-radius: 6px; margin-bottom: 15px; text-align: center; }
    form { display: flex; flex-direction: column; gap: 12px; }
    .input-group { position: relative; }
    input { padding: 12px 40px 12px 12px; border: 1px solid #ccc; border-radius: 8px; font-size: 14px; width: 100%; }
    button[type="submit"] { background-color: #0d1b4c; color: #ffcc00; border: none; border-radius: 8px; padding: 12px; font-weight: bold; cursor: pointer; }
    .toggle-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; }
  </style>
</head>
<body>
  <div class="password-container">
    <h2>Set Your Password</h2>
    
    <div class="user-info">
      <strong>Welcome, <?php echo htmlspecialchars($user['name']); ?>!</strong><br>
      <small><?php echo htmlspecialchars($user['email']); ?></small>
    </div>

    <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>

    <form method="POST">
      <div class="input-group">
        <input type="password" name="password" id="password" placeholder="Create Password" required>
        <button type="button" class="toggle-btn" onclick="togglePassword('password')">👁️</button>
      </div>
      
      <div class="input-group">
        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required>
        <button type="button" class="toggle-btn" onclick="togglePassword('confirm_password')">👁️</button>
      </div>

      <button type="submit">Complete Registration</button>
    </form>
  </div>

  <script>
    function togglePassword(fieldId) {
      const input = document.getElementById(fieldId);
      input.type = input.type === 'password' ? 'text' : 'password';
    }
  </script>
</body>
</html>