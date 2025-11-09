<?php
session_start();

// Include database connection - updated path to match your structure
require_once(__DIR__ . '/../config.php');
require_once(DB_PATH . 'db.php');

// Check if user is logged in and has 'user' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') { 
    header("Location: ../login/Studlogin.php"); 
    exit(); 
}

// Function to generate star rating
function generateStars($rating) {
    $stars = '';
    $fullStars = floor($rating);
    $halfStar = ($rating - $fullStars) >= 0.5;
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $stars .= '<i class="fas fa-star"></i>';
    }
    
    // Half star
    if ($halfStar) {
        $stars .= '<i class="fas fa-star-half-alt"></i>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $stars .= '<i class="far fa-star"></i>';
    }
    
    return $stars;
}

// Get user details from database using session user_id
$userName = 'User';
$firstLetter = 'U';
$user_id = $_SESSION['user_id'];

// Fetch user details from users table
$user_query = "SELECT name, email, role, profile_image FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows > 0) {
    $user_data = $user_result->fetch_assoc();
    $userName = $user_data['name'];
    $firstLetter = strtoupper(substr($userName, 0, 1));
} else {
    // If user not found in database, log them out
    session_destroy();
    header("Location: ../login/Studlogin.php");
    exit();
}
$user_stmt->close();

// Fetch cart items for the current user
$cart_items = [];
$cart_total = 0;
$cart_count = 0;
$cart_item_count = 0; // Count distinct items

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

// Handle AJAX requests for cart operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];
    
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'User not logged in';
        echo json_encode($response);
        exit();
    }
    
    $user_id = $_SESSION['user_id'];
    
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
    }
    
    echo json_encode($response);
    exit();
}

// Function to get cart data
function getCartData($conn, $user_id) {
    $cart_data = [
        'items' => [],
        'total' => 0,
        'count' => 0,
        'item_count' => 0 // Count distinct items
    ];
    
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

// Get store ID from URL
$store_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($store_id === 0) {
    header("Location: UserInt.php");
    exit();
}

// Fetch store data from database
$store = [];
$store_query = "SELECT r.*, u.name as owner_name 
                FROM restaurants r 
                LEFT JOIN users u ON r.owner_id = u.id 
                WHERE r.id = ?";
$store_stmt = $conn->prepare($store_query);
$store_stmt->bind_param("i", $store_id);
$store_stmt->execute();
$store_result = $store_stmt->get_result();

if ($store_result->num_rows > 0) {
    $store = $store_result->fetch_assoc();
} else {
    // If store not found, redirect to home
    header("Location: UserInt.php");
    exit();
}
$store_stmt->close();

// Fetch menu items for the store from products table - FIXED: Removed image_url reference
$menu_items = [];
$menu_query = "SELECT * FROM products WHERE restaurant_id = ? AND is_available = 1 ORDER BY category, name";
$menu_stmt = $conn->prepare($menu_query);
$menu_stmt->bind_param("i", $store_id);
$menu_stmt->execute();
$menu_result = $menu_stmt->get_result();

while ($row = $menu_result->fetch_assoc()) {
    $menu_items[] = $row;
}
$menu_stmt->close();

// Prepare products data for JavaScript
$products_js = [];
foreach ($menu_items as $product) {
    $products_js[$product['id']] = [
        'name' => $product['name'],
        'description' => $product['description'],
        'price' => $product['price'],
        'category' => $product['category'] ?? 'General',
        'restaurant_id' => $product['restaurant_id']
    ];
}

// Function to get product image path - SIMPLIFIED VERSION
function getProductImage($productName) {
    $imageName = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($productName));
    $folders = [
        '/test/Images/products/',
        '/Images/products/',
        '/products/',
        '/uploads/products/',
        '../Images/products/',
        '../../Images/products/'
    ];
    $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    
    foreach ($folders as $folder) {
        foreach ($extensions as $ext) {
            $tryPath = $_SERVER['DOCUMENT_ROOT'] . $folder . $imageName . '.' . $ext;
            if (file_exists($tryPath)) {
                return $folder . $imageName . '.' . $ext;
            }
        }
    }
    
    return null; // No image found
}

