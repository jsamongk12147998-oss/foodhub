<?php
// users/logout.php
session_start();
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    try {
        // Check if user_sessions table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'user_sessions'");
        
        if ($table_check && $table_check->num_rows > 0) {
            // Mark session as inactive - using session_token or just user_id
            // Option 1: If you have session_token in the table
            if (isset($_SESSION['session_token'])) {
                $sql = "UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND session_token = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("is", $user_id, $_SESSION['session_token']);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                // Option 2: Fallback - update by user_id only
                $sql = "UPDATE user_sessions SET is_active = 0 WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    } catch (Exception $e) {
        // Log error but don't break logout process
        error_log("Logout session update error: " . $e->getMessage());
    }
}

// Clear all session variables
$_SESSION = array();

// If you want to delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: Studlogin.php");
exit();
?>