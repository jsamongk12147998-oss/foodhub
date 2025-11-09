<?php
session_start();
// --- SECURITY CHECK ---
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'super_admin') { 
    header("Location: Studlogin.php"); 
    exit(); 
}
// ✅ 1. Load config first (defines ROOT_PATH, DB_PATH, etc.)
require_once(__DIR__ . '/../config.php');
// ✅ 2. Then safely load database connection
require_once(__DIR__ . '/../db/db.php');

// ✅ Initialize variables with defaults FIRST
$adminName = "Super Admin";
$firstLetter = "S";
$alertMessage = '';

// ✅ Helper function to generate store image URL
function generateStoreImageUrl($storeName) {
    // Remove special characters except spaces and hyphens
    $sanitized = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $storeName);
    // Replace spaces and hyphens with underscores
    $sanitized = str_replace([' ', '-'], '_', $sanitized);
    // Remove multiple consecutive underscores
    $sanitized = preg_replace('/_+/', '_', $sanitized);
    // Trim underscores from start and end
    $sanitized = trim($sanitized, '_');
    return 'uploads/stores/' . $sanitized . '.jpg';
}

// ✅ 3. Handle Branch Admin Creation with Restaurant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_branch_admin') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Sanitize inputs
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $store_name = trim($_POST['store_name']);
        $contact_number = trim($_POST['contact_number'] ?? '');
        $opening_time = $_POST['opening_time'] ?? '09:00:00';
        $closing_time = $_POST['closing_time'] ?? '21:00:00';
        $pickup_interval = intval($_POST['pickup_interval'] ?? 30);
        $category = trim($_POST['category'] ?? '');
        
        // Handle profile image upload (optional)
        $profile_image = null;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array(strtolower($file_extension), $allowed_extensions)) {
                $profile_image = time() . '_' . uniqid() . '.' . $file_extension;
                move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $profile_image);
            } else {
                throw new Exception("Invalid profile image format. Allowed: JPG, JPEG, PNG, GIF");
            }
        }
        
        // Generate store image URL path (no actual file creation)
        $store_image_url = generateStoreImageUrl($store_name);
        
        // Check if email already exists
        $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $checkEmail->bind_param("s", $email);
        $checkEmail->execute();
        if ($checkEmail->get_result()->num_rows > 0) {
            throw new Exception("Email already exists");
        }
        
        // Insert user first (without branch_id since we don't have restaurant id yet)
        $stmt = $conn->prepare("
            INSERT INTO users (name, email, password, role, store_name, profile_image, is_active, status) 
            VALUES (?, ?, ?, 'branch_admin', ?, ?, 1, 'active')
        ");
        $stmt->bind_param("sssss", $name, $email, $password, $store_name, $profile_image);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create user: " . $stmt->error);
        }
        
        $user_id = $conn->insert_id;
        
        // Insert restaurant with owner_id and category (using restaurant_image_url)
        $stmt2 = $conn->prepare("
            INSERT INTO restaurants (name, restaurant_image_url, owner_name, contact_number, 
                                   opening_time, closing_time, pickup_interval, owner_id, 
                                   is_active, status, category) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'active', ?)
        ");
        $stmt2->bind_param("ssssssiss", 
            $store_name, 
            $store_image_url, 
            $name, 
            $contact_number, 
            $opening_time, 
            $closing_time, 
            $pickup_interval, 
            $user_id,
            $category
        );
        
        if (!$stmt2->execute()) {
            throw new Exception("Failed to create restaurant: " . $stmt2->error);
        }
        
        $restaurant_id = $conn->insert_id;
        
        // Update user with branch_id (restaurant_id)
        $stmt3 = $conn->prepare("UPDATE users SET branch_id = ?, owner_id = ? WHERE id = ?");
        $stmt3->bind_param("iii", $restaurant_id, $user_id, $user_id);
        
        if (!$stmt3->execute()) {
            throw new Exception("Failed to update user with branch_id: " . $stmt3->error);
        }
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = "Branch Admin and Restaurant created successfully!";
        $response['user_id'] = $user_id;
        $response['restaurant_id'] = $restaurant_id;
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $response['message'] = $e->getMessage();
    }
    
    // Return JSON response for AJAX requests or redirect for regular form submission
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Regular form submission - redirect to prevent form resubmission
    $_SESSION['alert_message'] = $response['message'];
    $_SESSION['alert_type'] = $response['success'] ? 'success' : 'error';
    header("Location: Super_Admin.php");
    exit();
}

