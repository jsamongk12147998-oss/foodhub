<?php
session_start();

// Include database connection - updated path to match your structure
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

// Check if user is logged in and has 'user' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') { 
    header("Location: ../login/Studlogin.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];
$success = $error = '';

// Get user data
$user_sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    // If user not found in database, log them out
    session_destroy();
    header("Location: ../login/Studlogin.php");
    exit();
}

$stmt->close();

// Get first letter of name for profile picture
$first_letter = strtoupper(substr($user['name'], 0, 1));

// Function to format store name for URLs
function formatStoreName($storeName) {
    return preg_replace('/[^a-zA-Z0-9]/', '_', $storeName);
}

// Function to get proper image URL
function getProductImageUrl($productImage, $storeName) {
    if (empty($productImage)) {
        return null;
    }
    
    // If it's already a full path, return as is
    if (strpos($productImage, 'uploads/') === 0) {
        return '../' . $productImage;
    }
    
    // Otherwise construct the path
    $formattedStoreName = formatStoreName($storeName);
    return '../uploads/menus/' . $formattedStoreName . '/' . $productImage;
}

// Function to get search suggestions
function getSearchSuggestions($conn, $query) {
    $suggestions = [];
    
    if (strlen($query) < 2) {
        return $suggestions;
    }
    
    // Search products
    $products_query = "SELECT p.name, p.category, r.name as restaurant_name 
                      FROM products p 
                      JOIN restaurants r ON p.restaurant_id = r.id 
                      WHERE p.is_available = 1 
                      AND (p.name LIKE ? OR p.category LIKE ?)
                      LIMIT 5";
    $products_stmt = $conn->prepare($products_query);
    $search_param = "%$query%";
    $products_stmt->bind_param("ss", $search_param, $search_param);
    $products_stmt->execute();
    $products_result = $products_stmt->get_result();
    
    while ($row = $products_result->fetch_assoc()) {
        $suggestions[] = [
            'type' => 'food',
            'name' => $row['name'],
            'category' => $row['category'],
            'restaurant' => $row['restaurant_name']
        ];
    }
    $products_stmt->close();
    
    // Search stores
    $stores_query = "SELECT name, category FROM restaurants 
                    WHERE is_active = 1 
                    AND (name LIKE ? OR category LIKE ?)
                    LIMIT 5";
    $stores_stmt = $conn->prepare($stores_query);
    $stores_stmt->bind_param("ss", $search_param, $search_param);
    $stores_stmt->execute();
    $stores_result = $stores_stmt->get_result();
    
    while ($row = $stores_result->fetch_assoc()) {
        $suggestions[] = [
            'type' => 'store',
            'name' => $row['name'],
            'category' => $row['category']
        ];
    }
    $stores_stmt->close();
    
    return $suggestions;
}

// Handle search functionality - REDIRECT TO SEARCH.PHP
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $search_query = trim($_GET['search']);
    $search_category = $_GET['category'] ?? 'all';
    
    if (!empty($search_query)) {
        // Redirect to search.php with search parameters
        $redirect_url = "search.php?q=" . urlencode($search_query) . "&type=" . urlencode($search_category);
        header("Location: " . $redirect_url);
        exit();
    }
}

// Fetch cart items for the current user - FIXED: Using correct column names
$cart_items = [];
$cart_total = 0;
$cart_count = 0;
$cart_item_count = 0; // Count distinct items

// FIXED: Removed image reference entirely since it doesn't exist
$cart_query = "SELECT c.*, r.name as restaurant_name 
               FROM cart c 
               JOIN products p ON c.product_id = p.id 
               JOIN restaurants r ON p.restaurant_id = r.id 
               WHERE c.user_id = ?";
