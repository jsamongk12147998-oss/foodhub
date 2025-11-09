<?php
// users/verify_email.php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

session_start();

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Check if verification code exists
    $sql = "SELECT id, email, name FROM users WHERE verification_code = ? AND email_verified = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Verify the email
        $update_sql = "UPDATE users SET email_verified = 1, verification_code = NULL WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $user['id']);
        
        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Email verified successfully! You can now login to UMAK FoodHub.";
        } else {
            $_SESSION['error'] = "Verification failed. Please try again.";
        }
    } else {
        $_SESSION['error'] = "Invalid or expired verification code.";
    }
    
    header("Location: ../users/UserInt.php");
    exit();
} else {
    header("Location: signup.php");
    exit();
}
?>