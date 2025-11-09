<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'branch_admin') {
    header("Location: ../admin/dashboard.php");
    exit();
}

$pageTitle = 'Menu Management - Branch Admin';
$currentPage = 'menus';

// Admin info
$adminName = $_SESSION['name'] ?? "Branch Admin";
$adminId = $_SESSION['user_id'] ?? null;
$firstLetter = strtoupper(substr($adminName, 0, 1));

// Get restaurant_id and restaurant_name for this branch admin
$restaurant_id = $_SESSION['restaurant_id'] ?? null;
$restaurant_name = $_SESSION['restaurant_name'] ?? null;

// If restaurant info is not in session, try to get it from the database
if ((!$restaurant_id || !$restaurant_name) && $adminId) {
    $stmt = $conn->prepare("SELECT id, name FROM restaurants WHERE owner_id = ?");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $restaurant = $result->fetch_assoc();
        $restaurant_id = $restaurant['id'];
        $restaurant_name = $restaurant['name'];
        $_SESSION['restaurant_id'] = $restaurant_id;
        $_SESSION['restaurant_name'] = $restaurant_name;
    }
    $stmt->close();
}

// Helper function to generate product image URL
function generateProductImageUrl($productName, $restaurantName) {
    // Sanitize restaurant name for folder
    $sanitizedRestaurant = preg_replace('/[^a-zA-Z0-9]/', '_', $restaurantName);
    $sanitizedRestaurant = trim($sanitizedRestaurant, '_');
    
    // Sanitize product name for filename
    $sanitizedProduct = preg_replace('/[^a-zA-Z0-9]/', '_', $productName);
    $sanitizedProduct = trim($sanitizedProduct, '_');
    
    // Return URL path (no actual file creation)
    return 'uploads/menus/' . $sanitizedRestaurant . '/' . $sanitizedProduct . '.jpg';
}

// Handle actions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$restaurant_id || !$restaurant_name) {
        $message = "Error: Restaurant information not found. Please log in again.";
    } else {
        switch ($_POST['action']) {
            case 'add':
                $productName = trim($_POST['name']);
                
                // Generate product image URL path (no actual file creation)
                $product_image_url = generateProductImageUrl($productName, $restaurant_name);
                
                $stmt = $conn->prepare("INSERT INTO products (name, description, category, price, product_image_url, restaurant_id, is_available) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("sssdsi", $productName, $_POST['description'], $_POST['category'], $_POST['price'], $product_image_url, $restaurant_id);
                if ($stmt->execute()) {
                    $message = "Product added successfully!";
                } else {
                    $message = "Error adding product: " . $stmt->error;
                }
                $stmt->close();
                break;
                
            case 'edit':
                $productName = trim($_POST['name']);
                
                // Generate new product image URL based on product name
                $product_image_url = generateProductImageUrl($productName, $restaurant_name);
                
                $stmt = $conn->prepare("UPDATE products SET name=?, description=?, category=?, price=?, product_image_url=? WHERE id=? AND restaurant_id=?");
                $stmt->bind_param("sssdsii", $productName, $_POST['description'], $_POST['category'], $_POST['price'], $product_image_url, $_POST['id'], $restaurant_id);
                if ($stmt->execute()) {
                    $message = "Product updated successfully!";
                } else {
                    $message = "Error updating product: " . $stmt->error;
                }
                $stmt->close();
                break;
                
            case 'delete':
                // Delete product from database (no actual file deletion needed)
                $stmt = $conn->prepare("DELETE FROM products WHERE id=? AND restaurant_id=?");
                $stmt->bind_param("ii", $_POST['id'], $restaurant_id);
                
                if ($stmt->execute()) {
                    $message = "Product deleted successfully!";
                } else {
                    $message = "Error deleting product: " . $stmt->error;
                }
                $stmt->close();
                break;
                
            case 'toggle':
                $stmt = $conn->prepare("UPDATE products SET is_available = NOT is_available WHERE id=? AND restaurant_id=?");
                $stmt->bind_param("ii", $_POST['id'], $restaurant_id);
                if ($stmt->execute()) {
                    $message = "Product availability toggled!";
                } else {
                    $message = "Error toggling product: " . $stmt->error;
                }
                $stmt->close();
                break;
        }
    }
}

