<?php
session_start();
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');
// Security check
// Security check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'branch_admin') {
    header("Location: ../admin/dashboard.php");
    exit();
}
$currentPage = 'settings';
$pageTitle = 'Settings - Branch Admin';
$message = "";
$error = "";
$adminId = $_SESSION['user_id'] ?? 0;
$adminName = $_SESSION['name'] ?? "Branch Admin";
$firstLetter = strtoupper(substr($adminName, 0, 1));
// Get the restaurant owned by this branch admin
$restaurantId = null;
$restaurantData = [];
try {
    $stmt = $conn->prepare("SELECT * FROM restaurants WHERE owner_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $restaurantId = $row['id'];
        $restaurantData = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching restaurant: " . $e->getMessage());
}
// Get admin user data
$userData = [];
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $userData = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching user data: " . $e->getMessage());
}
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_restaurant':
                if ($restaurantId) {
                    try {
                        $restaurantName = trim($_POST['restaurant_name']);
                        $ownerName = trim($_POST['owner_name']);
                        $contactNumber = trim($_POST['contact_number']);
                        $category = trim($_POST['category']);
                        $openingTime = $_POST['opening_time'];
                        $closingTime = $_POST['closing_time'];
                        $pickupInterval = intval($_POST['pickup_interval']);
                        // Handle store image upload
                        $storeImage = $restaurantData['store_image'];
                        if (isset($_FILES['store_image']) && $_FILES['store_image']['error'] === UPLOAD_ERR_OK) {
                            $uploadDir = __DIR__ . '/../uploads/restaurants/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }
                            $fileExtension = strtolower(pathinfo($_FILES['store_image']['name'], PATHINFO_EXTENSION));
                            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                            if (in_array($fileExtension, $allowedExtensions)) {
                                $fileName = 'restaurant_' . $restaurantId . '_' . time() . '.' . $fileExtension;
                                $uploadPath = $uploadDir . $fileName;
                                if (move_uploaded_file($_FILES['store_image']['tmp_name'], $uploadPath)) {
                                    // Delete old image if exists
                                    if (!empty($storeImage) && file_exists(__DIR__ . '/../' . $storeImage)) {
                                        unlink(__DIR__ . '/../' . $storeImage);
                                    }
                                    $storeImage = 'uploads/restaurants/' . $fileName;
                                }
                            }
                        }
                        $stmt = $conn->prepare("
                            UPDATE restaurants 
                            SET name = ?, 
                                owner_name = ?, 
                                contact_number = ?, 
                                category = ?,
                                opening_time = ?, 
                                closing_time = ?, 
                                pickup_interval = ?,
                                store_image = ?
                            WHERE id = ? AND owner_id = ?
                        ");
                        $stmt->bind_param("sssssisiii", 
                            $restaurantName, 
                            $ownerName, 
                            $contactNumber,
                            $category, 
                            $openingTime, 
                            $closingTime, 
                            $pickupInterval,
                            $storeImage,
                            $restaurantId, 
                            $adminId
                        );
                        if ($stmt->execute()) {
                            $message = "Restaurant settings updated successfully!";
                            // Refresh restaurant data
                            $restaurantData['name'] = $restaurantName;
                            $restaurantData['owner_name'] = $ownerName;
                            $restaurantData['contact_number'] = $contactNumber;
                            $restaurantData['category'] = $category;
                            $restaurantData['opening_time'] = $openingTime;
                            $restaurantData['closing_time'] = $closingTime;
                            $restaurantData['pickup_interval'] = $pickupInterval;
                            $restaurantData['store_image'] = $storeImage;
                        } else {
                            $error = "Failed to update restaurant settings.";
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $error = "Database error: " . $e->getMessage();
                    }
                } else {
                    $error = "No restaurant found for this account.";
                }
                break;
            case 'update_profile':
                try {
                    $name = trim($_POST['name']);
                    $email = trim($_POST['email']);
                    // Check if email is already used by another user
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->bind_param("si", $email, $adminId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $error = "Email is already in use by another account.";
                        $stmt->close();
                        break;
                    }
                    $stmt->close();
                    // Handle profile image upload
                    $profileImage = $userData['profile_image'];
                    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/../uploads/profiles/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $fileExtension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                        if (in_array($fileExtension, $allowedExtensions)) {
                            $fileName = 'profile_' . $adminId . '_' . time() . '.' . $fileExtension;
                            $uploadPath = $uploadDir . $fileName;
                            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadPath)) {
                                // Delete old image if exists
                                if (!empty($profileImage) && file_exists(__DIR__ . '/../' . $profileImage)) {
                                    unlink(__DIR__ . '/../' . $profileImage);
                                }
                                $profileImage = 'uploads/profiles/' . $fileName;
                            }
                        }
                    }
                    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, profile_image = ? WHERE id = ?");
                    $stmt->bind_param("sssi", $name, $email, $profileImage, $adminId);
                    if ($stmt->execute()) {
                        $_SESSION['name'] = $name;
                        $_SESSION['email'] = $email;
                        $userData['name'] = $name;
                        $userData['email'] = $email;
                        $userData['profile_image'] = $profileImage;
                        $adminName = $name;
                        $firstLetter = strtoupper(substr($name, 0, 1));
                        $message = "Profile updated successfully!";
                    } else {
                        $error = "Failed to update profile.";
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    $error = "Database error: " . $e->getMessage();
                }
                break;
            case 'change_password':
                try {
                    $currentPassword = $_POST['current_password'];
                    $newPassword = $_POST['new_password'];
                    $confirmPassword = $_POST['confirm_password'];
                    // Verify current password
                    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                    $stmt->bind_param("i", $adminId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user = $result->fetch_assoc();
                    $stmt->close();
                    if (!password_verify($currentPassword, $user['password'])) {
                        $error = "Current password is incorrect!";
                    } elseif ($newPassword !== $confirmPassword) {
                        $error = "New passwords do not match!";
                    } elseif (strlen($newPassword) < 6) {
                        $error = "Password must be at least 6 characters long!";
                    } else {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->bind_param("si", $hashedPassword, $adminId);
                        if ($stmt->execute()) {
                            $message = "Password changed successfully!";
                        } else {
                            $error = "Failed to change password.";
                        }
                        $stmt->close();
                    }
                } catch (Exception $e) {
                    $error = "Database error: " . $e->getMessage();
                }
                break;
            case 'toggle_restaurant_status':
                if ($restaurantId) {
                    try {
                        $newStatus = $_POST['status'];
                        $validStatuses = ['active', 'inactive'];
                        
                        if (!in_array($newStatus, $validStatuses)) {
                            $error = "Invalid status value.";
                            break;
                        }
                        $stmt = $conn->prepare("UPDATE restaurants SET status = ? WHERE id = ? AND owner_id = ?");
                        $stmt->bind_param("sii", $newStatus, $restaurantId, $adminId);
                        if ($stmt->execute()) {
                            $restaurantData['status'] = $newStatus;
                            $message = "Restaurant status updated to " . ucfirst($newStatus) . "!";
                        } else {
                            $error = "Failed to update restaurant status.";
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $error = "Database error: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}
// Set default values
$restaurantName = $restaurantData['name'] ?? 'N/A';
$ownerName = $restaurantData['owner_name'] ?? '';
$contactNumber = $restaurantData['contact_number'] ?? '';
$category = $restaurantData['category'] ?? '';
$openingTime = $restaurantData['opening_time'] ?? '09:00';
$closingTime = $restaurantData['closing_time'] ?? '21:00';
$pickupInterval = $restaurantData['pickup_interval'] ?? 30;
$storeImage = $restaurantData['store_image'] ?? '';
$restaurantStatus = $restaurantData['status'] ?? 'active';
$userName = $userData['name'] ?? '';
$userEmail = $userData['email'] ?? '';
$profileImage = $userData['profile_image'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<style>
/* === CSS Variables for Theme === */
:root {
    --primary-color: #6C63FF;
    --secondary-color: #FF6584;
    --accent-color: #36D1DC;
    --bg-color: #F8FAFC;
    --card-bg: #FFFFFF;
    --text-color: #2D3748;
    --text-secondary: #718096;
    --border-color: #E2E8F0;
    --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    --sidebar-width: 260px;
    --topbar-height: 70px;
    --border-radius: 16px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* === Dark Theme Variables === */
[data-theme="dark"] {
    --primary-color: #8B5FBF;
    --secondary-color: #FF6584;
    --accent-color: #36D1DC;
    --bg-color: #0F1419;
    --card-bg: #1A202C;
    --text-color: #E2E8F0;
    --text-secondary: #A0AEC0;
    --border-color: #2D3748;
    --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2);
    --shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.2);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    transition: background-color 0.3s ease, color 0.3s ease;
}

body {
    font-family: "Inter", -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--bg-color);
    color: var(--text-color);
    line-height: 1.6;
}

a {
    text-decoration: none;
}

button {
    cursor: pointer;
}

/* === Topbar === */
.topbar {
    background: var(--card-bg);
    color: var(--text-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 30px;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1001;
    height: var(--topbar-height);
    box-shadow: var(--shadow);
    border-bottom: 1px solid var(--border-color);
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 20px;
}

.hamburger {
    font-size: 24px;
    background: none;
    border: none;
    color: var(--primary-color);
    cursor: pointer;
    padding: 8px;
    border-radius: 10px;
    transition: var(--transition);
}

.hamburger:hover {
    background: rgba(108, 99, 255, 0.1);
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 700;
    font-size: 1.4rem;
    color: var(--primary-color);
}

.logo-icon {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 20px;
    position: relative;
}

.theme-toggle {
    background: none;
    border: none;
    color: var(--text-secondary);
    font-size: 20px;
    cursor: pointer;
    padding: 8px;
    border-radius: 10px;
    transition: var(--transition);
}

.theme-toggle:hover {
    background: rgba(108, 99, 255, 0.1);
    color: var(--primary-color);
}

.profile-pic {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    cursor: pointer;
    transition: var(--transition);
    font-size: 18px;
}

.profile-pic:hover {
    transform: scale(1.05);
    box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.2);
}

.profile-name {
    color: var(--text-color);
    font-weight: 600;
}

.profile-dropdown {
    position: absolute;
    top: 60px;
    right: 0;
    background: var(--card-bg);
    color: var(--text-color);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    display: none;
    flex-direction: column;
    min-width: 180px;
    z-index: 2000;
    box-shadow: var(--shadow-hover);
    overflow: hidden;
    backdrop-filter: blur(10px);
}

.profile-dropdown a {
    padding: 12px 16px;
    color: var(--text-color);
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 10px;
}

.profile-dropdown a:hover {
    background: var(--primary-color);
    color: white;
}

/* === Sidebar === */
.sidebar {
    width: var(--sidebar-width);
    background: var(--card-bg);
    padding-top: var(--topbar-height);
    color: var(--text-color);
    position: fixed;
    top: var(--topbar-height);
    left: 0;
    height: 100vh;
    overflow-y: auto;
    transition: transform 0.3s ease;
    z-index: 1000;
    border-right: 1px solid var(--border-color);
}

.sidebar.collapsed {
    transform: translateX(-260px);
}

.sidebar nav a {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    color: var(--text-color);
    margin: 8px 16px;
    border-radius: 12px;
    transition: var(--transition);
}

.sidebar nav a:hover {
    background: rgba(108, 99, 255, 0.1);
    color: var(--primary-color);
}

.sidebar nav a.active {
    background: var(--primary-color);
    color: white;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(108, 99, 255, 0.3);
}

/* === Main Content === */
.main {
    margin-left: var(--sidebar-width);
    margin-top: var(--topbar-height);
    padding: 30px;
    transition: margin-left 0.3s ease;
}

.main.expanded {
    margin-left: 0;
}

.page-header {
    margin-bottom: 24px;
}

.page-title {
    color: var(--text-color);
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 8px;
}

/* === Alerts === */
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    border-left: 4px solid;
    background: var(--card-bg);
    box-shadow: var(--shadow);
    animation: slideIn 0.3s ease;
}

.alert-success {
    background: #F0FDF4;
    color: #166534;
    border-color: #22C55E;
}

.alert-error {
    background: #FEF2F2;
    color: #991B1B;
    border-color: #EF4444;
}

.alert-warning {
    background: #FFFBEB;
    color: #92400E;
    border-color: #F59E0B;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* === Cards === */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 24px;
    margin-top: 20px;
}

.settings-card {
    background: var(--card-bg);
    border-radius: var(--border-radius);
    border: 1px solid var(--border-color);
    padding: 28px;
    box-shadow: var(--shadow);
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.settings-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
}

.settings-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-hover);
}