// ✅ 4. Handle Branch Admin Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_branch_admin') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $conn->begin_transaction();
        
        $user_id = intval($_POST['user_id']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $store_name = trim($_POST['store_name']);
        $contact_number = trim($_POST['contact_number'] ?? '');
        $opening_time = $_POST['opening_time'] ?? '09:00:00';
        $closing_time = $_POST['closing_time'] ?? '21:00:00';
        $pickup_interval = intval($_POST['pickup_interval'] ?? 30);
        $category = trim($_POST['category'] ?? '');
        
        // Get current user data
        $getUserData = $conn->prepare("SELECT branch_id, profile_image FROM users WHERE id = ?");
        $getUserData->bind_param("i", $user_id);
        $getUserData->execute();
        $userData = $getUserData->get_result()->fetch_assoc();
        $restaurant_id = $userData['branch_id'];
        $current_profile_image = $userData['profile_image'];
        
        // Handle profile image upload (optional)
        $profile_image = $current_profile_image;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array(strtolower($file_extension), $allowed_extensions)) {
                // Delete old image if exists
                if ($current_profile_image && file_exists($upload_dir . $current_profile_image)) {
                    unlink($upload_dir . $current_profile_image);
                }
                
                $profile_image = time() . '_' . uniqid() . '.' . $file_extension;
                move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $profile_image);
            } else {
                throw new Exception("Invalid profile image format. Allowed: JPG, JPEG, PNG, GIF");
            }
        }
        
        // Generate new store image URL path based on store name
        $store_image_url = generateStoreImageUrl($store_name);
        
        // Update user
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $stmt = $conn->prepare("
                UPDATE users 
                SET name = ?, email = ?, password = ?, store_name = ?, profile_image = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sssssi", $name, $email, $password, $store_name, $profile_image, $user_id);
        } else {
            $stmt = $conn->prepare("
                UPDATE users 
                SET name = ?, email = ?, store_name = ?, profile_image = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssssi", $name, $email, $store_name, $profile_image, $user_id);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update user: " . $stmt->error);
        }
        
        // Update restaurant with category (using restaurant_image_url)
        $stmt2 = $conn->prepare("
            UPDATE restaurants 
            SET name = ?, restaurant_image_url = ?, owner_name = ?, 
                contact_number = ?, opening_time = ?, closing_time = ?, pickup_interval = ?, category = ?
            WHERE id = ?
        ");
        $stmt2->bind_param("sssssssii", 
            $store_name, 
            $store_image_url, 
            $name, 
            $contact_number, 
            $opening_time, 
            $closing_time, 
            $pickup_interval,
            $category, 
            $restaurant_id
        );
        
        if (!$stmt2->execute()) {
            throw new Exception("Failed to update restaurant: " . $stmt2->error);
        }
        
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = "Branch Admin and Restaurant updated successfully!";
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = $e->getMessage();
    }
    
    // Return JSON response for AJAX requests or redirect for regular form submission
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Regular form submission - redirect
    $_SESSION['alert_message'] = $response['message'];
    $_SESSION['alert_type'] = $response['success'] ? 'success' : 'error';
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ✅ 5. Handle Branch Admin Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_branch_admin') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $conn->begin_transaction();
        
        $user_id = intval($_POST['user_id']);
        
        // Get user and restaurant data
        $getUserData = $conn->prepare("SELECT branch_id, profile_image FROM users WHERE id = ?");
        $getUserData->bind_param("i", $user_id);
        $getUserData->execute();
        $userData = $getUserData->get_result()->fetch_assoc();
        
        if ($userData) {
            $restaurant_id = $userData['branch_id'];
            $profile_image = $userData['profile_image'];
            
            // Delete profile image if exists
            if ($profile_image) {
                $profile_path = __DIR__ . '/../uploads/profiles/' . $profile_image;
                if (file_exists($profile_path)) {
                    unlink($profile_path);
                }
            }
            
            // Delete restaurant (CASCADE will handle products)
            // Note: We're not deleting actual store image files since they're just URL paths
            if ($restaurant_id) {
                $stmt = $conn->prepare("DELETE FROM restaurants WHERE id = ?");
                $stmt->bind_param("i", $restaurant_id);
                $stmt->execute();
            }
            
            // Delete user (CASCADE will handle cart, orders, sessions, etc.)
            $stmt2 = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt2->bind_param("i", $user_id);
            $stmt2->execute();
            
            $conn->commit();
            
            $response['success'] = true;
            $response['message'] = "Branch Admin and Restaurant deleted successfully!";
        } else {
            throw new Exception("User not found");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = $e->getMessage();
    }
    
    // Return JSON response for AJAX requests or redirect for regular form submission
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Regular form submission - redirect
    $_SESSION['alert_message'] = $response['message'];
    $_SESSION['alert_type'] = $response['success'] ? 'success' : 'error';
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ✅ 6. Handle Image Removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_image') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $user_id = intval($_POST['user_id']);
        $image_type = $_POST['image_type']; // 'profile' or 'store'
        
        if ($image_type === 'profile') {
            // Get current profile image
            $getUserData = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
            $getUserData->bind_param("i", $user_id);
            $getUserData->execute();
            $userData = $getUserData->get_result()->fetch_assoc();
            $current_image = $userData['profile_image'];
            
            if ($current_image) {
                // Delete file
                $image_path = __DIR__ . '/../uploads/profiles/' . $current_image;
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
                
                // Update database
                $stmt = $conn->prepare("UPDATE users SET profile_image = NULL WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
            }
            
        } elseif ($image_type === 'store') {
            // Get restaurant ID
            $getUserData = $conn->prepare("SELECT branch_id, store_name FROM users WHERE id = ?");
            $getUserData->bind_param("i", $user_id);
            $getUserData->execute();
            $userData = $getUserData->get_result()->fetch_assoc();
            $restaurant_id = $userData['branch_id'];
            
            if ($restaurant_id) {
                // Set store image URL to NULL (no actual file deletion needed)
                $stmt = $conn->prepare("UPDATE restaurants SET restaurant_image_url = NULL WHERE id = ?");
                $stmt->bind_param("i", $restaurant_id);
                $stmt->execute();
            }
        }
        
        $response['success'] = true;
        $response['message'] = ucfirst($image_type) . " image removed successfully!";
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// ✅ 7. Update super admin session activity (SAFE VERSION)
if (isset($_SESSION['branch_admin_id'])) {
    $userId = intval($_SESSION['branch_admin_id']);
    
    // Verify user exists and is super_admin
    $userCheck = $conn->prepare("SELECT id, name, role FROM users WHERE id = ? AND role = 'super_admin'");
    $userCheck->bind_param("i", $userId);
    $userCheck->execute();
    $userResult = $userCheck->get_result();
    
    if ($userResult->num_rows > 0) {
        $userData = $userResult->fetch_assoc();
        
        // Update admin name variables
        $adminName = $userData['name'];
        $firstLetter = !empty($adminName) ? strtoupper(substr($adminName, 0, 1)) : "S";
        
        // Update session name if not set
        if (!isset($_SESSION['name'])) {
            $_SESSION['name'] = $userData['name'];
        }
        
        // Update user session
        try {
            // Check if session already exists
            $sessionCheck = $conn->prepare("SELECT id FROM user_sessions WHERE user_id = ?");
            $sessionCheck->bind_param("i", $userId);
            $sessionCheck->execute();
            $sessionExists = $sessionCheck->get_result()->num_rows > 0;
            
            if ($sessionExists) {
                // Update existing session
                $updateStmt = $conn->prepare("
                    UPDATE user_sessions 
                    SET last_activity = NOW(), is_active = 1 
                    WHERE user_id = ?
                ");
                $updateStmt->bind_param("i", $userId);
                $updateStmt->execute();
            } else {
                // Create new session only if user exists
                $insertStmt = $conn->prepare("
                    INSERT INTO user_sessions (user_id, last_activity, is_active, ip_address)
                    VALUES (?, NOW(), 1, ?)
                ");
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $insertStmt->bind_param("is", $userId, $ipAddress);
                $insertStmt->execute();
            }
        } catch (Exception $e) {
            error_log("Session management error: " . $e->getMessage());
        }
    } else {
        // Invalid user - clear session
        error_log("Invalid super_admin session: User $userId not found or wrong role");
        session_destroy();
        header("Location: Studlogin.php");
        exit();
    }
}

// ✅ 8. Handle alert messages (AFTER session management)
if (isset($_SESSION['alert_message'])) {
    $alertType = isset($_SESSION['alert_type']) ? $_SESSION['alert_type'] : 'error';
    $alertMessage = '<div class="alert ' . htmlspecialchars($alertType) . '" style="display:block;">' . 
                    htmlspecialchars($_SESSION['alert_message']) . '</div>';
    unset($_SESSION['alert_message']);
    unset($_SESSION['alert_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FoodHub Super Admin Dashboard</title>
<style>
  /* === Reset & Global === */
  * {margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",sans-serif;}
  body {background:#f8f9fb; color:#111; transition:margin-left 0.3s ease;}
  /* === Topbar === */
  .topbar {
    background:#002147;
    color:white;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:10px 25px;
    position:fixed; top:0; left:0; right:0;
    z-index:1001; height:60px;
    box-shadow:0 2px 6px rgba(0,0,0,0.15);
  }
  .topbar-left {
    display:flex; align-items:center; gap:15px;
  }
  .topbar-left img {height:45px; width:auto;}
  .hamburger {
    font-size:22px; background:none; border:none;
    color:#ffcc00; cursor:pointer;
  }
  .topbar-right {
    display:flex; align-items:center; gap:10px; position:relative;
  }
  .profile-pic {
    width:35px; height:35px; border-radius:50%;
    background:#ffcc00; color:#002147;
    display:flex; align-items:center; justify-content:center;
    font-weight:bold; cursor:pointer;
  }
  .profile-name {margin-left:5px; font-weight:bold; color:#fff;}
  .profile-dropdown {
    position:absolute; top:60px; right:0;
    background:#fff; color:#002147;
    border:1px solid #ddd; border-radius:8px;
    box-shadow:0 3px 8px rgba(0,0,0,0.2);
    display:none; flex-direction:column;
    min-width:150px; z-index:2000;
  }
  .profile-dropdown a {
    padding:10px 15px; text-decoration:none; color:#002147;
  }
  .profile-dropdown a:hover {
    background:#ffcc00; color:#002147;
  }
  /* === Sidebar === */
  .sidebar {
    width:240px; background:#002147;
    padding-top:60px; color:#fff;
    position:fixed; top:60px; left:0; height:100vh;
    overflow-y:auto; transition:transform 0.3s ease;
  }
  .sidebar.collapsed {transform:translateX(-240px);}
  .sidebar nav a {
    display:block; padding:12px 10px; color:#fff; text-decoration:none;
    margin-bottom:10px; border-radius:6px; transition:0.3s;
  }
  .sidebar nav a:hover, .sidebar nav a.active {
    background:#ffcc00; color:#002147; font-weight:bold;
  }
  /* === Main Content === */
  .main {
    margin-left:240px; margin-top:60px; padding:20px;
    transition:margin-left 0.3s ease;
  }
  .main.expanded {margin-left:0;}
  .cards {display:flex; gap:20px; flex-wrap:wrap;}
  .card {
    flex:1; min-width:220px; background:#fff;
    border-radius:12px; padding:20px;
    border:1px solid #ddd; box-shadow:0 3px 6px rgba(0,0,0,0.05);
  }
  .card h3 {font-size:14px; color:#555; margin-bottom:10px;}
  .card p {font-size:20px; font-weight:bold; color:#002147;}
  .table-container {
    margin-top:30px; background:#fff; border-radius:12px;
    padding:20px; border:1px solid #ddd;
  }
  .table-container h3 {color:#002147; margin-bottom:10px;}
  table {width:100%; border-collapse:collapse;}
  th, td {padding:12px; border-bottom:1px solid #eee; text-align:left;}
  .status-delivered {color:green; font-weight:bold;}
  .status-pending {color:#ff9900; font-weight:bold;}
  .actions {margin-top:15px;}
  .btn {
    background:#002147; color:#fff;
    padding:10px 16px; border-radius:6px; text-decoration:none;
  }
  .btn:hover {background:#ffcc00; color:#002147;}
  .page-wrapper {padding-top:20px;}
  
  /* === Alert Messages === */
  .alert {
    padding:15px; margin:10px 0; border-radius:8px;
    display:none; animation:fadeIn 0.3s;
  }
  .alert.success {background:#d4edda; color:#155724; border:1px solid #c3e6cb;}
  .alert.error {background:#f8d7da; color:#721c24; border:1px solid #f5c6cb;}
  @keyframes fadeIn {from{opacity:0;} to{opacity:1;}}
  
  /* === Responsive === */
  @media(max-width:768px){
    .main {margin-left:0;}
    .cards{flex-direction:column;}
    .sidebar {transform:translateX(-240px);}
    .sidebar.collapsed {transform:translateX(-240px);}
  }
</style>
</head>
<body>
<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="menuBtn">☰</button>
    <img src="FoodHub_Title.png" alt="FoodHub Logo">
  </div>
  <div class="topbar-right">
    <div class="profile-pic" id="profileToggle"><?php echo htmlspecialchars($firstLetter); ?></div>
    <span class="profile-name"><?php echo htmlspecialchars($adminName); ?></span>
    <div class="profile-dropdown" id="profileDropdown">
     <a href="../users/logout.php">🚪 Sign Out</a>
    </div>
  </div>
</div>
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <nav>
    <a href="dashboard.php" class="nav-link active">📊 Dashboard</a>
    <a href="usersuperad.php" class="nav-link">👥 Users</a>
    <a href="restarantsuperadmin.php" class="nav-link">🍽 Restaurants</a>
    <a href="analytics.php" class="nav-link">📈 Analytics</a>
    <a href="settings.php" class="nav-link">⚙️ Settings</a>
  </nav>
</aside>
<!-- MAIN CONTENT -->
<main class="main" id="mainContent">
  <?php echo $alertMessage; ?>
  <?php include 'dashboard.php'; ?>
</main>
<script>
// Profile dropdown toggle
const profileToggle = document.getElementById('profileToggle');
const profileDropdown = document.getElementById('profileDropdown');
profileToggle.addEventListener('click', (e) => {
  e.stopPropagation();
  profileDropdown.style.display =
    profileDropdown.style.display === 'flex' ? 'none' : 'flex';
});
document.addEventListener('click', (e) => {
  if (!profileDropdown.contains(e.target)) {
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
// Sidebar links -> AJAX content load
const links = document.querySelectorAll('.sidebar nav a');
links.forEach(link => {
  link.addEventListener('click', e => {
    e.preventDefault();
    links.forEach(l => l.classList.remove('active'));
    link.classList.add('active');
    const url = link.getAttribute('href');
    mainContent.innerHTML = "<p>Loading...</p>";
    fetch(url)
      .then(res => {
        if (!res.ok) throw new Error('Page not found');
        return res.text();
      })
      .then(data => {
        mainContent.innerHTML = `<div class="page-wrapper">${data}</div>`;
        mainContent.scrollTop = 0;
      })
      .catch(() => {
        mainContent.innerHTML =
          "<p style='color:red'>⚠️ Failed to load content.</p>";
      });
  });
});
// Utility function to show alerts
function showAlert(message, type) {
  const alertDiv = document.createElement('div');
  alertDiv.className = `alert ${type}`;
  alertDiv.textContent = message;
  alertDiv.style.display = 'block';
  mainContent.insertBefore(alertDiv, mainContent.firstChild);
  setTimeout(() => alertDiv.remove(), 5000);
}
// Function to remove images
function removeImage(userId, imageType) {
    if (confirm(`Are you sure you want to remove this ${imageType} image?`)) {
        const formData = new FormData();
        formData.append('action', 'remove_image');
        formData.append('user_id', userId);
        formData.append('image_type', imageType);
        
        fetch('Super_Admin.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                // Reload the page to reflect changes
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlert(data.message, 'error');
            }
        })
        .catch(error => {
            showAlert('Error removing image: ' + error, 'error');
        });
    }
}
</script>
</body>
</html>