// Fetch products for this restaurant
$products = [];
if ($restaurant_id) {
    $result = $conn->query("SELECT * FROM products WHERE restaurant_id = $restaurant_id ORDER BY id DESC");
    if ($result) {
        $products = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.page-title {
    color: var(--text-color);
    font-size: 1.8rem;
    font-weight: 700;
}

/* === Buttons === */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border-radius: 10px;
    font-weight: 500;
    transition: var(--transition);
    border: none;
    cursor: pointer;
    font-size: 14px;
}

.btn-primary {
    background: var(--primary-color);
    color: white;
}

.btn-primary:hover {
    background: #5a52e0;
    box-shadow: 0 8px 15px rgba(108, 99, 255, 0.3);
    transform: translateY(-2px);
}

.btn-sm {
    padding: 8px 14px;
    font-size: 12px;
}

.btn-outline {
    background: transparent;
    color: var(--primary-color);
    border: 1px solid var(--primary-color);
}

.btn-outline:hover {
    background: var(--primary-color);
    color: white;
    transform: translateY(-2px);
}

.btn-danger {
    background: #EF4444;
    color: white;
}

.btn-danger:hover {
    background: #DC2626;
    transform: translateY(-2px);
}

.btn-success {
    background: #10B981;
    color: white;
}

.btn-success:hover {
    background: #059669;
    transform: translateY(-2px);
}

.btn-secondary {
    background: #6B7280;
    color: white;
}

.btn-secondary:hover {
    background: #4B5563;
    transform: translateY(-2px);
}

/* === Table === */
.table-container {
    background: var(--card-bg);
    border-radius: var(--border-radius);
    padding: 28px;
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow);
    margin-top: 20px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

th, td {
    padding: 16px;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
    vertical-align: middle;
}

th {
    color: var(--text-secondary);
    font-weight: 600;
    position: sticky;
    top: 0;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: var(--bg-color);
}

tr {
    transition: var(--transition);
}

tr:hover {
    background: var(--bg-color);
}

/* === Product Info Cell === */
.product-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.product-image-wrapper {
    flex-shrink: 0;
    width: 80px;
    height: 80px;
    border-radius: 12px;
    overflow: hidden;
    background: var(--bg-color);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--border-color);
    transition: var(--transition);
}

.product-image-wrapper:hover {
    transform: scale(1.05);
    border-color: var(--primary-color);
}

.product-image-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-image-placeholder {
    color: var(--text-secondary);
    font-size: 2rem;
    text-align: center;
}

.product-details {
    flex: 1;
    min-width: 0;
}

.product-name {
    font-weight: 600;
    color: var(--text-color);
    font-size: 1rem;
    margin-bottom: 4px;
}

.product-description {
    color: var(--text-secondary);
    font-size: 0.85rem;
    margin-top: 2px;
    line-height: 1.4;
}

.product-url {
    font-family: monospace;
    font-size: 0.7rem;
    color: var(--primary-color);
    word-break: break-all;
    margin-top: 6px;
    background: var(--bg-color);
    padding: 4px 8px;
    border-radius: 6px;
    display: inline-block;
}

/* === Image Preview in Modal === */
.image-preview-container {
    margin: 15px 0;
    padding: 20px;
    background: var(--bg-color);
    border-radius: 12px;
    border: 1px solid var(--border-color);
}

.image-preview-box {
    width: 150px;
    height: 150px;
    margin: 10px auto;
    border-radius: 12px;
    overflow: hidden;
    background: var(--card-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--border-color);
    transition: var(--transition);
}

.image-preview-box:hover {
    border-color: var(--primary-color);
}

.image-preview-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.image-preview-placeholder {
    color: var(--text-secondary);
    font-size: 3rem;
}

/* === Alerts === */
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    border-left: 4px solid;
    background: var(--card-bg);
    box-shadow: var(--shadow);
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

/* === Modals === */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1002;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(5px);
}

.modal-content {
    background: var(--card-bg);
    border-radius: var(--border-radius);
    padding: 28px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
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
    font-size: 1.4rem;
    font-weight: 600;
}

.close-modal {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-secondary);
    transition: var(--transition);
}

.close-modal:hover {
    color: var(--primary-color);
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text-color);
}