// Function to get store image path - SIMPLIFIED VERSION
function getStoreImage($storeName) {
    $imageName = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($storeName));
    $folders = [
        '/test/Images/stores/',
        '/Images/stores/',
        '/stores/',
        '/uploads/stores/',
        '../Images/stores/',
        '../../Images/stores/'
    ];
    $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    
    foreach ($folders as $folder) {
        foreach ($extensions as $ext) {
            $tryPath = $_SERVER['DOCUMENT_ROOT'] . $folder . $imageName . '.' . $ext;
            if (file_exists($tryPath)) {
                return $folder . $imageName . '.' . $ext;
            }
        }
    }
    
    return null; // No image found
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($store['name']); ?> - UMAK Foodhub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        /* ... (your existing CSS styles remain the same) ... */
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
        
        /* Store Specific Styles */
        .store-status {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #666;
        }
        
        .store-status.status-open {
            color: #38a169;
        }
        
        .store-status.status-closed {
            color: #e53e3e;
        }
        
        .menu-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .menu-title {
            font-size: 2rem;
            font-weight: bold;
            color: #333;
            margin: 0;
        }
        
        .menu-controls {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-box {
            position: relative;
            min-width: 280px;
        }
        
        .search-box input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #fbbf24;
        }
        
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }
        
        .category-filter {
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            background: white;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 180px;
        }
        
        .category-filter:focus {
            outline: none;
            border-color: #fbbf24;
        }
        
        /* Menu Items Grid */
        .menu-items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }
        
        .menu-item-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #f0f0f0;
        }
        
        .menu-item-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }
        
        .menu-item-image {
            position: relative;
            height: 200px;
            overflow: hidden;
        }
        
        .menu-item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .menu-item-card:hover .menu-item-image img {
            transform: scale(1.05);
        }
        
        .item-favorite-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .item-favorite-btn:hover {
            background: #fef3c7;
            transform: scale(1.1);
        }
        
        .item-favorite-btn.active {
            background: #fbbf24;
        }
        
        .item-favorite-btn.active i {
            color: #dc2626;
        }
        
        .menu-item-info {
            padding: 20px;
        }
        
        .item-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        .item-description {
            color: #6b7280;
            font-size: 0.875rem;
            line-height: 1.5;
            margin-bottom: 12px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .item-rating {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }
        
        .rating-stars-small {
            display: flex;
            gap: 2px;
        }
        
        .rating-stars-small i {
            font-size: 12px;
            color: #fbbf24;
        }
        
        .rating-value-small {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .item-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .item-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e3a8a;
        }
        
        .add-to-cart-btn {
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .add-to-cart-btn:hover {
            background: #3730a3;
            transform: translateY(-2px);
        }
        
        /* Store Header */
        .store-header-section {
            margin-bottom: 40px;
        }
        
        .store-banner {
            display: flex;
            gap: 30px;
            align-items: center;
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        .store-image-large {
            flex-shrink: 0;
            width: 200px;
            height: 200px;
            border-radius: 16px;
            overflow: hidden;
        }
        
        .store-image-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .store-info-header {
            flex: 1;
        }
        
        .store-info-header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        .store-category {
            display: inline-block;
            background: #fbbf24;
            color: #1e3a8a;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 16px;
        }
        
        .store-rating-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .rating-stars {
            display: flex;
            gap: 4px;
        }
        
        .rating-stars i {
            font-size: 18px;
            color: #fbbf24;
        }
        
        .rating-value {
            font-size: 1rem;
            color: #6b7280;
            font-weight: 500;
        }
        
        .store-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 16px;
        }
        
        .delivery-time, .store-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .store-description {
            color: #6b7280;
            line-height: 1.6;
            font-size: 1rem;
        }
        
        @media (max-width: 768px) {
            .menu-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .menu-controls {
                justify-content: space-between;
            }
            
            .search-box {
                min-width: auto;
                flex: 1;
            }
            
            .store-banner {
                flex-direction: column;
                text-align: center;
            }
            
            .store-image-large {
                width: 150px;
                height: 150px;
            }
            
            .store-info-header h1 {
                font-size: 2rem;
            }
            
            .menu-items-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .menu-controls {
                flex-direction: column;
                gap: 10px;
            }
            
            .search-box, .category-filter {
                min-width: 100%;
            }
            
            .store-banner {
                padding: 20px;
            }
            
            .store-info-header h1 {
                font-size: 1.75rem;
            }
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
                    <div class="w-8 h-8 rounded-lg overflow-hidden">
                        <img src="FoodHub_Title.png" alt="FoodHub Logo" class="w-full h-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
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
            
            <!-- Center: Search Bar -->
            <div class="hidden md:flex items-center flex-1 max-w-lg mx-8">
                <div class="flex items-center w-full bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                    <select class="px-4 py-3 bg-transparent border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer">
                        <option value="all">All</option>
                        <option value="foods">Foods</option>
                        <option value="shops">Stores</option>
                    </select>
                    <div class="w-px h-6 bg-gray-300"></div>
                    <input type="text" placeholder="What are you craving today?" class="flex-1 px-4 py-3 border-none focus:outline-none focus:ring-0 text-sm">
                    <button class="px-6 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Right: Cart and Profile -->
            <div class="flex items-center space-x-4">
                <button id="cart-btn" class="relative p-2 text-white hover:text-yellow-300 hover:bg-blue-800 rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9m-9 0V19a2 2 0 002 2h7a2 2 0 002-2v-4"></path>
                    </svg>
                    <span class="cart-badge"><?php echo $cart_item_count; ?></span>
                </button>
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 umak-yellow rounded-full flex items-center justify-center">
                        <span class="umak-blue-text font-bold text-sm"><?php echo $firstLetter; ?></span>
                    </div>
                    <span id="user-name" class="hidden sm:block text-sm font-medium text-white"><?php echo htmlspecialchars($userName); ?></span>
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
                <div class="w-8 h-8 rounded-lg overflow-hidden">
                    <img src="FoodHub_Title.png" alt="FoodHub Logo" class="w-full h-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
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
            <li><a href="profile.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                <span>Profile</span>
            </a></li>
            <li><a href="../users/logout.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
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
                        <?php
                        $productImage = getProductImage($item['product_name']);
                        if ($productImage): ?>
                            <img src="<?php echo htmlspecialchars($productImage); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" class="w-12 h-12 object-cover rounded">
                        <?php else: ?>
                            <span class="text-gray-400 text-2xl">🍽️</span>
                        <?php endif; ?>
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
        <!-- Mobile Search -->
        <div class="md:hidden mb-6">
            <div class="flex items-center bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden focus-within:ring-2 focus-within:ring-yellow-400 focus-within:border-yellow-400 transition-all duration-200">
                <select class="px-3 py-3 bg-transparent border-none text-sm focus:outline-none focus:ring-0 appearance-none cursor-pointer flex-shrink-0">
                    <option value="all">All</option>
                    <option value="foods">Foods</option>
                    <option value="shops">Stores</option>
                </select>
                <div class="w-px h-6 bg-gray-300 flex-shrink-0"></div>
                <input type="text" placeholder="Search food or stores..." class="flex-1 px-3 py-3 border-none focus:outline-none focus:ring-0 text-sm">
                <button class="px-4 py-3 umak-yellow umak-blue-text hover:bg-yellow-300 transition-all duration-300 font-medium border-l border-yellow-400 flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <!-- Store Content -->
        <div class="content-area store-content">
            <!-- Store Header -->
            <div class="store-header-section">
                <div class="store-banner">
                    <div class="store-image-large">
                        <?php
                        $storeImage = getStoreImage($store['name']);
                        if ($storeImage): ?>
                            <img src="<?php echo htmlspecialchars($storeImage); ?>" 
                                 alt="<?php echo htmlspecialchars($store['name']); ?>">
                        <?php else: ?>
                            <div class="w-full h-full umak-blue flex items-center justify-center">
                                <span class="text-white text-4xl font-bold">🏪</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="store-info-header">
                        <h1><?php echo htmlspecialchars($store['name']); ?></h1>
                        <div class="store-category">Restaurant</div>
                        <div class="store-rating-header">
                            <div class="rating-stars">
                                <?php echo generateStars(4.0); ?>
                            </div>
                            <div class="rating-value">4.0 (0 reviews)</div>
                        </div>
                        <div class="store-meta">
                            <div class="delivery-time">
                                <i class="fas fa-clock"></i>
                                <?php echo $store['pickup_interval'] ?? 30; ?> min
                            </div>
                            <div class="store-status <?php echo ($store['is_active'] && $store['status'] == 'active') ? 'status-open' : 'status-closed'; ?>">
                                <i class="fas fa-circle"></i>
                                <?php echo ($store['is_active'] && $store['status'] == 'active') ? 'Open' : 'Closed'; ?>
                            </div>
                        </div>
                        <p class="store-description">
                            <?php 
                            if (!empty($store['owner_name'])) {
                                echo 'Owned by ' . htmlspecialchars($store['owner_name']) . '. ';
                            }
                            echo 'Hours: ' . date('g:i A', strtotime($store['opening_time'])) . ' - ' . date('g:i A', strtotime($store['closing_time']));
                            ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <div class="menu-header">
                    <h2 class="menu-title">Menu Items</h2>
                    <div class="menu-controls">
                        <div class="search-box">
                            <input type="text" placeholder="Search menu..." id="menuSearch">
                            <i class="fas fa-search search-icon"></i>
                        </div>
                        <select class="category-filter" id="categoryFilter">
                            <option value="all">All Categories</option>
                            <?php
                            // Get unique categories from menu items
                            $categories = [];
                            foreach ($menu_items as $item) {
                                $category = $item['category'] ?? 'Uncategorized';
                                if (!in_array($category, $categories)) {
                                    $categories[] = $category;
                                }
                            }
                            foreach ($categories as $category): 
                            ?>
                            <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Menu Items Grid -->
                <div class="menu-items-grid" id="menuItemsGrid">
                    <?php if (empty($menu_items)): ?>
                    <div class="col-span-full text-center py-12">
                        <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewbox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                        <p class="text-gray-500 mb-2">No menu items available</p>
                        <p class="text-sm text-gray-400">This store hasn't added any items yet</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($menu_items as $item): ?>
                    <div class="menu-item-card" data-category="<?php echo htmlspecialchars($item['category'] ?? 'Uncategorized'); ?>" data-name="<?php echo htmlspecialchars(strtolower($item['name'])); ?>">
                        <div class="menu-item-image">
                            <?php
                            $productImage = getProductImage($item['name']);
                            if ($productImage): ?>
                                <img src="<?php echo htmlspecialchars($productImage); ?>" 
                                     alt="<?php echo htmlspecialchars($item['name']); ?>">
                            <?php else: ?>
                                <div class="w-full h-full umak-blue flex items-center justify-center">
                                    <span class="text-white text-4xl">🍽️</span>
                                </div>
                            <?php endif; ?>
                            <button class="item-favorite-btn" data-item-id="<?php echo $item['id']; ?>" data-product-name="<?php echo htmlspecialchars($item['name']); ?>" data-store-name="<?php echo htmlspecialchars($store['name']); ?>">
                                <i class="fa-regular fa-heart"></i>
                            </button>
                        </div>
                        <div class="menu-item-info">
                            <h3 class="item-name"><?php echo htmlspecialchars($item['name']); ?></h3>
                            <p class="item-description"><?php echo htmlspecialchars($item['description'] ?? 'Delicious dish from our restaurant'); ?></p>
                            <div class="item-rating">
                                <div class="rating-stars-small">
                                    <?php echo generateStars(4.0); ?>
                                </div>
                                <span class="rating-value-small">4.0</span>
                            </div>
                            <div class="item-footer">
                                <div class="item-price">₱<?php echo number_format($item['price'], 2); ?></div>
                                <button class="add-to-cart-btn" 
                                        data-item-id="<?php echo $item['id']; ?>" 
                                        data-item-name="<?php echo htmlspecialchars($item['name']); ?>" 
                                        data-item-price="<?php echo $item['price']; ?>">
                                    Add to Cart
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// Product data from PHP
const products = <?php echo json_encode($products_js); ?>;

// UI Elements
const hamburger = document.getElementById('hamburger');
const sidebar = document.getElementById('sidebar');
const closeSidebar = document.getElementById('close-sidebar');
const cartBtn = document.getElementById('cart-btn');
const cartSidebar = document.getElementById('cart-sidebar');
const closeCart = document.getElementById('close-cart');
const overlay = document.getElementById('overlay');

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

// Show notification
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification ${type === 'error' ? 'error' : ''}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Add item to cart via AJAX
function addToCart(productId, quantity = 1) {
    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('quantity', quantity);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Item added to cart!');
            updateCartCount();
            if (data.cart_data) {
                updateCartDisplay(data.cart_data);
            }
        } else {
            showNotification(data.message || 'Failed to add item to cart', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

// Update cart display with new data
function updateCartDisplay(cartData) {
    const cartItemsContainer = document.getElementById('cart-items-container');
    const cartEmpty = document.getElementById('cart-empty');
    const cartTotal = document.getElementById('cart-total');
    const proceedToCheckout = document.getElementById('proceed-to-checkout');
    
    if (cartData.items.length === 0) {
        cartItemsContainer.classList.add('hidden');
        cartEmpty.classList.remove('hidden');
        proceedToCheckout.classList.add('opacity-50', 'pointer-events-none');
    } else {
        cartEmpty.classList.add('hidden');
        cartItemsContainer.classList.remove('hidden');
        proceedToCheckout.classList.remove('opacity-50', 'pointer-events-none');
        
        cartItemsContainer.innerHTML = '';
        
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
            quantityElement.textContent = newQuantity;
            updateCartCount();
            if (data.cart_data) {
                updateCartDisplay(data.cart_data);
            } else {
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

// Update cart count badge via AJAX
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

// Store functionality
document.addEventListener('DOMContentLoaded', function() {
    // Initialize cart count
    updateCartCount();
    
    // Favorite buttons functionality
    const favoriteButtons = document.querySelectorAll('.item-favorite-btn');
    
    favoriteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const icon = this.querySelector('i');
            const itemId = this.getAttribute('data-item-id');
            const productName = this.getAttribute('data-product-name');
            const storeName = this.getAttribute('data-store-name');
            
            if (icon.classList.contains('fa-regular')) {
                icon.classList.remove('fa-regular');
                icon.classList.add('fa-solid');
                this.classList.add('active');
                showNotification(`Added ${productName} to favorites`);
            } else {
                icon.classList.remove('fa-solid');
                icon.classList.add('fa-regular');
                this.classList.remove('active');
                showNotification(`Removed ${productName} from favorites`);
            }
        });
    });
    
    // Add to Cart buttons functionality
    const addToCartButtons = document.querySelectorAll('.add-to-cart-btn');
    addToCartButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const itemId = this.getAttribute('data-item-id');
            addToCart(itemId, 1);
        });
    });
    
    // Search functionality
    const menuSearch = document.getElementById('menuSearch');
    const menuItems = document.querySelectorAll('.menu-item-card');
    
    if (menuSearch) {
        menuSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            menuItems.forEach(item => {
                const itemName = item.getAttribute('data-name');
                const itemDescription = item.querySelector('.item-description')?.textContent.toLowerCase() || '';
                
                if (itemName.includes(searchTerm) || 
                    itemDescription.includes(searchTerm) || 
                    searchTerm === '') {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }
    
    // Category filter functionality
    const categoryFilter = document.getElementById('categoryFilter');
    if (categoryFilter) {
        categoryFilter.addEventListener('change', function() {
            const selectedCategory = this.value;
            
            menuItems.forEach(item => {
                const itemCategory = item.getAttribute('data-category');
                if (selectedCategory === 'all' || itemCategory === selectedCategory) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }
});
</script>
</body>
</html>