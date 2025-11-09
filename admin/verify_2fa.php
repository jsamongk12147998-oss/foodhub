<?php
session_start();
include "db.php";
require 'autoload.php'; // If using PHPGangsta/GoogleAuthenticator

use PHPGangsta_GoogleAuthenticator;

if (!isset($_SESSION['temp_user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {     
    $code = trim($_POST['code']);
    $user_id = $_SESSION['temp_user_id'];

    $stmt = $conn->prepare("SELECT twofa_secret FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $ga = new PHPGangsta_GoogleAuthenticator();
    if ($ga->verifyCode($user['twofa_secret'], $code, 2)) { // 2 = 2*30sec window
        // 2FA verified
        $_SESSION['user_id'] = $user_id;
        unset($_SESSION['temp_user_id']); // remove temp session
        header("Location: UserInt.php");
        exit();
    } else {
        $error = "Invalid 2FA code.";
    }
}
?>
<form method="POST">
    <input type="text" name="code" placeholder="Enter 2FA code" required>
    <button type="submit">Verify</button>
    <?php if (!empty($error)) echo "<p>$error</p>"; ?>
</form>