$stmt = $conn->prepare($cart_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$cart_result = $stmt->get_result();

if ($cart_result) {
    while ($row = $cart_result->fetch_assoc()) {
        $cart_items[] = $row;
        $cart_total += $row['price'] * $row['quantity'];
        $cart_count += $row['quantity'];
        $cart_item_count++; // Count each distinct item
    }
}
$stmt->close();

// Handle AJAX requests for cart operations and search suggestions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];
    
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'User not logged in';
        echo json_encode($response);
        exit();
    }
    
    switch ($_POST['action']) {
        case 'add_to_cart':
            if (isset($_POST['product_id'], $_POST['quantity'])) {
                $product_id = intval($_POST['product_id']);
                $quantity = intval($_POST['quantity']);
                
                // Get product details
                $product_query = "SELECT p.*, r.id as restaurant_id FROM products p JOIN restaurants r ON p.restaurant_id = r.id WHERE p.id = ?";
                $stmt = $conn->prepare($product_query);
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $product_result = $stmt->get_result();
                
                if ($product_result->num_rows > 0) {
                    $product = $product_result->fetch_assoc();
                    
                    // Check if item already exists in cart
                    $check_query = "SELECT * FROM cart WHERE user_id = ? AND product_id = ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("ii", $user_id, $product_id);
                    $check_stmt->execute();
                    $existing_item = $check_stmt->get_result();
                    
                    if ($existing_item->num_rows > 0) {
                        // Update quantity
                        $update_query = "UPDATE cart SET quantity = quantity + ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND product_id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->bind_param("iii", $quantity, $user_id, $product_id);
                        $update_stmt->execute();
                        $response['success'] = $update_stmt->affected_rows > 0;
                        $update_stmt->close();
                    } else {
                        // Insert new item
                        $insert_query = "INSERT INTO cart (user_id, product_id, product_name, price, quantity, restaurant_id) VALUES (?, ?, ?, ?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_query);
                        $insert_stmt->bind_param("iisdii", $user_id, $product_id, $product['name'], $product['price'], $quantity, $product['restaurant_id']);
                        $insert_stmt->execute();
                        $response['success'] = $insert_stmt->affected_rows > 0;
                        $insert_stmt->close();
                    }
                    
                    $check_stmt->close();
                    $response['message'] = $response['success'] ? 'Item added to cart' : 'Failed to add item to cart';
                    
                    // Return updated cart data
                    if ($response['success']) {
                        $response['cart_data'] = getCartData($conn, $user_id);
                    }
                } else {
                    $response['message'] = 'Product not found';
                }
                $stmt->close();
            }
            break;
            
        case 'update_cart_quantity':
            if (isset($_POST['cart_id'], $_POST['quantity'])) {
                $cart_id = intval($_POST['cart_id']);
                $quantity = intval($_POST['quantity']);
                
                if ($quantity <= 0) {
                    // Remove item if quantity is 0 or less
                    $delete_query = "DELETE FROM cart WHERE id = ? AND user_id = ?";
                    $delete_stmt = $conn->prepare($delete_query);
                    $delete_stmt->bind_param("ii", $cart_id, $user_id);
                    $delete_stmt->execute();
                    $response['success'] = $delete_stmt->affected_rows > 0;
                    $delete_stmt->close();
                    $response['message'] = $response['success'] ? 'Item removed from cart' : 'Failed to remove item';
                } else {
                    // Update quantity
                    $update_query = "UPDATE cart SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("iii", $quantity, $cart_id, $user_id);
                    $update_stmt->execute();
                    $response['success'] = $update_stmt->affected_rows > 0;
                    $update_stmt->close();
                    $response['message'] = $response['success'] ? 'Cart updated' : 'Failed to update cart';
                }
                
                // Return updated cart data
                if ($response['success']) {
                    $response['cart_data'] = getCartData($conn, $user_id);
                }
            }
            break;
            
        case 'remove_from_cart':
            if (isset($_POST['cart_id'])) {
                $cart_id = intval($_POST['cart_id']);
                $delete_query = "DELETE FROM cart WHERE id = ? AND user_id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("ii", $cart_id, $user_id);
                $delete_stmt->execute();
                $response['success'] = $delete_stmt->affected_rows > 0;
                $delete_stmt->close();
                $response['message'] = $response['success'] ? 'Item removed from cart' : 'Failed to remove item';
                
                // Return updated cart data
                if ($response['success']) {
                    $response['cart_data'] = getCartData($conn, $user_id);
                }
            }
            break;
            
        case 'get_cart_count':
            // Count distinct items (not quantities)
            $count_query = "SELECT COUNT(*) as item_count FROM cart WHERE user_id = ?";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->bind_param("i", $user_id);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count_data = $count_result->fetch_assoc();
            $response['success'] = true;
            $response['count'] = $count_data['item_count'] ?? 0;
            $count_stmt->close();
            break;
            
        case 'get_cart_data':
            $response['success'] = true;
            $response['cart_data'] = getCartData($conn, $user_id);
            break;

        case 'search_suggestions':
            if (isset($_POST['query'])) {
                $query = $conn->real_escape_string($_POST['query']);
                $response['success'] = true;
                $response['suggestions'] = getSearchSuggestions($conn, $query);
            }
            break;
    }
    
    echo json_encode($response);
    exit();
}

