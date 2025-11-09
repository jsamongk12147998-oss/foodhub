<?php
    require_once(__DIR__ . '/../config.php');
    require_once(__DIR__ . '/../db/db.php');
    require_once(SUPERADMIN_PATH . 'functions.php');

    // Handle file uploads and form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'create_branch_admin' || $_POST['action'] === 'update_branch_admin') {
                $name = $_POST['name'] ?? '';
                $email = $_POST['email'] ?? '';
                $password = $_POST['password'] ?? '';
                $store_name = $_POST['store_name'] ?? '';
                $category = $_POST['category'] ?? '';
                $contact_number = $_POST['contact_number'] ?? '';
                $opening_time = $_POST['opening_time'] ?? '09:00';
                $closing_time = $_POST['closing_time'] ?? '21:00';
                $pickup_interval = $_POST['pickup_interval'] ?? 30;
                $user_id = $_POST['user_id'] ?? '';
                $restaurant_id = $_POST['restaurant_id'] ?? '';
                
                // Validate email           
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo '<script>window.location.href = "?msg=invalid_email";</script>';
                    exit();
                }
                
                // Validate password for new accounts
                if ($_POST['action'] === 'create_branch_admin') {
                    $passwordValidation = validatePassword($password);
                    if (!$passwordValidation['valid']) {
                        echo '<script>window.location.href = "?msg=password_error&error=' . urlencode($passwordValidation['error']) . '";</script>';
                        exit();
                    }
                }
                
                // Validate password for updates (if provided)
                if ($_POST['action'] === 'update_branch_admin' && !empty($password)) {
                    $passwordValidation = validatePassword($password);
                    if (!$passwordValidation['valid']) {
                        echo '<script>window.location.href = "?msg=password_error&error=' . urlencode($passwordValidation['error']) . '";</script>';
                        exit();
                    }
                }
                
                // Generate branch_id from store name
                $branch_id = generateBranchId($store_name);
                
                // Generate restaurant image URL
                $restaurant_image_url = generateStoreImageUrl($store_name);
                
                // Handle profile image upload
                $profile_image = '';
                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
                    $profile_image = uploadProfileImage($_FILES['profile_image'], $email);
                }
                
                if ($_POST['action'] === 'create_branch_admin') {
                    // Check if email already exists
                    $checkEmailQuery = "SELECT id FROM users WHERE email = ?";
                    $stmt = $conn->prepare($checkEmailQuery);
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $stmt->close();
                        echo '<script>window.location.href = "?msg=email_exists";</script>';
                        exit();
                    }
                    $stmt->close();
                    
                    // Create new branch admin
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert into users table with ACTIVE status (no OTP needed)
                    $userQuery = "INSERT INTO users (name, email, password, role, branch_id, store_name, profile_image, status) 
                                VALUES (?, ?, ?, 'branch_admin', ?, ?, ?, 'active')";
                    $stmt = $conn->prepare($userQuery);
                    $stmt->bind_param("ssssss", $name, $email, $hashed_password, $branch_id, $store_name, $profile_image);
                    
                    if ($stmt->execute()) {
                        $new_user_id = $stmt->insert_id;
                        $stmt->close();
                        
                        // Insert into restaurants table
                        $restaurantQuery = "INSERT INTO restaurants (owner_id, name, category, contact_number, opening_time, closing_time, pickup_interval, restaurant_image_url, status) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                        $stmt = $conn->prepare($restaurantQuery);
                        $stmt->bind_param("isssssis", $new_user_id, $store_name, $category, $contact_number, $opening_time, $closing_time, $pickup_interval, $restaurant_image_url);
                        
                        if ($stmt->execute()) {
                            $stmt->close();
                            
                            // Send welcome email (optional, without OTP)
                            $email_sent = sendWelcomeEmail($email, $name, $store_name);
                            
                            if ($email_sent) {
                                echo '<script>window.location.href = "?msg=created&email_sent=true";</script>';
                            } else {
                                // Account is created even if email fails
                                echo '<script>window.location.href = "?msg=created&email_sent=false";</script>';
                            }
                        } else {
                            $stmt->close();
                            echo '<script>window.location.href = "?msg=restaurant_error";</script>';
                        }
                    } else {
                        $stmt->close();
                        echo '<script>window.location.href = "?msg=user_error";</script>';
                    }
                    exit();
                    
                } else if ($_POST['action'] === 'update_branch_admin') {
                    // Update existing branch admin
                    if (!empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $userQuery = "UPDATE users SET name=?, email=?, password=?, store_name=?, profile_image=COALESCE(?, profile_image) WHERE id=?";
                        $stmt = $conn->prepare($userQuery);
                        $stmt->bind_param("sssssi", $name, $email, $hashed_password, $store_name, $profile_image, $user_id);
                    } else {
                        $userQuery = "UPDATE users SET name=?, email=?, store_name=?, profile_image=COALESCE(?, profile_image) WHERE id=?";
                        $stmt = $conn->prepare($userQuery);
                        $stmt->bind_param("ssssi", $name, $email, $store_name, $profile_image, $user_id);
                    }
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        
                        // Update restaurant
                        if ($restaurant_id) {
                            $restaurantQuery = "UPDATE restaurants SET name=?, category=?, contact_number=?, opening_time=?, closing_time=?, pickup_interval=?, restaurant_image_url=? WHERE id=?";
                            $stmt = $conn->prepare($restaurantQuery);
                            $stmt->bind_param("sssssisi", $store_name, $category, $contact_number, $opening_time, $closing_time, $pickup_interval, $restaurant_image_url, $restaurant_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                        
                        echo '<script>window.location.href = "?msg=updated";</script>';
                    } else {
                        $stmt->close();
                        echo '<script>window.location.href = "?msg=update_error";</script>';
                    }
                    exit();
                }
            }
            
            // Handle image removal
            if ($_POST['action'] === 'remove_image') {
                $user_id = $_POST['user_id'] ?? '';
                $image_type = $_POST['image_type'] ?? '';
                
                if ($image_type === 'profile') {
                    $updateQuery = "UPDATE users SET profile_image = NULL WHERE id = ?";
                    $stmt = $conn->prepare($updateQuery);
                    $stmt->bind_param("i", $user_id);
                    if ($stmt->execute()) {
                        echo json_encode(['success' => true, 'message' => 'Profile image removed successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to remove image']);
                    }
                    $stmt->close();
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid image type']);
                }
                exit();
            }
        }
    }

    // Helper functions
    if (!function_exists('generateBranchId')) {
        function generateBranchId($store_name) {
            $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $store_name), 0, 3));
            $random = mt_rand(1000, 9999);
            return $prefix . $random;
        }
    }

    if (!function_exists('generateStoreImageUrl')) {
        function generateStoreImageUrl($store_name) {
            $sanitized = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $store_name);
            $sanitized = preg_replace('/[\s\-]+/', '_', $sanitized);
            $sanitized = preg_replace('/_+/', '_', $sanitized);
            $sanitized = trim($sanitized, '_');
            return 'uploads/stores/' . $sanitized . '.jpg';
        }
    }

    if (!function_exists('uploadProfileImage')) {
        function uploadProfileImage($file, $email) {
            $upload_dir = __DIR__ . '/../uploads/profiles/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (!in_array($file['type'], $allowed_types)) {
                return ''; // Invalid file type
            }
            
            if ($file['size'] > $max_size) {
                return ''; // File too large
            }
            
            // Generate unique filename
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . md5($email . time()) . '.' . $file_extension;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                return $filename;
            }
            
            return '';
        }
    }

    // ✅ Password validation function
    if (!function_exists('validatePassword')) {
        function validatePassword($password) {
            // Check minimum length
            if (strlen($password) < 11) {
                return ['valid' => false, 'error' => 'Password must be at least 11 characters long'];
            }
            
            // Check for at least one uppercase letter
            if (!preg_match('/[A-Z]/', $password)) {
                return ['valid' => false, 'error' => 'Password must contain at least one uppercase letter'];
            }
            
            // Check for at least one lowercase letter
            if (!preg_match('/[a-z]/', $password)) {
                return ['valid' => false, 'error' => 'Password must contain at least one lowercase letter'];
            }
            
            // Check for at least one number
            if (!preg_match('/[0-9]/', $password)) {
                return ['valid' => false, 'error' => 'Password must contain at least one number'];
            }
            
            // Check for at least one special character
            if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
                return ['valid' => false, 'error' => 'Password must contain at least one special character (!@#$%^&*()-_=+{};:,<.>)'];
            }
            
            // Check for no spaces
            if (preg_match('/\s/', $password)) {
                return ['valid' => false, 'error' => 'Password must not contain spaces'];
            }
            
            return ['valid' => true, 'error' => ''];
        }
    }

    // ✅ Include PHPMailer + SMTP config
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../smtp_config.php';

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

    // ✅ Send welcome email (without OTP)
    function sendWelcomeEmail($email, $name, $store_name) {
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
            $mail->Subject = 'Your Branch Admin Account is Ready';

            $mail->Body = "
            <html>
            <head>
                <title>Branch Admin Account Created</title>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
                    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                    .header { background: linear-gradient(135deg, #002147 0%, #003366 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; margin: -30px -30px 20px -30px; }
                    .welcome-message { font-size: 18px; font-weight: bold; color: #002147; text-align: center; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px; }
                    .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px; text-align: center; }
                    .info-box { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #007bff; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Branch Admin Account Created</h1>
                    </div>
                    
                    <p>Hello <strong>$name</strong>,</p>
                    
                    <div class='welcome-message'>
                        🎉 Your branch admin account has been successfully created!
                    </div>
                    
                    <p>You can now log in to your branch admin dashboard and start managing your restaurant <strong>$store_name</strong>.</p>
                    
                    <div class='info-box'>
                        <strong>Login Information:</strong><br>
                        • Email: $email<br>
                        • Store: $store_name<br>
                        • You can log in immediately using your credentials
                    </div>
                    
                    <p>To get started, please visit the admin login page and use your email and password to access your account.</p>
                    
                    <p>If you have any questions or need assistance, please contact the system administrator.</p>
                    
                    <div class='footer'>
                        <p>This is an automated message. Please do not reply to this email.</p>
                    </div>
                </div>
            </body>
            </html>";

            $mail->AltBody = "Hello $name,\n\nYour branch admin account has been created for store: $store_name.\n\nYou can now log in using your email and password.\n\nIf you have any questions, please contact the system administrator.";

            $mail->send();
            error_log("✅ Welcome email sent successfully to: $email");
            return true;
        } catch (Exception $e) {
            error_log("❌ Email failed: " . $mail->ErrorInfo);
            return false;
        }
    }

    if (!function_exists('getSystemEmail')) {
        function getSystemEmail() {
            // Define this in your config.php or use a default
            return defined('SYSTEM_EMAIL') ? SYSTEM_EMAIL : "noreply@restaurant-system.com";
        }
    }

    // Handle delete action
    if (isset($_GET['delete_id'])) {
        $delete_id = $_GET['delete_id'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // First, get the restaurant_id associated with this user
            $getRestaurantQuery = "SELECT id FROM restaurants WHERE owner_id = ?";
            $stmt = $conn->prepare($getRestaurantQuery);
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $restaurant = $result->fetch_assoc();
            $stmt->close();
            
            // Delete from restaurants table if exists
            if ($restaurant) {
                $deleteRestaurantQuery = "DELETE FROM restaurants WHERE owner_id = ?";
                $stmt = $conn->prepare($deleteRestaurantQuery);
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Delete from users table
            $deleteUserQuery = "DELETE FROM users WHERE id = ? AND role = 'branch_admin'";
            $stmt = $conn->prepare($deleteUserQuery);
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            echo '<script>window.location.href = "?msg=deleted";</script>';
            exit();
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $error = "Failed to delete branch admin: " . $e->getMessage();
            echo '<script>window.location.href = "?msg=delete_error";</script>';
            exit();
        }
    }

    // Handle activate/deactivate action
    if (isset($_GET['toggle_status'])) {
        $admin_id = $_GET['toggle_status'];
        $new_status = $_GET['status'];
        
        try {
            // Update user status
            $updateUserQuery = "UPDATE users SET status = ? WHERE id = ? AND role = 'branch_admin'";
            $stmt = $conn->prepare($updateUserQuery);
            $stmt->bind_param("si", $new_status, $admin_id);
            $stmt->execute();
            $stmt->close();
            
            // Also update restaurant status if exists
            $updateRestaurantQuery = "UPDATE restaurants SET status = ? WHERE owner_id = ?";
            $stmt = $conn->prepare($updateRestaurantQuery);
            $stmt->bind_param("si", $new_status, $admin_id);
            $stmt->execute();
            $stmt->close();
            
            $action = $new_status == 'active' ? 'activated' : 'deactivated';
            echo '<script>window.location.href = "?msg=' . $action . '";</script>';
            exit();
        } catch (Exception $e) {
            $error = "Failed to update status: " . $e->getMessage();
            echo '<script>window.location.href = "?msg=status_error";</script>';
            exit();
        }
    }

    // === DASHBOARD METRICS ===
    $totalUsersQuery = "SELECT COUNT(*) AS total FROM users WHERE role != 'super_admin'";
    $totalUsersResult = safeQuery($conn, $totalUsersQuery);
    $totalUsers = $totalUsersResult ? $totalUsersResult->fetch_assoc()['total'] : 0;

    $activeRestaurantsQuery = "SELECT COUNT(*) AS total FROM restaurants WHERE status = 'active'";
    $activeRestaurantsResult = safeQuery($conn, $activeRestaurantsQuery);
    $activeRestaurants = $activeRestaurantsResult ? $activeRestaurantsResult->fetch_assoc()['total'] : 0;

    $ordersTodayQuery = "SELECT COUNT(*) AS total FROM orders WHERE DATE(created_at) = CURDATE()";
    $ordersTodayResult = safeQuery($conn, $ordersTodayQuery);
    $ordersToday = $ordersTodayResult ? $ordersTodayResult->fetch_assoc()['total'] : 0;

    $revenueTodayQuery = "SELECT SUM(total_amount) AS revenue FROM orders WHERE DATE(created_at) = CURDATE()";
    $revenueTodayResult = safeQuery($conn, $revenueTodayQuery);
    $revenueToday = $revenueTodayResult ? $revenueTodayResult->fetch_assoc()['revenue'] : 0;
    $revenueToday = $revenueToday ?: 0;

    // === FETCH BRANCH ADMINS WITH RESTAURANT INFO ===
    $branchAdmins = [];
    $adminQuery = "
    SELECT u.id, u.name, u.email, u.branch_id, u.store_name, u.owner_id, 
            u.profile_image, u.attachment_file, u.status as user_status,
            r.id as restaurant_id, r.contact_number, r.opening_time, 
            r.closing_time, r.pickup_interval, r.status as restaurant_status, 
            r.category, r.restaurant_image_url
    FROM users u
    LEFT JOIN restaurants r ON u.id = r.owner_id
    WHERE u.role = 'branch_admin'
    ";
    $adminResult = safeQuery($conn, $adminQuery);
    if ($adminResult && $adminResult->num_rows > 0) {
        while ($row = $adminResult->fetch_assoc()) {
            $branchAdmins[] = $row;
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #002147;
        }
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        .dashboard-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .dashboard-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: #002147;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        .dashboard-header p {
            color: #666;
            font-size: 1.1rem;
        }
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 33, 71, 0.1);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 5px solid #ffcc00;
        }
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 33, 71, 0.15);
        }
        .metric-card.users { border-left-color: #002147; }
        .metric-card.restaurants { border-left-color: #28a745; }
        .metric-card.orders { border-left-color: #007bff; }
        .metric-card.revenue { border-left-color: #dc3545; }
        .metric-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: #002147;
        }
        .metric-card h3 {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .metric-value {
            font-size: 2.2rem;
            font-weight: bold;
            color: #002147;
            margin: 0;
            line-height: 1.2;
        }
        .metric-subtext {
            font-size: 0.85rem;
            color: #888;
            margin-top: 8px;
            font-weight: 500;
        }
        .management-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 33, 71, 0.1);
            margin-bottom: 30px;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .section-header h2 {
            color: #002147;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .add-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        .table-responsive {
            overflow-x: auto;
            border-radius: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        thead {
            background: linear-gradient(135deg, #002147 0%, #003366 100%);
        }
        th {
            padding: 16px 12px;
            text-align: left;
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.3s;
        }
        tbody tr:hover {
            background: #f8fafc;
        }
        td {
            padding: 14px 12px;
            text-align: left;
            vertical-align: middle;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ffcc00 0%, #ffd633 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #002147;
            font-weight: bold;
            font-size: 1.1rem;
            overflow: hidden;
        }
        .user-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        .user-details {
            display: flex;
            flex-direction: column;
        }
        .user-name {
            font-weight: 600;
            color: #002147;
        }
        .user-email {
            color: #666;
            font-size: 0.85rem;
        }
        .branch-info {
            display: flex;
            flex-direction: column;
        }
        .branch-name {
            font-weight: 600;
            color: #002147;
        }
        .branch-id {
            color: #666;
            font-size: 0.85rem;
        }
        .store-image-url {
            color: #007bff;
            font-size: 0.75rem;
            font-family: monospace;
            margin-top: 4px;
            word-break: break-all;
        }
        .category-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #e3f2fd;
            color: #1976d2;
            margin-top: 4px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }
        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        .status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        .file-link {
            color: #007bff;
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .file-link:hover {
            text-decoration: underline;
        }
        .no-file {
            color: #888;
            font-style: italic;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .btn-edit {
            background: #17a2b8;
            color: white;
        }
        .btn-edit:hover {
            background: #138496;
            transform: translateY(-1px);
        }
        .btn-activate {
            background: #28a745;
            color: white;
        }
        .btn-activate:hover {
            background: #218838;
            transform: translateY(-1px);
        }
        .btn-deactivate {
            background: #ffc107;
            color: #212529;
        }
        .btn-deactivate:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #666;
        }
        .no-data-content {
            color: #666;
        }
        .no-data-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            display: block;
            opacity: 0.5;
        }
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .modal-title {
            color: #002147;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            padding: 5px;
        }
        .close-btn:hover {
            color: #002147;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #002147;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .form-input, .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s;
        }
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #002147;
        }
        .form-hint {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-message {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #17a2b8;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .warning-message {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .url-display {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.85rem;
            color: #495057;
            margin-top: 8px;
            word-break: break-all;
            border: 1px solid #dee2e6;
        }
        .btn-remove {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            margin-left: 10px;
        }
        .btn-remove:hover {
            background: #c82333;
        }
        .image-preview {
            margin-top: 10px;
            display: none;
        }
        .image-preview img {
            max-width: 150px;
            max-height: 150px;
            border-radius: 8px;
        }
        .user-status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 4px;
        }
        .user-status-badge.active {
            background: #d4edda;
            color: #155724;
        }
        .user-status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        .user-status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        .password-requirements {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            border-left: 4px solid #007bff;
        }
        .requirement {
            margin: 5px 0;
            font-size: 0.85rem;
        }
        .requirement.valid {
            color: #28a745;
        }
        .requirement.invalid {
            color: #dc3545;
        }
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 20px 15px;
            }
            .dashboard-header h1 {
                font-size: 2rem;
            }
            .metrics-grid {
                grid-template-columns: 1fr 1fr;
            }
            .management-section {
                padding: 20px;
            }
            .section-header {
                flex-direction: column;
                align-items: stretch;
            }
            .add-btn {
                width: 100%;
                justify-content: center;
            }
            .modal-content {
                padding: 20px;
            }
            .action-buttons {
                flex-direction: column;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 480px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            .dashboard-header h1 {
                font-size: 1.8rem;
            }
            .metric-card {
                padding: 20px;
            }
            .metric-value {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="dashboard-header">
        <h1><i class="fas fa-tachometer-alt"></i> Super Admin Dashboard</h1>
        <p>Complete overview and management of your platform</p>
    </div>
    
    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] === 'created'): ?>
            <?php if (isset($_GET['email_sent']) && $_GET['email_sent'] === 'true'): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    ✅ Branch Admin created successfully! Welcome email has been sent.
                </div>
            <?php else: ?>
                <div class="warning-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    ⚠️ Branch Admin created but welcome email failed to send. The admin can still login immediately.
                </div>
            <?php endif; ?>
        <?php elseif ($_GET['msg'] === 'password_error' && isset($_GET['error'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                ❌ Password Error: <?= htmlspecialchars(urldecode($_GET['error'])); ?>
            </div>
        <?php else: ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                ✅ Branch Admin <?= htmlspecialchars($_GET['msg']); ?> successfully!
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-triangle"></i>
            ❌ <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <div class="metrics-grid">
        <div class="metric-card users">
            <div class="metric-icon">
                <i class="fas fa-users"></i>
            </div>
            <h3>Total Users</h3>
            <p class="metric-value"><?= number_format($totalUsers); ?></p>
            <div class="metric-subtext">All platform users</div>
        </div>
        
        <div class="metric-card restaurants">
            <div class="metric-icon">
                <i class="fas fa-utensils"></i>
            </div>
            <h3>Active Restaurants</h3>
            <p class="metric-value"><?= number_format($activeRestaurants); ?></p>
            <div class="metric-subtext">Currently operating</div>
        </div>
        
        <div class="metric-card orders">
            <div class="metric-icon">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <h3>Orders Today</h3>
            <p class="metric-value"><?= number_format($ordersToday); ?></p>
            <div class="metric-subtext">Placed today</div>
        </div>
        
        <div class="metric-card revenue">
            <div class="metric-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <h3>Revenue Today</h3>
            <p class="metric-value">₱<?= number_format($revenueToday, 2); ?></p>
            <div class="metric-subtext">Total today's revenue</div>
        </div>
    </div>
    
    <div class="management-section">
        <div class="section-header">
            <h2><i class="fas fa-user-tie"></i> Branch Admin Management</h2>
            <button class="add-btn" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add Branch Admin
            </button>
        </div>
        
        <!-- Info message about immediate login -->
        <div class="info-message">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Immediate Login:</strong> New branch admins can login immediately after creation using their email and password. 
                No OTP verification is required.
            </div>
        </div>
        
        <div class="table-responsive">
            <?php if ($branchAdmins): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Admin Info</th>
                            <th>Branch Details</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Contact</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branchAdmins as $admin): ?>
                            <tr>
                                <td>
                                    <div class="user-info">
                                        <div class="user-avatar">
                                            <?php if (!empty($admin['profile_image'])): ?>
                                                <img src="../uploads/profiles/<?= htmlspecialchars($admin['profile_image']); ?>" 
                                                    alt="Profile" 
                                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; background: linear-gradient(135deg, #ffcc00 0%, #ffd633 100%); color: #002147; font-weight: bold; font-size: 1.1rem; border-radius: 50%;">
                                                    <?= strtoupper(substr($admin['name'], 0, 1)) ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #ffcc00 0%, #ffd633 100%); color: #002147; font-weight: bold; font-size: 1.1rem;">
                                                    <?= strtoupper(substr($admin['name'], 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="user-details">
                                            <div class="user-name"><?= htmlspecialchars($admin['name']); ?></div>
                                            <div class="user-email"><?= htmlspecialchars($admin['email']); ?></div>
                                            <span class="user-status-badge <?= $admin['user_status'] ?? 'active'; ?>">
                                                <?= ucfirst($admin['user_status'] ?? 'active'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="branch-info">
                                        <div class="branch-name"><?= htmlspecialchars($admin['store_name']); ?></div>
                                        <div class="branch-id">Branch ID: <?= htmlspecialchars($admin['branch_id']); ?></div>
                                        <?php if (!empty($admin['restaurant_image_url'])): ?>
                                            <div class="store-image-url" title="<?= htmlspecialchars($admin['restaurant_image_url']); ?>">
                                                <i class="fas fa-image"></i> <?= htmlspecialchars($admin['restaurant_image_url']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($admin['category'])): ?>
                                        <span class="category-badge">
                                            <?= htmlspecialchars($admin['category']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="no-file">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($admin['restaurant_status']): ?>
                                        <span class="status-badge <?= $admin['restaurant_status']; ?>">
                                            <?= ucfirst($admin['restaurant_status']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="no-file">No restaurant</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($admin['contact_number']): ?>
                                        <div><?= htmlspecialchars($admin['contact_number']); ?></div>
                                    <?php else: ?>
                                        <span class="no-file">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-edit" onclick='openEditModal(<?= json_encode($admin); ?>)'>
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php if (($admin['user_status'] ?? 'active') == 'active'): ?>
                                            <button class="btn btn-deactivate" onclick="toggleStatus('<?= $admin['id']; ?>', 'inactive', '<?= htmlspecialchars(addslashes($admin['name'])); ?>')">
                                                <i class="fas fa-pause"></i> Deactivate
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-activate" onclick="toggleStatus('<?= $admin['id']; ?>', 'active', '<?= htmlspecialchars(addslashes($admin['name'])); ?>')">
                                                <i class="fas fa-play"></i> Activate
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-delete" onclick="confirmDelete('<?= $admin['id']; ?>', '<?= htmlspecialchars(addslashes($admin['name'])); ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">
                    <div class="no-data-content">
                        <span class="no-data-icon">👥</span>
                        <h3>No Branch Admins Found</h3>
                        <p>Get started by adding your first branch admin</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit/Add Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">
                <i class="fas fa-user-plus"></i> Add Branch Admin
            </h3>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        
        <form id="adminForm" method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" id="formAction" value="create_branch_admin">
            <input type="hidden" name="user_id" id="adminId" value="">
            <input type="hidden" name="restaurant_id" id="restaurantId" value="">
            
            <div class="form-group">
                <label class="form-label" for="adminName">Full Name *</label>
                <input type="text" name="name" id="adminName" class="form-input" placeholder="Enter full name" required>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="adminEmail">Email *</label>
                <input type="email" name="email" id="adminEmail" class="form-input" placeholder="Enter email address" required>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="adminPassword">Password *</label>
                <input type="password" name="password" id="adminPassword" class="form-input" placeholder="Enter password" required oninput="validatePasswordStrength(this.value)">
                <div class="form-hint" id="passwordHint">Required when adding a new Branch Admin</div>
                
                <!-- Password Requirements Display -->
                <div class="password-requirements" id="passwordRequirements">
                    <strong>Password Requirements:</strong>
                    <div class="requirement invalid" id="reqLength">✓ At least 11 characters long</div>
                    <div class="requirement invalid" id="reqUppercase">✓ At least one uppercase letter (A-Z)</div>
                    <div class="requirement invalid" id="reqLowercase">✓ At least one lowercase letter (a-z)</div>
                    <div class="requirement invalid" id="reqNumber">✓ At least one number (0-9)</div>
                    <div class="requirement invalid" id="reqSpecial">✓ At least one special character (!@#$%^&*()-_=+{};:,<.>)</div>
                    <div class="requirement invalid" id="reqNoSpaces">✓ No spaces allowed</div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="adminStoreName">Store Name *</label>
                    <input type="text" name="store_name" id="adminStoreName" class="form-input" placeholder="Enter store name" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="adminCategory">Category *</label>
                    <input type="text" name="category" id="adminCategory" class="form-input" placeholder="e.g., Fast Food, Cafe" required>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Store Image URL</label>
                <div class="form-hint">This will be automatically generated based on the store name</div>
                <div id="storeImageUrlPreview" class="url-display" style="display: none;">
                    <i class="fas fa-link"></i> <span id="urlPreviewText"></span>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="adminContact">Contact Number</label>
                <input type="text" name="contact_number" id="adminContact" class="form-input" placeholder="Enter contact number">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="openingTime">Opening Time</label>
                    <input type="time" name="opening_time" id="openingTime" class="form-input" value="09:00">
                </div>
                <div class="form-group">
                    <label class="form-label" for="closingTime">Closing Time</label>
                    <input type="time" name="closing_time" id="closingTime" class="form-input" value="21:00">
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="pickupInterval">Pickup Interval (minutes)</label>
                <input type="number" name="pickup_interval" id="pickupInterval" class="form-input" value="30" min="15" max="120">
            </div>
            
            <!-- Profile Image Section -->
            <div class="form-group">
                <label class="form-label">Profile Image</label>
                <input type="file" name="profile_image" id="profileImageInput" class="form-input" accept="image/*" onchange="previewImage(this, 'profileImagePreview')">
                <div class="form-hint">Optional: JPG, JPEG, PNG, GIF (Max: 2MB)</div>
                <div id="profileImagePreview" class="image-preview">
                    <img id="profilePreviewImg" src="" alt="Profile Preview">
                    <button type="button" class="btn-remove" onclick="removePreview('profileImageInput', 'profileImagePreview')">Remove</button>
                </div>
                <div id="currentProfileImage"></div>
            </div>
            
            <div class="modal-actions">
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-save"></i> Save
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-exclamation-triangle"></i> Confirm Delete
            </h3>
            <button class="close-btn" onclick="closeDeleteModal()">&times;</button>
        </div>
        
        <p id="deleteMessage" style="margin-bottom: 20px; line-height: 1.5;">
            Are you sure you want to delete this branch admin?
        </p>
        
        <div class="modal-actions">
            <button class="btn btn-delete" onclick="proceedDelete()">
                <i class="fas fa-trash"></i> Yes, Delete
            </button>
            <button class="btn btn-secondary" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>

<!-- Status Toggle Modal -->
<div id="statusModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="statusModalTitle">
                <i class="fas fa-exclamation-triangle"></i> Confirm Status Change
            </h3>
            <button class="close-btn" onclick="closeStatusModal()">&times;</button>
        </div>
        
        <p id="statusMessage" style="margin-bottom: 20px; line-height: 1.5;">
            Are you sure you want to change the status of this branch admin?
        </p>
        
        <div class="modal-actions">
            <button class="btn" id="statusConfirmBtn" onclick="proceedStatusChange()">
                <i class="fas fa-check"></i> Confirm
            </button>
            <button class="btn btn-secondary" onclick="closeStatusModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>

<script>
let adminToDelete = null;
let adminToToggle = null;
let newStatus = null;
let isPasswordValid = false;

function generateStoreImageUrl(storeName) {
    let sanitized = storeName.replace(/[^a-zA-Z0-9\s\-_]/g, '');
    sanitized = sanitized.replace(/[\s\-]+/g, '_');
    sanitized = sanitized.replace(/_+/g, '_');
    sanitized = sanitized.replace(/^_+|_+$/g, '');
    return 'uploads/stores/' + sanitized + '.jpg';
}

function updateStoreImagePreview() {
    const storeName = document.getElementById('adminStoreName').value;
    const preview = document.getElementById('storeImageUrlPreview');
    const previewText = document.getElementById('urlPreviewText');
    
    if (storeName.trim()) {
        const url = generateStoreImageUrl(storeName);
        previewText.textContent = url;
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
}

function validatePasswordStrength(password) {
    const requirements = {
        length: password.length >= 11,
        uppercase: /[A-Z]/.test(password),
        lowercase: /[a-z]/.test(password),
        number: /[0-9]/.test(password),
        special: /[!@#$%^&*()\-_=+{};:,<.>]/.test(password),
        noSpaces: !/\s/.test(password)
    };
    
    // Update requirement displays
    document.getElementById('reqLength').className = requirements.length ? 'requirement valid' : 'requirement invalid';
    document.getElementById('reqUppercase').className = requirements.uppercase ? 'requirement valid' : 'requirement invalid';
    document.getElementById('reqLowercase').className = requirements.lowercase ? 'requirement valid' : 'requirement invalid';
    document.getElementById('reqNumber').className = requirements.number ? 'requirement valid' : 'requirement invalid';
    document.getElementById('reqSpecial').className = requirements.special ? 'requirement valid' : 'requirement invalid';
    document.getElementById('reqNoSpaces').className = requirements.noSpaces ? 'requirement valid' : 'requirement invalid';
    
    // Check if all requirements are met
    isPasswordValid = Object.values(requirements).every(req => req);
    
    // Update submit button state
    const submitBtn = document.getElementById('submitBtn');
    const formAction = document.getElementById('formAction').value;
    
    if (formAction === 'create_branch_admin') {
        submitBtn.disabled = !isPasswordValid;
        submitBtn.title = isPasswordValid ? '' : 'Please meet all password requirements';
    }
}

function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    const img = preview.querySelector('img');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            img.src = e.target.result;
            preview.style.display = 'block';
        }
        
        reader.readAsDataURL(input.files[0]);
    }
}

function removePreview(inputId, previewId) {
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    input.value = '';
    preview.style.display = 'none';
}

function openAddModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add Branch Admin';
    document.getElementById('adminForm').reset();
    document.getElementById('formAction').value = 'create_branch_admin';
    document.getElementById('adminId').value = '';
    document.getElementById('restaurantId').value = '';
    
    document.getElementById('adminPassword').required = true;
    document.getElementById('passwordHint').textContent = 'Required when adding a new Branch Admin';
    document.getElementById('adminCategory').required = true;
    document.getElementById('openingTime').value = '09:00';
    document.getElementById('closingTime').value = '21:00';
    document.getElementById('pickupInterval').value = '30';
    
    // Reset password validation
    validatePasswordStrength('');
    document.getElementById('submitBtn').disabled = true;
    
    // Hide URL preview on new modal
    document.getElementById('storeImageUrlPreview').style.display = 'none';
    document.getElementById('currentProfileImage').innerHTML = '';
    document.getElementById('profileImagePreview').style.display = 'none';
    
    document.getElementById('editModal').style.display = 'flex';
}

function openEditModal(admin) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Branch Admin';
    document.getElementById('adminForm').reset();
    
    document.getElementById('formAction').value = 'update_branch_admin';
    document.getElementById('adminId').value = admin.id;
    document.getElementById('restaurantId').value = admin.restaurant_id || '';
    document.getElementById('adminName').value = admin.name;
    document.getElementById('adminEmail').value = admin.email;
    document.getElementById('adminStoreName').value = admin.store_name;
    document.getElementById('adminCategory').value = admin.category || '';
    document.getElementById('adminContact').value = admin.contact_number || '';
    document.getElementById('openingTime').value = admin.opening_time || '09:00';
    document.getElementById('closingTime').value = admin.closing_time || '21:00';
    document.getElementById('pickupInterval').value = admin.pickup_interval || '30';
    
    document.getElementById('adminPassword').required = false;
    document.getElementById('passwordHint').textContent = 'Leave blank to keep current password';
    document.getElementById('adminPassword').value = '';
    document.getElementById('adminCategory').required = false;
    
    // Reset password validation for edit mode
    validatePasswordStrength('');
    document.getElementById('submitBtn').disabled = false;
    
    // Show current store image URL
    if (admin.restaurant_image_url) {
        document.getElementById('urlPreviewText').textContent = admin.restaurant_image_url;
        document.getElementById('storeImageUrlPreview').style.display = 'block';
    } else {
        updateStoreImagePreview();
    }
    
    // Show current profile image if exists
    const currentProfileDiv = document.getElementById('currentProfileImage');
    if (admin.profile_image) {
        currentProfileDiv.innerHTML = `
            <div style="margin-top: 10px;">
                <strong>Current Profile Image:</strong><br>
                <img src="../uploads/profiles/${admin.profile_image}" alt="Current Profile" style="max-width: 150px; max-height: 150px; border-radius: 8px; margin-top: 5px;">
                <button type="button" class="btn-remove" onclick="removeExistingImage(${admin.id}, 'profile')" style="display: block; margin-top: 5px;">
                    Remove Current Image
                </button>
            </div>
        `;
    } else {
        currentProfileDiv.innerHTML = '';
    }
    
    document.getElementById('profileImagePreview').style.display = 'none';
    
    document.getElementById('editModal').style.display = 'flex';
}

function removeExistingImage(userId, imageType) {
    if (confirm(`Are you sure you want to remove the current ${imageType} image?`)) {
        const formData = new FormData();
        formData.append('action', 'remove_image');
        formData.append('user_id', userId);
        formData.append('image_type', imageType);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error removing image: ' + error);
        });
    }
}

function toggleStatus(adminId, status, adminName) {
    adminToToggle = adminId;
    newStatus = status;
    
    const action = status === 'active' ? 'activate' : 'deactivate';
    const statusModalTitle = document.getElementById('statusModalTitle');
    const statusMessage = document.getElementById('statusMessage');
    const statusConfirmBtn = document.getElementById('statusConfirmBtn');
    
    statusModalTitle.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Confirm ${action.charAt(0).toUpperCase() + action.slice(1)}`;
    statusMessage.innerHTML = `
        Are you sure you want to ${action} branch admin <strong>"${adminName}"</strong>?<br><br>
        <small style="color: #${status === 'active' ? '28a745' : 'ffc107'};">
            ${status === 'active' ? 
                '✅ This will allow the branch admin to access their account and manage their restaurant.' : 
                '⚠️ This will prevent the branch admin from accessing their account and their restaurant will be hidden from users.'}
        </small>
    `;
    
    statusConfirmBtn.className = `btn ${status === 'active' ? 'btn-activate' : 'btn-deactivate'}`;
    statusConfirmBtn.innerHTML = `<i class="fas fa-${status === 'active' ? 'play' : 'pause'}"></i> Yes, ${action.charAt(0).toUpperCase() + action.slice(1)}`;
    
    document.getElementById('statusModal').style.display = 'flex';
}

function proceedStatusChange() {
    if (adminToToggle && newStatus) {
        window.location.href = `?toggle_status=${adminToToggle}&status=${newStatus}`;
    }
}

function closeStatusModal() {
    document.getElementById('statusModal').style.display = 'none';
    adminToToggle = null;
    newStatus = null;
}

function confirmDelete(adminId, adminName) {
    adminToDelete = adminId;
    document.getElementById('deleteMessage').innerHTML = 
        `Are you sure you want to delete branch admin <strong>"${adminName}"</strong>?<br><br>
        <small style="color: #dc3545;">This action will also delete the associated restaurant and cannot be undone.</small>`;
    document.getElementById('deleteModal').style.display = 'flex';
}

function proceedDelete() {
    if (adminToDelete) {
        window.location.href = `?delete_id=${adminToDelete}`;
    }
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    adminToDelete = null;
}

window.onclick = function(event) {
    const editModal = document.getElementById('editModal');
    const deleteModal = document.getElementById('deleteModal');
    const statusModal = document.getElementById('statusModal');
    
    if (event.target === editModal) {
        closeModal();
    }
    if (event.target === deleteModal) {
        closeDeleteModal();
    }
    if (event.target === statusModal) {
        closeStatusModal();
    }
}

function updateActivity() {
    fetch('../admin/update_activity.php')
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.log('Activity update failed:', data.error);
            }
        })
        .catch(error => console.log('Error updating activity:', error));
}

updateActivity();
setInterval(updateActivity, 30000);

// Auto-update store image URL preview when store name changes
document.addEventListener('DOMContentLoaded', function() {
    const storeNameInput = document.getElementById('adminStoreName');
    if (storeNameInput) {
        storeNameInput.addEventListener('input', updateStoreImagePreview);
    }
    
    // Add form validation for password
    const adminForm = document.getElementById('adminForm');
    if (adminForm) {
        adminForm.addEventListener('submit', function(e) {
            const formAction = document.getElementById('formAction').value;
            const password = document.getElementById('adminPassword').value;
            
            if (formAction === 'create_branch_admin') {
                if (!isPasswordValid) {
                    e.preventDefault();
                    alert('Please meet all password requirements before submitting.');
                    return false;
                }
            }
            
            if (formAction === 'update_branch_admin' && password !== '') {
                if (!isPasswordValid) {
                    e.preventDefault();
                    alert('Please meet all password requirements before submitting.');
                    return false;
                }
            }
            
            return true;
        });
    }
});
</script>
</body>
</html>