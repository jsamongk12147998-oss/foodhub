<?php
session_start();

// Security check
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'super_admin') { 
    header("Location: Studlogin.php"); 
    exit(); 
}

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

$response = ['success' => false, 'message' => ''];

try {
    $conn->begin_transaction();
    
    // Determine if this is an add or edit operation
    $isEdit = !empty($_POST['id']);
    $user_id = $isEdit ? intval($_POST['id']) : null;
    
    // Sanitize inputs
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $branch_id = intval($_POST['branch_id']);
    $store_name = trim($_POST['store_name']);
    $contact_number = trim($_POST['contact_number'] ?? '');
    $opening_time = $_POST['opening_time'] ?? '09:00:00';
    $closing_time = $_POST['closing_time'] ?? '21:00:00';
    $pickup_interval = intval($_POST['pickup_interval'] ?? 30);
    
    // Handle password
    $password = null;
    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    } elseif (!$isEdit) {
        throw new Exception("Password is required for new branch admin");
    }
    
    // Handle profile image upload
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Delete old profile image if editing
        if ($isEdit) {
            $getOldImage = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
            $getOldImage->bind_param("i", $user_id);
            $getOldImage->execute();
            $oldImageData = $getOldImage->get_result()->fetch_assoc();
            if ($oldImageData && $oldImageData['profile_image'] && file_exists($upload_dir . $oldImageData['profile_image'])) {
                unlink($upload_dir . $oldImageData['profile_image']);
            }
        }
        
        $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $profile_image = time() . '_' . uniqid() . '.' . $file_extension;
        move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $profile_image);
    }
    
    // Handle attachment file upload
    $attachment_file = null;
    if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Delete old attachment if editing
        if ($isEdit) {
            $getOldFile = $conn->prepare("SELECT attachment_file FROM users WHERE id = ?");
            $getOldFile->bind_param("i", $user_id);
            $getOldFile->execute();
            $oldFileData = $getOldFile->get_result()->fetch_assoc();
            if ($oldFileData && $oldFileData['attachment_file'] && file_exists($upload_dir . $oldFileData['attachment_file'])) {
                unlink($upload_dir . $oldFileData['attachment_file']);
            }
        }
        
        $file_extension = pathinfo($_FILES['attachment_file']['name'], PATHINFO_EXTENSION);
        $attachment_file = time() . '_attachment.' . $file_extension;
        move_uploaded_file($_FILES['attachment_file']['tmp_name'], $upload_dir . $attachment_file);
    }
    
    if ($isEdit) {
        // ===== UPDATE EXISTING BRANCH ADMIN =====
        
        // Get current data
        $getUserData = $conn->prepare("SELECT branch_id, profile_image, attachment_file FROM users WHERE id = ?");
        $getUserData->bind_param("i", $user_id);
        $getUserData->execute();
        $userData = $getUserData->get_result()->fetch_assoc();
        $restaurant_id = $userData['branch_id'];
        
        // Keep old files if no new ones uploaded
        if (!$profile_image) {
            $profile_image = $userData['profile_image'];
        }
        if (!$attachment_file) {
            $attachment_file = $userData['attachment_file'];
        }
        
        // Update user
        if ($password) {
            $stmt = $conn->prepare("
                UPDATE users 
                SET name = ?, email = ?, password = ?, branch_id = ?, store_name = ?, 
                    profile_image = ?, attachment_file = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssissssi", $name, $email, $password, $branch_id, $store_name, 
                            $profile_image, $attachment_file, $user_id);
        } else {
            $stmt = $conn->prepare("
                UPDATE users 
                SET name = ?, email = ?, branch_id = ?, store_name = ?, 
                    profile_image = ?, attachment_file = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssisssi", $name, $email, $branch_id, $store_name, 
                            $profile_image, $attachment_file, $user_id);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update user: " . $stmt->error);
        }
        
        // Update or create restaurant
        if ($restaurant_id) {
            // Update existing restaurant
            $stmt2 = $conn->prepare("
                UPDATE restaurants 
                SET name = ?, owner_name = ?, contact_number = ?, 
                    opening_time = ?, closing_time = ?, pickup_interval = ?
                WHERE id = ?
            ");
            $stmt2->bind_param("sssssii", 
                $store_name, $name, $contact_number, 
                $opening_time, $closing_time, $pickup_interval, $restaurant_id
            );
        } else {
            // Create new restaurant if doesn't exist
            $stmt2 = $conn->prepare("
                INSERT INTO restaurants (name, owner_name, contact_number, 
                                       opening_time, closing_time, pickup_interval, owner_id, 
                                       is_active, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'active')
            ");
            $stmt2->bind_param("sssssii", 
                $store_name, $name, $contact_number, 
                $opening_time, $closing_time, $pickup_interval, $user_id
            );
        }
        
        if (!$stmt2->execute()) {
            throw new Exception("Failed to update restaurant: " . $stmt2->error);
        }
        
        // If we created a new restaurant, update the user's branch_id
        if (!$restaurant_id) {
            $new_restaurant_id = $conn->insert_id;
            $stmt3 = $conn->prepare("UPDATE users SET branch_id = ?, owner_id = ? WHERE id = ?");
            $stmt3->bind_param("iii", $new_restaurant_id, $user_id, $user_id);
            $stmt3->execute();
        }
        
        $conn->commit();
        $response['message'] = "Branch Admin updated successfully!";
        
    } else {
        // ===== CREATE NEW BRANCH ADMIN =====
        
        // Check if email already exists
        $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $checkEmail->bind_param("s", $email);
        $checkEmail->execute();
        if ($checkEmail->get_result()->num_rows > 0) {
            throw new Exception("Email already exists");
        }
        
        // Insert user first
        $stmt = $conn->prepare("
            INSERT INTO users (name, email, password, role, branch_id, store_name, owner_id, 
                             profile_image, attachment_file, is_active, status) 
            VALUES (?, ?, ?, 'branch_admin', ?, ?, NULL, ?, ?, 1, 'active')
        ");
        $stmt->bind_param("ssissss", $name, $email, $password, $branch_id, $store_name, 
                         $profile_image, $attachment_file);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create user: " . $stmt->error);
        }
        
        $new_user_id = $conn->insert_id;
        
        // Create restaurant with active status by default
        $stmt2 = $conn->prepare("
            INSERT INTO restaurants (name, owner_name, contact_number, 
                                   opening_time, closing_time, pickup_interval, owner_id, 
                                   is_active, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'active')
        ");
        $stmt2->bind_param("sssssii", 
            $store_name, $name, $contact_number, 
            $opening_time, $closing_time, $pickup_interval, $new_user_id
        );
        
        if (!$stmt2->execute()) {
            throw new Exception("Failed to create restaurant: " . $stmt2->error);
        }
        
        $new_restaurant_id = $conn->insert_id;
        
        // Update user with restaurant reference
        $stmt3 = $conn->prepare("UPDATE users SET branch_id = ?, owner_id = ? WHERE id = ?");
        $stmt3->bind_param("iii", $new_restaurant_id, $new_user_id, $new_user_id);
        
        if (!$stmt3->execute()) {
            throw new Exception("Failed to link restaurant to user: " . $stmt3->error);
        }
        
        $conn->commit();
        $response['message'] = "Branch Admin created successfully!";
    }
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = $e->getMessage();
}

// Redirect back to dashboard with message
$_SESSION['alert_message'] = $response['message'];
$_SESSION['alert_type'] = $response['success'] ? 'success' : 'error';
header("Location: Super_Admin.php" . ($response['success'] ? "?msg=" . ($isEdit ? "updated" : "added") : ""));
exit();
?>