// Function to get cart data - FIXED: Removed image reference
function getCartData($conn, $user_id) {
    $cart_data = [
        'items' => [],
        'total' => 0,
        'count' => 0,
        'item_count' => 0 // Count distinct items
    ];
    
    // FIXED: Removed image reference since it doesn't exist in products table
    $cart_query = "SELECT c.*, r.name as restaurant_name 
                   FROM cart c 
                   JOIN products p ON c.product_id = p.id 
                   JOIN restaurants r ON p.restaurant_id = r.id 
                   WHERE c.user_id = ?";
    $stmt = $conn->prepare($cart_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $cart_result = $stmt->get_result();
    
    if ($cart_result) {
        while ($row = $cart_result->fetch_assoc()) {
            $cart_data['items'][] = $row;
            $cart_data['total'] += $row['price'] * $row['quantity'];
            $cart_data['count'] += $row['quantity'];
            $cart_data['item_count']++; // Count distinct items
        }
    }
    $stmt->close();
    
    return $cart_data;
}

// Handle profile photo removal
if (isset($_GET['remove_photo'])) {
    if (!empty($user['profile_image'])) {
        // Delete the profile image file
        if (file_exists(__DIR__ . '/' . $user['profile_image'])) {
            unlink(__DIR__ . '/' . $user['profile_image']);
        }
        
        // Update database to remove profile image
        $update_sql = "UPDATE users SET profile_image = NULL WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $success = "Profile picture removed successfully!";
            // Refresh user data
            $user_sql = "SELECT * FROM users WHERE id = ?";
            $stmt = $conn->prepare($user_sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $first_letter = strtoupper(substr($user['name'], 0, 1));
        } else {
            $error = "Error removing profile picture!";
        }
        $stmt->close();
    }
}

// Update profile
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        // Check if email already exists (excluding current user)
        $check_sql = "SELECT id FROM users WHERE email = ? AND id != ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("si", $email, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Email already exists!";
        } else {
            // Handle profile picture upload
            $profile_image = $user['profile_image'];
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['profile_picture']['type'];
                
                if (in_array($file_type, $allowed_types)) {
                    $upload_dir = __DIR__ . '/uploads/profiles/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                    $filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $filepath)) {
                        // Delete old profile picture if exists
                        if ($profile_image && file_exists(__DIR__ . '/' . $profile_image)) {
                            unlink(__DIR__ . '/' . $profile_image);
                        }
                        $profile_image = 'uploads/profiles/' . $filename;
                    }
                }
            }
            
            // Update user data
            $update_sql = "UPDATE users SET name = ?, email = ?, phone = ?, address = ?, profile_image = ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("sssssi", $name, $email, $phone, $address, $profile_image, $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['name'] = $name;
                $success = "Profile updated successfully!";
                // Refresh user data and first letter
                $user_sql = "SELECT * FROM users WHERE id = ?";
                $stmt = $conn->prepare($user_sql);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $first_letter = strtoupper(substr($user['name'], 0, 1));
            } else {
                $error = "Error updating profile: " . $stmt->error;
            }
        }
        $stmt->close();
    }
    
    // Update password
    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 6) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_sql = "UPDATE users SET password = ? WHERE id = ?";
                    $stmt = $conn->prepare($update_sql);
                    $stmt->bind_param("si", $hashed_password, $user_id);
                    
                    if ($stmt->execute()) {
                        $success = "Password updated successfully!";
                    } else {
                        $error = "Error updating password!";
                    }
                    $stmt->close();
                } else {
                    $error = "Password must be at least 6 characters long!";
                }
            } else {
                $error = "New passwords do not match!";
            }
        } else {
            $error = "Current password is incorrect!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - UMAK Foodhub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        body {
            box-sizing: border-box;
        }
        
        .sidebar-transition {
            transition: transform 0.3s ease-in-out;
        }
        
        .cart-transition {
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
        }
        
        .overlay {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
        }
        
        .umak-blue {
            background-color: #1e3a8a;
        }
        
        .umak-yellow {
            background-color: #fbbf24;
        }
        
        .umak-blue-text {
            color: #1e3a8a;
        }
        
        .umak-yellow-text {
            color: #fbbf24;
        }
        
        .umak-blue-border {
            border-color: #1e3a8a;
        }
        
        .umak-yellow-border {
            border-color: #fbbf24;
        }
        /* Notification Styles */
        .notification {
            position: fixed;
            top: 100px;
            right: 20px;
            background: #4CAF50;
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        }
        .notification.error {
            background: #f44336;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }
        /* Cart Badge */
        .cart-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #fbbf24;
            color: #1e3a8a;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            font-weight: bold;
            display: none;
            align-items: center;
            justify-content: center;
        }
        /* Profile Styles */
        .profile-picture {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #1e3a8a;
            margin-right: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: bold;
            color: #1e3a8a;
            background: linear-gradient(135deg, #ffcc00, #ffd700);
        }
        .profile-picture.has-image {
            background: none;
            color: inherit;
        }
        .profile-picture.has-image .initial {
            display: none;
        }
        .profile-picture img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        .info-section, .password-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .section-title {
            color: #1e3a8a;
            margin-bottom: 25px;
            font-size: 22px;
            border-bottom: 2px solid #fbbf24;
            padding-bottom: 10px;
        }
        .info-grid {
            display: grid;
            gap: 20px;
        }
        .info-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }
        .info-icon {
            width: 40px;
            height: 40px;
            background: #1e3a8a;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
        }
        .info-content h3 {
            color: #1e3a8a;
            margin-bottom: 5px;
            font-size: 16px;
        }
        .info-content p {
            color: #666;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #1e3a8a;
            font-weight: 600;
        }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #1e3a8a;
        }
        .form-group textarea {
            height: 100px;
            resize: vertical;
        }
        .btn {
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s;
            margin-right: 10px;
        }
        .btn:hover {
            background: #fbbf24;
            color: #1e3a8a;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .success { 
            background: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        .error { 
            background: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb;
        }
        .file-upload {
            position: relative;
            display: inline-block;
            margin-bottom: 20px;
        }
        .file-upload input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .file-upload-label {
            display: inline-block;
            padding: 10px 20px;
            background: #1e3a8a;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .file-upload-label:hover {
            background: #fbbf24;
            color: #1e3a8a;
        }
        .password-rules {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .remove-photo {
            display: inline-block;
            margin-left: 10px;
            color: #ff4444;
            text-decoration: none;
            font-size: 14px;
        }
        .remove-photo:hover {
            text-decoration: underline;
        }
        .current-photo {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        .current-photo img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #1e3a8a;
            margin-right: 15px;
        }
        /* Search Suggestions Dropdown */
        .search-suggestions-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        .search-suggestion-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }
        .search-suggestion-item:hover {
            background-color: #f9fafb;
        }
        .search-suggestion-item:last-child {
            border-bottom: none;
        }
        .suggestion-type {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .suggestion-name {
            font-weight: 500;
            color: #1f2937;
            margin-bottom: 2px;
        }
        .suggestion-details {
            font-size: 0.875rem;
            color: #6b7280;
        }
        .search-container {
            position: relative;
        }
        .search-container:focus-within .search-suggestions-dropdown {
            display: block;
        }
        .search-dropdown {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        .search-input {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            border-left: none;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-full">
<!-- Overlay -->
<div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden overlay"></div>

<!-- Top Navigation -->
<nav class="umak-blue shadow-lg fixed top-0 left-0 right-0 z-30">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <!-- Left: Logo and Hamburger -->
            <div class="flex items-center space-x-4">
                <button id="hamburger" class="p-2 rounded-md text-white hover:text-yellow-300 hover:bg-blue-800 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <div class="flex items-center space-x-2">
                    <div class="w-20 h-15 rounded-lg overflow-hidden">
                        <img src="FoodHub_notext.png" alt="FoodHub Logo" class="w-full h-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="w-8 h-8 umak-yellow rounded-lg flex items-center justify-center" style="display: none;">
                            <span class="umak-blue-text font-bold text-sm">F</span>
                        </div>
                    </div>
                    <div class="flex items-center">
                        <span class="text-xl font-bold text-white">UMAK</span>
                        <span class="text-xl font-bold umak-yellow-text ml-1">Foodhub</span>
                    </div>
                </div>
            </div>

            <!-- Center: Search Bar - UPDATED TO REDIRECT TO SEARCH.PHP WITH SUGGESTIONS -->
            <div class="hidden md:flex items-center flex-1 max-w-2xl mx-8">
                <div class="search-container w-full">
                    <form method="GET" action="search.php" class="flex items-center w-full bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                        <select name="type" class="px-4 py-3 bg-white border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer">
                            <option value="all">All</option>
                            <option value="foods">Foods</option>
                            <option value="shops">Stores</option>
                        </select>
                        <div class="w-px h-6 bg-gray-300"></div>
                        <input type="text" name="q" placeholder="What are you craving today?" 
                            class="flex-1 px-4 py-3 border-none focus:outline-none focus:ring-0 text-sm" id="search-input" autocomplete="off">
                        <button type="submit" class="px-6 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </button>
                    </form>
                    <div class="search-suggestions-dropdown" id="search-suggestions"></div>
                </div>
            </div>

            <!-- Right: Cart and Profile -->
            <div class="flex items-center space-x-4">
                <button id="cart-btn" class="relative p-2 text-white hover:text-yellow-300 hover:bg-blue-800 rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0V19a2 2 0 002 2h7a2 2 0 002-2v-4"></path>
                    </svg>
                    <span class="cart-badge" style="<?php echo $cart_item_count > 0 ? 'display: flex;' : ''; ?>"><?php echo $cart_item_count; ?></span>
                </button>
                <div class="flex items-center space-x-2">
                    <?php if (!empty($user['profile_image'])): ?>
                        <div class="w-8 h-8 rounded-full overflow-hidden">
                            <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile" class="w-full h-full object-cover">
                        </div>
                    <?php else: ?>
                        <div class="w-8 h-8 umak-yellow rounded-full flex items-center justify-center">
                            <span class="umak-blue-text font-bold text-sm"><?php echo $first_letter; ?></span>
                        </div>
                    <?php endif; ?>
                    <span id="user-name" class="hidden sm:block text-sm font-medium text-white"><?php echo htmlspecialchars($user['name']); ?></span>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Sidebar -->
<aside id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-white shadow-lg z-50 transform -translate-x-full sidebar-transition">
    <div class="p-4 border-b border-gray-200">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <div class="w-15 h-12 rounded-lg overflow-hidden">
                    <img src="FoodHub_notext.png" alt="FoodHub Logo" class="w-full h-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="w-8 h-8 umak-yellow rounded-lg flex items-center justify-center" style="display: none;">
                        <span class="umak-blue-text font-bold text-sm">F</span>
                    </div>
                </div>
                <div class="flex items-center">
                    <span class="text-xl font-bold umak-blue-text">UMAK</span>
                    <span class="text-xl font-bold umak-yellow-text ml-1">Foodhub</span>
                </div>
            </div>
            <button id="close-sidebar" class="p-1 text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
    <nav class="p-4">
        <ul class="space-y-2">
            <li><a href="UserInt.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="currentColor" viewbox="0 0 20 20">
                    <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                </svg>
                <span>Home</span>
            </a></li>
            <li><a href="browse_menu.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z"></path>
                </svg>
                <span>Browse Menu</span>
            </a></li>
            <li><a href="favorites.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                </svg>
                <span>Favorites</span>
            </a></li>
            <li><a href="my_orders.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                </svg>
                <span>My Orders</span>
            </a></li>
            <li><a href="profile.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg bg-yellow-50 umak-blue-text font-medium border-l-4 umak-yellow-border">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                <span>Profile</span>
            </a></li>
            <li><a href="../users/logout.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="red" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span>Logout</span>
            </a></li>
        </ul>
    </nav>
</aside>

<!-- Cart Sidebar -->
<div id="cart-sidebar" class="fixed right-0 top-0 h-full w-80 bg-white shadow-lg z-50 transform translate-x-full cart-transition flex flex-col">
    <div class="p-4 border-b border-gray-200 flex-shrink-0">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Your Cart</h2>
            <button id="close-cart" class="p-1 text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- Scrollable cart items area -->
    <div class="flex-1 overflow-y-auto">
        <div id="cart-empty" class="text-center py-12 <?php echo !empty($cart_items) ? 'hidden' : ''; ?>">
            <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0V19a2 2 0 002 2h7a2 2 0 002-2v-4"></path>
            </svg>
            <p class="text-gray-500 mb-2">Your cart is empty</p>
            <p class="text-sm text-gray-400">Add some delicious items to get started!</p>
        </div>
        <div id="cart-items-container" class="p-4 <?php echo empty($cart_items) ? 'hidden' : ''; ?>">
            <?php foreach ($cart_items as $item): ?>
            <div class="cart-item border-b border-gray-200 pb-4 mb-4" data-cart-id="<?php echo $item['id']; ?>">
                <div class="flex items-center space-x-3">
                    <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center">
                        <!-- FIXED: No image display since image column doesn't exist -->
                        <span class="text-gray-400 text-2xl">🍽️</span>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($item['product_name']); ?></h4>
                        <p class="text-sm text-gray-600">₱<?php echo number_format($item['price'], 2); ?></p>
                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($item['restaurant_name']); ?></p>
                        <div class="flex items-center justify-between mt-2">
                            <div class="flex items-center space-x-2">
                                <button onclick="updateCartQuantity(<?php echo $item['id']; ?>, -1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">-</button>
                                <span class="font-medium cart-quantity"><?php echo $item['quantity']; ?></span>
                                <button onclick="updateCartQuantity(<?php echo $item['id']; ?>, 1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">+</button>
                            </div>
                            <button onclick="removeFromCart(<?php echo $item['id']; ?>)" class="text-red-500 hover:text-red-700 text-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Fixed footer that stays at bottom -->
    <div class="border-t border-gray-200 p-4 bg-white flex-shrink-0">
        <div class="flex justify-between items-center mb-4">
            <span class="font-semibold text-gray-900">Total:</span>
            <span id="cart-total" class="font-bold text-lg umak-blue-text">₱<?php echo number_format($cart_total, 2); ?></span>
        </div>
        <a href="checkout.php" id="proceed-to-checkout" class="w-full umak-yellow umak-blue-text py-3 rounded-lg font-semibold hover:bg-yellow-300 transition-all duration-300 text-center block <?php echo empty($cart_items) ? 'opacity-50 pointer-events-none' : ''; ?>">
            Proceed to Checkout
        </a>
    </div>
</div>

<!-- Main Content -->
<main class="pt-16">
    <div class="p-6">
        <!-- Mobile Search - UPDATED TO REDIRECT TO SEARCH.PHP WITH SUGGESTIONS -->
        <div class="md:hidden mb-6">
            <div class="search-container">
                <form method="GET" action="search.php" class="flex items-center bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                    <select name="type" class="px-3 py-3 bg-white border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer flex-shrink-0">
                        <option value="all">All</option>
                        <option value="foods">Foods</option>
                        <option value="shops">Stores</option>
                    </select>
                    <div class="w-px h-6 bg-gray-300 flex-shrink-0"></div>
                    <input type="text" name="q" placeholder="Search food or stores..." 
                        class="flex-1 px-3 py-3 border-none focus:outline-none focus:ring-0 text-sm" id="mobile-search-input">
                    <button type="submit" class="px-4 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </form>
                <div class="search-suggestions-dropdown" id="mobile-search-suggestions"></div>
            </div>
        </div>

        <!-- Personalized Greeting Section -->
        <section class="mb-8">
            <div class="umak-blue rounded-2xl p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold mb-2">Hi, <span id="greeting-name"><?php echo htmlspecialchars($user['name']); ?></span>! 👋</h1>
                        <p class="text-lg opacity-90">Manage your profile and account settings</p>
                    </div>
                    <div class="hidden md:block">
                        <?php if (!empty($user['profile_image'])): ?>
                            <div class="w-16 h-16 rounded-full overflow-hidden border-4 border-yellow-400">
                                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile" class="w-full h-full object-cover">
                            </div>
                        <?php else: ?>
                            <div class="w-16 h-16 umak-yellow rounded-full flex items-center justify-center border-4 border-yellow-400">
                                <span class="umak-blue-text font-bold text-2xl"><?php echo $first_letter; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
        <!-- Profile Content -->
        <section class="mb-8">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900">My Profile</h2>
                    <p class="text-gray-600 mt-1">Update your personal information and password</p>
                </div>
            </div>
            
            <?php if ($success): ?>
                <div class="message success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error"><?php echo $error; ?></div>
            <?php endif; ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Information Section -->
                <div class="info-section">
                    <h2 class="section-title">Information</h2>
                    
                    <!-- Current Profile Picture Display -->
                    <?php if (!empty($user['profile_image'])): ?>
                        <div class="current-photo">
                            <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Current Profile Picture">
                            <div>
                                <p class="font-medium">Current Profile Picture</p>
                                <a href="?remove_photo=1" class="remove-photo" onclick="return confirm('Are you sure you want to remove your profile picture?')">
                                    Remove Photo
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <div class="file-upload">
                            <label class="file-upload-label">
                                <i class="fas fa-camera mr-2"></i>Change Profile Picture
                                <input type="file" name="profile_picture" accept="image/*">
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label for="name">Full Name</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="Enter your phone number">
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea name="address" placeholder="Enter your address"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                        </div>
                        
                        <button type="submit" name="update_profile" class="btn">
                            <i class="fas fa-save mr-2"></i>Update Profile
                        </button>
                    </form>
                </div>
                <!-- Password Section -->
                <div class="password-section">
                    <h2 class="section-title">Update Password</h2>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" name="current_password" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" name="new_password" required>
                            <div class="password-rules">Password must be at least 6 characters long</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                        
                        <button type="submit" name="update_password" class="btn">
                            <i class="fas fa-key mr-2"></i>Update Password
                        </button>
                    </form>
                    <!-- Additional Info Display -->
                    <div class="info-grid" style="margin-top: 30px;">
                        <div class="info-item">
                            <div class="info-icon">📧</div>
                            <div class="info-content">
                                <h3>Email</h3>
                                <p><?php echo htmlspecialchars($user['email']); ?></p>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">👤</div>
                            <div class="info-content">
                                <h3>Role</h3>
                                <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role']))); ?></p>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-icon">📅</div>
                            <div class="info-content">
                                <h3>Member Since</h3>
                                <p><?php echo date('F d, Y', strtotime($user['created_at'])); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>

<script>
// UI Elements
const hamburger = document.getElementById('hamburger');
const sidebar = document.getElementById('sidebar');
const closeSidebar = document.getElementById('close-sidebar');
const cartBtn = document.getElementById('cart-btn');
const cartSidebar = document.getElementById('cart-sidebar');
const closeCart = document.getElementById('close-cart');
const overlay = document.getElementById('overlay');

// Search elements
const searchInput = document.getElementById('search-input');
const mobileSearchInput = document.getElementById('mobile-search-input');
const searchSuggestions = document.getElementById('search-suggestions');
const mobileSearchSuggestions = document.getElementById('mobile-search-suggestions');

// Sidebar functionality
hamburger.addEventListener('click', () => {
    sidebar.classList.remove('-translate-x-full');
    overlay.classList.remove('hidden');
});

closeSidebar.addEventListener('click', () => {
    sidebar.classList.add('-translate-x-full');
    overlay.classList.add('hidden');
});

// Cart functionality
cartBtn.addEventListener('click', () => {
    cartSidebar.classList.remove('translate-x-full');
    overlay.classList.remove('hidden');
});

closeCart.addEventListener('click', () => {
    cartSidebar.classList.add('translate-x-full');
    overlay.classList.add('hidden');
});

// Overlay click to close
overlay.addEventListener('click', () => {
    sidebar.classList.add('-translate-x-full');
    cartSidebar.classList.add('translate-x-full');
    overlay.classList.add('hidden');
});

// Search suggestions functionality
function setupSearchSuggestions(inputElement, suggestionsElement) {
    let timeoutId;
    
    inputElement.addEventListener('input', function() {
        clearTimeout(timeoutId);
        const query = this.value.trim();
        
        if (query.length < 2) {
            suggestionsElement.style.display = 'none';
            return;
        }
        
        timeoutId = setTimeout(() => {
            fetchSearchSuggestions(query, suggestionsElement);
        }, 300);
    });
    
    inputElement.addEventListener('focus', function() {
        const query = this.value.trim();
        if (query.length >= 2) {
            fetchSearchSuggestions(query, suggestionsElement);
        }
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!inputElement.contains(e.target) && !suggestionsElement.contains(e.target)) {
            suggestionsElement.style.display = 'none';
        }
    });
}

function fetchSearchSuggestions(query, suggestionsElement) {
    const formData = new FormData();
    formData.append('action', 'search_suggestions');
    formData.append('query', query);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.suggestions.length > 0) {
            displaySearchSuggestions(data.suggestions, suggestionsElement);
        } else {
            suggestionsElement.style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error fetching search suggestions:', error);
        suggestionsElement.style.display = 'none';
    });
}

function displaySearchSuggestions(suggestions, suggestionsElement) {
    let html = '';
    
    suggestions.forEach(suggestion => {
        const typeIcon = suggestion.type === 'food' ? '🍽️' : '🏪';
        const typeText = suggestion.type === 'food' ? 'Food' : 'Store';
        
        html += `
            <div class="search-suggestion-item" onclick="selectSearchSuggestion('${suggestion.name}')">
                <div class="suggestion-type">${typeIcon} ${typeText}</div>
                <div class="suggestion-name">${suggestion.name}</div>
                <div class="suggestion-details">
                    ${suggestion.type === 'food' 
                        ? `${suggestion.category} • ${suggestion.restaurant}`
                        : suggestion.category
                    }
                </div>
            </div>
        `;
    });
    
    suggestionsElement.innerHTML = html;
    suggestionsElement.style.display = 'block';
}

function selectSearchSuggestion(suggestion) {
    // Set the search input value and submit the form
    if (searchInput) {
        searchInput.value = suggestion;
        searchInput.closest('form').submit();
    }
    if (mobileSearchInput) {
        mobileSearchInput.value = suggestion;
        mobileSearchInput.closest('form').submit();
    }
}

// Update cart display with new data
function updateCartDisplay(cartData) {
    const cartItemsContainer = document.getElementById('cart-items-container');
    const cartEmpty = document.getElementById('cart-empty');
    const cartTotal = document.getElementById('cart-total');
    const proceedToCheckout = document.getElementById('proceed-to-checkout');
    
    if (cartData.items.length === 0) {
        // Show empty cart message
        cartItemsContainer.classList.add('hidden');
        cartEmpty.classList.remove('hidden');
        proceedToCheckout.classList.add('opacity-50', 'pointer-events-none');
    } else {
        // Hide empty cart message and show items
        cartEmpty.classList.add('hidden');
        cartItemsContainer.classList.remove('hidden');
        proceedToCheckout.classList.remove('opacity-50', 'pointer-events-none');
        
        // Clear existing items
        cartItemsContainer.innerHTML = '';
        
        // Add new items - FIXED: No image display
        cartData.items.forEach(item => {
            const cartItemHTML = `
                <div class="cart-item border-b border-gray-200 pb-4 mb-4" data-cart-id="${item.id}">
                    <div class="flex items-center space-x-3">
                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center">
                            <span class="text-gray-400 text-2xl">🍽️</span>
                        </div>
                        <div class="flex-1">
                            <h4 class="font-semibold text-gray-900">${item.product_name}</h4>
                            <p class="text-sm text-gray-600">₱${parseFloat(item.price).toFixed(2)}</p>
                            <p class="text-xs text-gray-500">${item.restaurant_name}</p>
                            <div class="flex items-center justify-between mt-2">
                                <div class="flex items-center space-x-2">
                                    <button onclick="updateCartQuantity(${item.id}, -1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">-</button>
                                    <span class="font-medium cart-quantity">${item.quantity}</span>
                                    <button onclick="updateCartQuantity(${item.id}, 1)" class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-sm hover:bg-gray-300 transition-colors">+</button>
                                </div>
                                <button onclick="removeFromCart(${item.id})" class="text-red-500 hover:text-red-700 text-sm">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            cartItemsContainer.innerHTML += cartItemHTML;
        });
    }
    
    // Update total
    cartTotal.textContent = '₱' + parseFloat(cartData.total).toFixed(2);
}

// Update cart quantity via AJAX
function updateCartQuantity(cartId, change) {
    const cartItem = document.querySelector(`[data-cart-id="${cartId}"]`);
    const quantityElement = cartItem.querySelector('.cart-quantity');
    let currentQuantity = parseInt(quantityElement.textContent);
    let newQuantity = currentQuantity + change;
    
    if (newQuantity <= 0) {
        removeFromCart(cartId);
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_cart_quantity');
    formData.append('cart_id', cartId);
    formData.append('quantity', newQuantity);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartCount();
            if (data.cart_data) {
                updateCartDisplay(data.cart_data);
            } else {
                quantityElement.textContent = newQuantity;
                updateCartTotal();
            }
            showNotification(data.message || 'Cart updated');
        } else {
            showNotification(data.message || 'Failed to update cart', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

// Remove from cart via AJAX
function removeFromCart(cartId) {
    const formData = new FormData();
    formData.append('action', 'remove_from_cart');
    formData.append('cart_id', cartId);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Item removed from cart');
            updateCartCount();
            if (data.cart_data) {
                updateCartDisplay(data.cart_data);
            } else {
                const cartItem = document.querySelector(`[data-cart-id="${cartId}"]`);
                if (cartItem) {
                    cartItem.remove();
                }
                updateCartTotal();
                checkEmptyCart();
            }
        } else {
            showNotification(data.message || 'Failed to remove item', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

// Update cart total
function updateCartTotal() {
    let total = 0;
    const cartItems = document.querySelectorAll('.cart-item');
    
    cartItems.forEach(item => {
        const priceText = item.querySelector('.text-sm.text-gray-600').textContent;
        const price = parseFloat(priceText.replace('₱', '').replace(',', ''));
        const quantity = parseInt(item.querySelector('.cart-quantity').textContent);
        total += price * quantity;
    });
    
    document.getElementById('cart-total').textContent = '₱' + total.toFixed(2);
    
    // Update checkout button state
    const checkoutBtn = document.getElementById('proceed-to-checkout');
    if (cartItems.length === 0) {
        checkoutBtn.classList.add('opacity-50', 'pointer-events-none');
    } else {
        checkoutBtn.classList.remove('opacity-50', 'pointer-events-none');
    }
}

// Check if cart is empty and show empty state
function checkEmptyCart() {
    const cartItems = document.querySelectorAll('.cart-item');
    const emptyState = document.getElementById('cart-empty');
    const cartItemsContainer = document.getElementById('cart-items-container');
    
    if (cartItems.length === 0) {
        cartItemsContainer.classList.add('hidden');
        emptyState.classList.remove('hidden');
    }
}

// Update cart count badge via AJAX - Counts distinct items
function updateCartCount() {
    const formData = new FormData();
    formData.append('action', 'get_cart_count');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const cartBadge = document.querySelector('.cart-badge');
            const count = data.count || 0;
            
            if (count > 0) {
                cartBadge.textContent = count > 99 ? '99+' : count;
                cartBadge.style.display = 'flex';
            } else {
                cartBadge.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// Notification function
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification ${type === 'error' ? 'error' : ''}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Preview profile picture before upload
const profilePictureInput = document.querySelector('input[name="profile_picture"]');
if (profilePictureInput) {
    profilePictureInput.addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                showNotification('Profile picture ready to upload. Click "Update Profile" to save.');
            }
            reader.readAsDataURL(this.files[0]);
        }
    });
}

// Initialize cart display and search functionality
document.addEventListener('DOMContentLoaded', function() {
    updateCartCount();
    
    // Setup search suggestions
    if (searchInput) {
        setupSearchSuggestions(searchInput, searchSuggestions);
    }
    if (mobileSearchInput) {
        setupSearchSuggestions(mobileSearchInput, mobileSearchSuggestions);
    }
    
    // Add smooth scrolling to all links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelector(this.getAttribute('href')).scrollIntoView({
                behavior: 'smooth'
            });
        });
    });
});

// Form validation
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = '#f44336';
                } else {
                    field.style.borderColor = '';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                showNotification('Please fill in all required fields', 'error');
            }
        });
    });
});
</script>
</body>
</html>