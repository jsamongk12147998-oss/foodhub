<?php
session_start();
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: Studlogin.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_id = $_SESSION['user_id'];
    $action = $_POST['action'] ?? '';
    
    if ($action === 'enable') {
        // Enable 2FA
        $sql = "UPDATE users SET two_factor_enabled = 1 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['success'] = "Two-factor authentication has been enabled.";
    } elseif ($action === 'disable') {
        // Disable 2FA
        $sql = "UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['success'] = "Two-factor authentication has been disabled.";
    }
    
    header("Location: enable_2fa.php");
    exit();
}

// Get current 2FA status
$sql = "SELECT two_factor_enabled FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage 2FA</title>
    <style>
        body { font-family: Arial; max-width: 600px; margin: 50px auto; padding: 20px; }
        .container { background: #f9f9f9; padding: 20px; border-radius: 8px; }
        .status { padding: 15px; border-radius: 5px; margin: 15px 0; }
        .enabled { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .disabled { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        button { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; }
        .enable-btn { background: #28a745; color: white; }
        .disable-btn { background: #dc3545; color: white; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Two-Factor Authentication</h2>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <div class="status <?php echo $user['two_factor_enabled'] ? 'enabled' : 'disabled'; ?>">
            Status: <strong><?php echo $user['two_factor_enabled'] ? 'ENABLED' : 'DISABLED'; ?></strong>
        </div>
        
        <form method="POST">
            <?php if (!$user['two_factor_enabled']): ?>
                <button type="submit" name="action" value="enable" class="enable-btn">Enable 2FA</button>
            <?php else: ?>
                <button type="submit" name="action" value="disable" class="disable-btn">Disable 2FA</button>
            <?php endif; ?>
        </form>
        
        <p><a href="../users/UserInt.php">← Back to Dashboard</a></p>
    </div>
</body>
</html>