.settings-card h3 {
    color: var(--text-color);
    margin-bottom: 20px;
    font-size: 1.3rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* === Forms === */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text-color);
    font-size: 14px;
}

.form-group input, .form-group textarea, .form-group select {
    width: 100%;
    padding: 12px 16px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    font-size: 14px;
    transition: var(--transition);
    background: var(--bg-color);
    color: var(--text-color);
}

.form-group input:focus, .form-group textarea:focus, .form-group select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.1);
}

.form-group small {
    color: var(--text-secondary);
    font-size: 12px;
    margin-top: 5px;
    display: block;
}

/* === Image Preview === */
.image-preview {
    margin-top: 10px;
    position: relative;
}

.image-preview img {
    max-width: 200px;
    max-height: 200px;
    border-radius: 10px;
    border: 2px solid var(--border-color);
    transition: var(--transition);
}

.image-preview img:hover {
    border-color: var(--primary-color);
}

.image-preview-label {
    display: inline-block;
    padding: 10px 20px;
    background: var(--bg-color);
    border: 2px dashed var(--border-color);
    border-radius: 10px;
    cursor: pointer;
    transition: var(--transition);
    font-weight: 500;
    color: var(--text-color);
}

.image-preview-label:hover {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

/* === Buttons === */
.btn {
    display: inline-block;
    background: var(--primary-color);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    font-size: 14px;
    text-align: center;
}

.btn:hover {
    background: #5a52e0;
    transform: translateY(-2px);
    box-shadow: 0 8px 15px rgba(108, 99, 255, 0.3);
}

.btn-secondary {
    background: #6B7280;
}

.btn-secondary:hover {
    background: #4B5563;
}

.btn-success {
    background: #10B981;
}

.btn-success:hover {
    background: #059669;
}

.btn-danger {
    background: #EF4444;
}

.btn-danger:hover {
    background: #DC2626;
}

/* === Status Toggle === */
.status-toggle {
    background: var(--bg-color);
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
}

.status-toggle h4 {
    margin-bottom: 10px;
    color: var(--text-color);
    font-size: 1rem;
}

.status-badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    display: inline-block;
    border: 1px solid;
}