.form-group input, .form-group textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    background: var(--bg-color);
    color: var(--text-color);
    transition: var(--transition);
    font-size: 14px;
}

.form-group input:focus, .form-group textarea:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.1);
}

.form-group textarea {
    min-height: 80px;
    resize: vertical;
}

/* === Restaurant Info Badge === */
.restaurant-badge {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: white;
    padding: 12px 20px;
    border-radius: 12px;
    font-weight: 600;
    display: inline-block;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}

/* === URL Display === */
.url-display {
    background: var(--bg-color);
    padding: 10px 14px;
    border-radius: 8px;
    font-family: monospace;
    font-size: 0.85rem;
    color: var(--text-color);
    margin-top: 8px;
    word-break: break-all;
    border: 1px solid var(--border-color);
}

.form-hint {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-top: 5px;
}

.product-url-info {
    background: rgba(108, 99, 255, 0.1);
    border-left: 4px solid var(--primary-color);
    padding: 12px 16px;
    margin-top: 12px;
    border-radius: 8px;
    font-size: 0.85rem;
    color: var(--text-color);
}

/* === Status Badges === */
.status-available {
    background: #10B981;
    color: white;
}

.status-unavailable {
    background: #6B7280;
    color: white;
}

.category-badge {
    background: rgba(108, 99, 255, 0.1);
    color: var(--primary-color);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
    border: 1px solid rgba(108, 99, 255, 0.2);
}

.price-display {
    color: var(--primary-color);
    font-size: 1.1rem;
    font-weight: 700;
}

/* === Empty State === */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-secondary);
}

.empty-state-icon {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.7;
}

.empty-state-title {
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text-color);
}

.empty-state-description {
    font-size: 0.95rem;
    max-width: 400px;
    margin: 0 auto;
}