.status-active {
    background: #F0FDF4;
    color: #166534;
    border-color: #BBF7D0;
}

.status-inactive {
    background: #FEF2F2;
    color: #991B1B;
    border-color: #FECACA;
}

/* === Category Badge === */
.category-display {
    background: rgba(108, 99, 255, 0.1);
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    border-left: 4px solid var(--primary-color);
}

.category-display h4 {
    margin-bottom: 8px;
    color: var(--text-color);
    font-size: 14px;
    font-weight: 600;
}

.category-badge {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    background: var(--primary-color);
    color: white;
}

/* === Modal === */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    align-items: center;
    justify-content: center;
    z-index: 3000;
    backdrop-filter: blur(5px);
}

.modal-content {
    background: var(--card-bg);
    padding: 30px;
    border-radius: var(--border-radius);
    width: 500px;
    max-width: 90%;
    box-shadow: var(--shadow-hover);
    animation: modalAppear 0.3s ease-out;
}

@keyframes modalAppear {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h3 {
    color: var(--text-color);
    font-size: 1.3rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.close-modal {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--text-secondary);
    cursor: pointer;
    line-height: 1;
    transition: var(--transition);
}

.close-modal:hover {
    color: var(--primary-color);
}

/* === Security Section === */
.security-section {
    padding: 20px;
    background: var(--bg-color);
    border-radius: 12px;
    margin-bottom: 15px;
    border: 1px solid var(--border-color);
}

.security-section h4 {
    margin-bottom: 10px;
    color: var(--text-color);
    font-size: 1rem;
}

.security-tips {
    padding: 20px;
    background: #FFFBEB;
    border-radius: 12px;
    border: 1px solid #FCD34D;
}

.security-tips h4 {
    margin-bottom: 10px;
    color: #92400E;
    font-size: 1rem;
}

.security-tips p {
    color: #92400E;
    font-size: 13px;
    line-height: 1.6;
}

[data-theme="dark"] .security-tips {
    background: #451A03;
    border-color: #92400E;
}

[data-theme="dark"] .security-tips h4,
[data-theme="dark"] .security-tips p {
    color: #FEF3C7;
}

/* === Responsive === */
@media (max-width: 768px) {
    .main {
        margin-left: 0;
        padding: 20px;
    }
    
    .sidebar {
        transform: translateX(-260px);
    }
    
    .settings-grid {
        grid-template-columns: 1fr;
    }
    
    .topbar {
        padding: 0 20px;
    }
    
    .topbar-right .profile-name {
        display: none;
    }
}

@media (max-width: 480px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }
    
    .settings-card {
        padding: 20px;
    }
    
    .modal-content {
        padding: 20px;
    }
}
</style>
</head>
<body>
<!-- TOPBAR -->
<div class="topbar">
    <div class="topbar-left">
        <button class="hamburger" id="menuBtn">☰</button>
        <div class="logo">
            <div class="logo-icon">F</div>
            <span>FoodHub</span>
        </div>
    </div>
    <div class="topbar-right">
        <button class="theme-toggle" id="themeToggle">🌙</button>
        <div class="profile-pic" id="profileToggle"><?= htmlspecialchars($firstLetter) ?></div>
        <span class="profile-name"><?= htmlspecialchars($adminName) ?></span>
        <div class="profile-dropdown" id="profileDropdown">
            <a href="branch_profile.php">👤 My Profile</a>
            <a href="../users/logout.php">🚪 Sign Out</a>
        </div>
    </div>
</div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <nav>
        <a href="dashboard.php">📊 Dashboard</a>
        <a href="orders.php">🧾 Orders</a>
        <a href="menus.php">🍽 Menu</a>
        <a href="payments.php">💰 Payments</a>
        <a href="settings.php" class="active">⚙️ Settings</a>
    </nav>
</aside>

<!-- MAIN CONTENT -->
<main class="main" id="mainContent">
    <div class="page-header">
        <h1 class="page-title">Settings</h1>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">✓ <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">✗ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if (!$restaurantId): ?>
        <div class="alert alert-warning">
            <strong>⚠️ No Restaurant Assigned</strong><br>
            You don't have a restaurant assigned to your account. Please contact the system administrator.
        </div>
    <?php endif; ?>
    
    <div class="settings-grid">
        <!-- Restaurant Settings -->
        <?php if ($restaurantId): ?>
        <div class="settings-card">
            <h3>🏪 Restaurant Settings</h3>
            
            <!-- Status Toggle -->
            <div class="status-toggle">
                <h4>Restaurant Status</h4>
                <p style="color:var(--text-secondary);font-size:13px;margin-bottom:10px;">
                    Current Status: <span class="status-badge status-<?= strtolower($restaurantStatus) ?>"><?= ucfirst($restaurantStatus) ?></span>
                </p>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_restaurant_status">
                    <input type="hidden" name="status" value="<?= $restaurantStatus === 'active' ? 'inactive' : 'active' ?>">
                    <button type="submit" class="btn btn-<?= $restaurantStatus === 'active' ? 'danger' : 'success' ?>" style="width:100%;">
                        <?= $restaurantStatus === 'active' ? '🔴 Set Inactive' : '🟢 Set Active' ?>
                    </button>
                </form>
            </div>
            
            <!-- Category Display -->
            <?php if (!empty($category)): ?>
            <div class="category-display">
                <h4>Current Category</h4>
                <span class="category-badge"><?= htmlspecialchars($category) ?></span>
            </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_restaurant">
                
                <div class="form-group">
                    <label for="restaurant_name">Restaurant Name</label>
                    <input type="text" id="restaurant_name" name="restaurant_name" value="<?= htmlspecialchars($restaurantName) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="owner_name">Owner Name</label>
                    <input type="text" id="owner_name" name="owner_name" value="<?= htmlspecialchars($ownerName) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="category">Restaurant Category</label>
                    <input type="text" id="category" name="category" value="<?= htmlspecialchars($category) ?>" placeholder="e.g., Fast Food, Cafe, Asian Cuisine" required>
                    <small>Specify your restaurant type (e.g., Fast Food, Cafe, Italian, Chinese, etc.)</small>
                </div>
                
                <div class="form-group">
                    <label for="contact_number">Contact Number</label>
                    <input type="text" id="contact_number" name="contact_number" value="<?= htmlspecialchars($contactNumber) ?>" required>
                    <small>Format: +63 (917) 123-4567</small>
                </div>
                
                <div class="form-group">
                    <label for="opening_time">Opening Time</label>
                    <input type="time" id="opening_time" name="opening_time" value="<?= htmlspecialchars($openingTime) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="closing_time">Closing Time</label>
                    <input type="time" id="closing_time" name="closing_time" value="<?= htmlspecialchars($closingTime) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="pickup_interval">Pickup Interval (minutes)</label>
                    <input type="number" id="pickup_interval" name="pickup_interval" value="<?= htmlspecialchars($pickupInterval) ?>" min="15" max="120" required>
                    <small>Time between order pickups (15-120 minutes)</small>
                </div>
                
                <div class="form-group">
                    <label for="store_image">Store Image</label>
                    <input type="file" id="store_image" name="store_image" accept="image/*" onchange="previewImage(this, 'storeImagePreview')">
                    <small>Accepted formats: JPG, JPEG, PNG, GIF</small>
                    <div class="image-preview" id="storeImagePreview">
                        <?php if ($storeImage): ?>
                            <img src="<?= htmlspecialchars('../' . $storeImage) ?>" alt="Store Image">
                        <?php endif; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn" style="width:100%;">💾 Save Restaurant Settings</button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Profile Settings -->
        <div class="settings-card">
            <h3>👤 Profile Settings</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($userName) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($userEmail) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="profile_image">Profile Picture</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*" onchange="previewImage(this, 'profileImagePreview')">
                    <small>Accepted formats: JPG, JPEG, PNG, GIF</small>
                    <div class="image-preview" id="profileImagePreview">
                        <?php if ($profileImage): ?>
                            <img src="<?= htmlspecialchars('../' . $profileImage) ?>" alt="Profile Picture">
                        <?php endif; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn" style="width:100%;">💾 Update Profile</button>
            </form>
        </div>
        
        <!-- Security Settings -->
        <div class="settings-card">
            <h3>🔒 Security Settings</h3>
            
            <div class="security-section">
                <h4>Change Password</h4>
                <p style="color:var(--text-secondary);margin-bottom:15px;font-size:14px;">Update your password to keep your account secure.</p>
                <button class="btn btn-secondary" type="button" onclick="openModal('changePasswordModal')" style="width:100%;">🔑 Change Password</button>
            </div>
            
            <div class="security-tips">
                <h4>⚠️ Account Security</h4>
                <p>
                    • Use a strong password with at least 6 characters<br>
                    • Never share your password with anyone<br>
                    • Change your password regularly<br>
                    • Log out when using shared devices
                </p>
            </div>
        </div>
    </div>