/* === Action Buttons === */
.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* === Responsive === */
@media (max-width: 768px) {
    .main {
        margin-left: 0;
        padding: 20px;
    }
    
    .table-container {
        overflow-x: auto;
        padding: 20px;
    }
    
    .product-info {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .product-image-wrapper {
        width: 100%;
        height: 120px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    
    .topbar {
        padding: 0 20px;
    }
    
    .topbar-right .profile-name {
        display: none;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .action-buttons .btn {
        width: 100%;
        justify-content: center;
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
        <a href="menus.php" class="active">🍽 Menu</a>
        <a href="payments.php">💰 Payments</a>
        <a href="settings.php">⚙️ Settings</a>
    </nav>
</aside>

<!-- MAIN -->
<main class="main" id="mainContent">
    <div class="page-header">
        <h1 class="page-title">Menu Management</h1>
        <button class="btn btn-primary" onclick="openModal('addMenuModal')">
            <span>➕</span> Add Product
        </button>
    </div>

    <?php if ($restaurant_name): ?>
        <div class="restaurant-badge">🏪 <?= htmlspecialchars($restaurant_name) ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert <?= strpos($message, 'Error') !== false ? 'alert-error' : 'alert-success' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!$restaurant_id || !$restaurant_name): ?>
        <div class="alert alert-error">
            <strong>Error: Restaurant not found.</strong><br>
            Please make sure your restaurant is properly set up in the system. 
            Contact the administrator if this issue persists.
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 40%;">Product</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <div class="empty-state-icon">🍽️</div>
                                    <div class="empty-state-title">No products yet</div>
                                    <div class="empty-state-description">
                                        Get started by adding your first product to the menu. 
                                        Click the "Add Product" button above.
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <div class="product-info">
                                    <div class="product-image-wrapper">
                                        <?php if (!empty($product['product_image_url'])): ?>
                                            <img src="../<?= htmlspecialchars($product['product_image_url']) ?>" 
                                                 alt="<?= htmlspecialchars($product['name']) ?>"
                                                 onerror="this.parentElement.innerHTML='<div class=\'product-image-placeholder\'>🍽️</div>'">
                                        <?php else: ?>
                                            <div class="product-image-placeholder">🍽️</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="product-details">
                                        <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                                        <div class="product-description">
                                            <?= htmlspecialchars($product['description']) ?>
                                        </div>
                                        <div class="product-url">
                                            📁 <?= htmlspecialchars($product['product_image_url'] ?: 'No URL') ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="category-badge">
                                    <?= htmlspecialchars($product['category']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="price-display">₱<?= number_format($product['price'], 2) ?></span>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $product['is_available'] ? 'btn-success' : 'btn-secondary' ?>">
                                        <?= $product['is_available'] ? '✓ Available' : '✕ Unavailable' ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button onclick='editProduct(<?= json_encode($product) ?>)' class="btn btn-sm btn-outline">✏️ Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this product?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<!-- Add Modal -->
<div id="addMenuModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Add Product</h3>
      <button class="close-modal" onclick="closeModal('addMenuModal')">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      
      <div class="form-group">
        <label>Product Name *</label>
        <input name="name" id="addProductName" required onchange="updateAddImageUrlPreview()" oninput="updateAddImageUrlPreview()">
        <div class="form-hint">The product name will be used to generate the image URL</div>
      </div>
      
      <div class="form-group">
        <label>Description</label>
        <textarea name="description"></textarea>
      </div>
      
      <div class="form-group">
        <label>Category *</label>
        <input name="category" required>
      </div>
      
      <div class="form-group">
        <label>Price *</label>
        <input type="number" step="0.01" name="price" required>
      </div>
      
      <div class="image-preview-container">
        <label style="font-weight:600;margin-bottom:10px;display:block;">Product Image Preview</label>
        <div class="image-preview-box" id="addImagePreview">
            <div class="image-preview-placeholder">🍽️</div>
        </div>
        <div style="text-align:center;margin-top:10px;">
            <div class="form-hint" style="margin:0;">Image URL will be:</div>
            <div id="addImageUrlPreview" class="url-display" style="display: none;margin-top:8px;">
                <span id="addUrlPreviewText"></span>
            </div>
        </div>
        <div class="product-url-info" style="margin-top:10px;">
            ℹ️ The image URL is automatically generated as: <br>
            <code>uploads/menus/<?= htmlspecialchars(preg_replace('/[^a-zA-Z0-9]/', '_', trim($restaurant_name, '_'))) ?>/Product_Name.jpg</code>
        </div>
      </div>
      
      <button type="submit" class="btn btn-primary" style="width:100%;">Add Product</button>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div id="editMenuModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Edit Product</h3>
      <button class="close-modal" onclick="closeModal('editMenuModal')">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" id="edit_id" name="id">
      
      <div class="form-group">
        <label>Product Name *</label>
        <input id="edit_name" name="name" required onchange="updateEditImageUrlPreview()" oninput="updateEditImageUrlPreview()">
        <div class="form-hint">Changing the product name will update the image URL</div>
      </div>
      
      <div class="form-group">
        <label>Description</label>
        <textarea id="edit_description" name="description"></textarea>
      </div>
      
      <div class="form-group">
        <label>Category *</label>
        <input id="edit_category" name="category" required>
      </div>
      
      <div class="form-group">
        <label>Price *</label>
        <input type="number" step="0.01" id="edit_price" name="price" required>
      </div>
      
      <div class="image-preview-container">
        <label style="font-weight:600;margin-bottom:10px;display:block;">Product Image Preview</label>
        <div class="image-preview-box" id="editImagePreview">
            <div class="image-preview-placeholder">🍽️</div>
        </div>
        <div style="text-align:center;margin-top:10px;">
            <div class="form-hint" style="margin:0;">Image URL will be:</div>
            <div id="editImageUrlPreview" class="url-display" style="margin-top:8px;">
                <span id="editUrlPreviewText"></span>
            </div>
        </div>
      </div>
      
      <button type="submit" class="btn btn-primary" style="width:100%;">Update Product</button>
    </form>
  </div>
</div>

<script>
const restaurantName = <?= json_encode($restaurant_name) ?>;

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

// Function to generate product image URL (matches PHP logic)
function generateProductImageUrl(productName, restaurantName) {
    // Sanitize restaurant name
    const sanitizedRestaurant = restaurantName.replace(/[^a-zA-Z0-9]/g, '_').replace(/^_+|_+$/g, '');
    
    // Sanitize product name
    const sanitizedProduct = productName.replace(/[^a-zA-Z0-9]/g, '_').replace(/^_+|_+$/g, '');
    
    return 'uploads/menus/' + sanitizedRestaurant + '/' + sanitizedProduct + '.jpg';
}

// Update image URL preview and image preview for Add form
function updateAddImageUrlPreview() {
    const productName = document.getElementById('addProductName').value;
    const preview = document.getElementById('addImageUrlPreview');
    const previewText = document.getElementById('addUrlPreviewText');
    const imagePreview = document.getElementById('addImagePreview');
    
    if (productName.trim() && restaurantName) {
        const url = generateProductImageUrl(productName, restaurantName);
        previewText.textContent = url;
        preview.style.display = 'block';
        
        // Update image preview
        imagePreview.innerHTML = `<img src="../${url}" alt="Preview" onerror="this.parentElement.innerHTML='<div class=\\'image-preview-placeholder\\'>🍽️</div>'">`;
    } else {
        preview.style.display = 'none';
        imagePreview.innerHTML = '<div class="image-preview-placeholder">🍽️</div>';
    }
}

// Update image URL preview and image preview for Edit form
function updateEditImageUrlPreview() {
    const productName = document.getElementById('edit_name').value;
    const previewText = document.getElementById('editUrlPreviewText');
    const imagePreview = document.getElementById('editImagePreview');
    
    if (productName.trim() && restaurantName) {
        const url = generateProductImageUrl(productName, restaurantName);
        previewText.textContent = url;
        
        // Update image preview
        imagePreview.innerHTML = `<img src="../${url}" alt="Preview" onerror="this.parentElement.innerHTML='<div class=\\'image-preview-placeholder\\'>🍽️</div>'">`;
    } else {
        imagePreview.innerHTML = '<div class="image-preview-placeholder">🍽️</div>';
    }
}

// Profile dropdown toggle
const profileToggle = document.getElementById('profileToggle');
const profileDropdown = document.getElementById('profileDropdown');
profileToggle.addEventListener('click', e => {
    e.stopPropagation();
    profileDropdown.style.display = profileDropdown.style.display==='flex'?'none':'flex';
});
document.addEventListener('click', e=>{
    if(profileDropdown&&!profileDropdown.contains(e.target)&&!profileToggle.contains(e.target)){
        profileDropdown.style.display='none';
    }
});

// Sidebar toggle
const menuBtn = document.getElementById('menuBtn');
const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
menuBtn.addEventListener('click',()=>{
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
});

// Modal Functions
function openModal(id) {
    document.getElementById(id).style.display = 'flex';
    
    // Reset Add form and show preview info
    if (id === 'addMenuModal') {
        document.getElementById('addProductName').value = '';
        document.getElementById('addImageUrlPreview').style.display = 'none';
        document.getElementById('addImagePreview').innerHTML = '<div class="image-preview-placeholder">🍽️</div>';
    }
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function editProduct(product) {
    document.getElementById('edit_id').value = product.id;
    document.getElementById('edit_name').value = product.name;
    document.getElementById('edit_description').value = product.description || '';
    document.getElementById('edit_category').value = product.category;
    document.getElementById('edit_price').value = product.price;
    
    // Show current image URL and preview
    const imageUrl = product.product_image_url || '';
    document.getElementById('editUrlPreviewText').textContent = imageUrl || 'No URL';
    
    const imagePreview = document.getElementById('editImagePreview');
    if (imageUrl) {
        imagePreview.innerHTML = `<img src="../${imageUrl}" alt="Preview" onerror="this.parentElement.innerHTML='<div class=\\'image-preview-placeholder\\'>🍽️</div>'">`;
    } else {
        imagePreview.innerHTML = '<div class="image-preview-placeholder">🍽️</div>';
    }
    
    openModal('editMenuModal');
}

// Close modal when clicking outside
window.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
});

// Auto-update preview when product name changes
document.addEventListener('DOMContentLoaded', function() {
    const addProductName = document.getElementById('addProductName');
    const editProductName = document.getElementById('edit_name');
    
    if (addProductName) {
        addProductName.addEventListener('input', updateAddImageUrlPreview);
    }
    
    if (editProductName) {
        editProductName.addEventListener('input', updateEditImageUrlPreview);
    }
});
</script>

</body>
</html>