</main>

<!-- Change Password Modal -->
<div id="changePasswordModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🔑 Change Password</h3>
            <button class="close-modal" onclick="closeModal('changePasswordModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required placeholder="Enter current password">
            </div>
            
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required minlength="6" placeholder="Enter new password">
                <small>Must be at least 6 characters long</small>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="6" placeholder="Confirm new password">
            </div>
            
            <button type="submit" class="btn" style="width:100%;">🔄 Update Password</button>
        </form>
    </div>
</div>

<script>
// Theme toggle functionality
const themeToggle = document.getElementById('themeToggle');
const currentTheme = localStorage.getItem('theme') || 'light';

// Set initial theme
document.documentElement.setAttribute('data-theme', currentTheme);
updateThemeIcon(currentTheme);

themeToggle.addEventListener('click', () => {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateThemeIcon(newTheme);
});

function updateThemeIcon(theme) {
    themeToggle.textContent = theme === 'light' ? '🌙' : '☀️';
}

function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Image preview function
function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Profile dropdown
const profileToggle = document.getElementById('profileToggle');
const profileDropdown = document.getElementById('profileDropdown');
profileToggle.addEventListener('click', e => {
    e.stopPropagation();
    profileDropdown.style.display = profileDropdown.style.display === 'flex' ? 'none' : 'flex';
});

document.addEventListener('click', e => {
    if (profileDropdown && !profileDropdown.contains(e.target) && !profileToggle.contains(e.target)) {
        profileDropdown.style.display = 'none';
    }
});

// Sidebar toggle
const menuBtn = document.getElementById('menuBtn');
const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
menuBtn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', () => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
});
</script>
</body>
</html>