<?php
require_once(__DIR__ . '/../config.php');
require_once(DB_PATH . 'db.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Security check - ensure user is branch admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'branch_admin') {
    header("Location: login.php");
    exit();
}
$currentPage = 'dashboard';
$pageTitle = 'Dashboard - Branch Admin';
// Admin info
$adminName = $_SESSION['name'] ?? "Branch Admin";
$firstLetter = strtoupper(substr($adminName, 0, 1));
$adminId = $_SESSION['user_id'] ?? 0;
// Get the restaurant owned by this branch admin
$restaurantId = null;
try {
    $stmt = $conn->prepare("SELECT id FROM restaurants WHERE owner_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $restaurantId = $row['id'];
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching restaurant: " . $e->getMessage());
}

// Handle AJAX request for order details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'get_order_details' && $restaurantId) {
        $orderId = intval($_POST['order_id']);
        
        try {
            // Get order details with customer info and payment info
            $stmt = $conn->prepare("
                SELECT 
                    o.id,
                    o.order_number,
                    o.total_amount,
                    o.status,
                    o.order_type,
                    o.order_date,
                    o.created_at,
                    u.name as customer_name,
                    u.email as customer_email,
                    p.payment_method,
                    p.payment_status
                FROM orders o
                JOIN users u ON o.user_id = u.id
                LEFT JOIN payments p ON o.payment_id = p.payment_id
                WHERE o.id = ? AND o.restaurant_id = ?
            ");
            $stmt->bind_param("ii", $orderId, $restaurantId);
            $stmt->execute();
            $result = $stmt->get_result();
            $orderData = $result->fetch_assoc();
            $stmt->close();
            
            if ($orderData) {
                // Get order items - FIXED: Use correct column name product_image_url
                $stmt = $conn->prepare("
                    SELECT 
                        oi.item_name,
                        oi.quantity,
                        oi.unit_price,
                        oi.total_price,
                        oi.product_image_url,
                        p.product_image_url as product_image
                    FROM order_items oi
                    LEFT JOIN products p ON oi.product_id = p.id
                    WHERE oi.order_id = ?
                ");
                $stmt->bind_param("i", $orderId);
                $stmt->execute();
                $result = $stmt->get_result();
                $orderItems = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                $orderData['items'] = $orderItems;
                echo json_encode(['success' => true, 'order' => $orderData]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Order not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
}

// Initialize variables
$ordersToday = 0;
$revenueToday = 0;
$activeMenuItems = 0;
$preparingOrders = 0;
$recentOrders = [];
$weeklyOrders = 0;
$avgOrderValue = 0;
// Only fetch data if restaurant exists
if ($restaurantId) {
    try {
        // Orders today
        $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND DATE(order_date) = CURDATE()");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $stmt->bind_result($ordersToday);
        $stmt->fetch();
        $stmt->close();
        // Revenue today
        $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE restaurant_id = ? AND DATE(order_date) = CURDATE() AND status = 'completed'");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $stmt->bind_result($revenueToday);
        $stmt->fetch();
        $stmt->close();
        // Active menu items
        $stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE restaurant_id = ? AND is_available = 1");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $stmt->bind_result($activeMenuItems);
        $stmt->fetch();
        $stmt->close();
        // Preparing orders
        $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status = 'preparing'");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $stmt->bind_result($preparingOrders);
        $stmt->fetch();
        $stmt->close();
        // Recent orders (today and yesterday)
        $stmt = $conn->prepare("
            SELECT 
                o.id,
                o.order_number,
                u.name as customer,
                o.total_amount as total,
                o.status,
                o.order_date,
                o.order_type
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE o.restaurant_id = ? 
            AND DATE(o.order_date) >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
            ORDER BY o.order_date DESC 
            LIMIT 10
        ");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $result = $stmt->get_result();
        $recentOrders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        // Total orders this week
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM orders 
            WHERE restaurant_id = ? 
            AND YEARWEEK(order_date, 1) = YEARWEEK(CURDATE(), 1)
        ");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $stmt->bind_result($weeklyOrders);
        $stmt->fetch();
        $stmt->close();
        // Average order value today
        $stmt = $conn->prepare("
            SELECT COALESCE(AVG(total_amount), 0) 
            FROM orders 
            WHERE restaurant_id = ? 
            AND DATE(order_date) = CURDATE()
        ");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $stmt->bind_result($avgOrderValue);
        $stmt->fetch();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Dashboard database error: " . $e->getMessage());
    }
}
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

        /* === Global === */
        * { 
            margin:0; 
            padding:0; 
            box-sizing:border-box; 
        }
        
        body {
            font-family: "Inter", -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-color); 
            color: var(--text-color);
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        a {text-decoration:none;}
        button {cursor:pointer;}
        
        /* === Topbar === */
        .topbar {
            background: var(--card-bg); 
            color: var(--text-color); 
            display:flex; 
            align-items:center;
            justify-content:space-between; 
            padding:0 30px; 
            position:fixed; 
            top:0; 
            left:0; 
            right:0; 
            z-index:1001; 
            height: var(--topbar-height);
            box-shadow: var(--shadow);
            border-bottom: 1px solid var(--border-color);
        }
        
        .topbar-left {
            display:flex; 
            align-items:center; 
            gap:20px;
        }
        
        .hamburger {
            font-size:24px; 
            background:none; 
            border:none; 
            color: var(--primary-color); 
            cursor:pointer;
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
            display:flex; 
            align-items:center; 
            gap:20px;
            position:relative;
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
            width:42px;
            height:42px;
            border-radius:12px;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:bold; 
            cursor:pointer;
            transition: var(--transition);
            font-size: 18px;
        }
        
        .profile-pic:hover {
            transform: scale(1.05);
            box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.2);
        }
        
        .profile-name {
            color: var(--text-color);
            font-weight:600;
        }
        
        .profile-dropdown {
            position:absolute;
            top:60px;
            right:0;
            background: var(--card-bg);
            color: var(--text-color);
            border:1px solid var(--border-color);
            border-radius:12px;
            display:none;
            flex-direction:column;
            min-width:180px;
            z-index:2000;
            box-shadow: var(--shadow-hover);
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        .profile-dropdown a {
            padding:12px 16px;
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
            position:fixed;
            top: var(--topbar-height);
            left:0;
            height:100vh;
            overflow-y:auto;
            transition: transform 0.3s ease;
            z-index: 1000;
            border-right: 1px solid var(--border-color);
        }
        
        .sidebar.collapsed {
            transform:translateX(-260px);
        }
        
        .sidebar nav a {
            display:flex;
            align-items: center;
            gap: 16px;
            padding:16px 20px;
            color: var(--text-color);
            margin:8px 16px;
            border-radius:12px;
            transition: var(--transition);
        }
        
        .sidebar nav a:hover {
            background: rgba(108, 99, 255, 0.1);
            color: var(--primary-color);
        }
        
        .sidebar nav a.active {
            background: var(--primary-color);
            color: white;
            font-weight:600;
            box-shadow: 0 4px 12px rgba(108, 99, 255, 0.3);
        }
        
        /* === Main Content === */
        .main {
            margin-left: var(--sidebar-width);
            margin-top: var(--topbar-height);
            padding:30px;
            transition: margin-left 0.3s ease;
        }
        
        .main.expanded {
            margin-left:0;
        }
        
        /* === Dashboard Cards === */
        .cards {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
            gap:24px;
            margin-bottom:40px;
        }
        
        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding:28px;
            border:1px solid var(--border-color);
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
        }
        
        .card:hover {
            transform:translateY(-8px);
            box-shadow: var(--shadow-hover);
        }
        
        .card-icon {
            font-size:2.8rem;
            margin-bottom:20px;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .card h3 {
            font-size:14px;
            color: var(--text-secondary);
            margin-bottom:12px;
            font-weight:500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .card p {
            font-size:32px;
            font-weight:700;
            color: var(--primary-color);
            margin:0;
        }
        
        [data-theme="dark"] .card p {
            color: var(--primary-color);
        }
        
        /* === Table === */
        .table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding:28px;
            margin-top:30px;
            border:1px solid var(--border-color);
            box-shadow: var(--shadow);
        }
        
        .table-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:24px;
        }
        
        .table-header h3 {
            margin:0;
            color: var(--text-color);
            font-size: 1.4rem;
            font-weight: 600;
        }
        
        table {
            width:100%;
            border-collapse:collapse;
        }
        
        th,td {
            padding:16px;
            border-bottom:1px solid var(--border-color);
            text-align:left;
        }
        
        th {
            background: var(--bg-color);
            font-weight:600;
            color: var(--text-secondary);
            position: sticky;
            top: 0;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        tr {
            transition: var(--transition);
        }
        
        tr:hover {
            background: var(--bg-color);
        }
        
        .status-badge {
            padding:6px 12px;
            border-radius:20px;
            font-size:12px;
            font-weight:600;
            text-transform:capitalize;
            display:inline-block;
        }
        
        .status-preparing {background:#FFFBEB;color:#D97706;border:1px solid #FCD34D;}
        .status-ready {background:#F0FDF4;color:#16A34A;border:1px solid #BBF7D0;}
        .status-completed {background:#EFF6FF;color:#2563EB;border:1px solid #BFDBFE;}
        .status-cancelled {background:#FFF7ED;color:#EA580C;border:1px solid #FDBA74;} 
        .status-failed {background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;} 
        .status-refunded {background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE;} 
        
        .order-type-badge {
            padding:6px 12px;
            border-radius:15px;
            font-size:11px;
            font-weight:500;
            margin-left:5px;
        }
        
        .order-type-dine-in {background:#E0F2FE;color:#0369A1;border:1px solid #BAE6FD;}
        .order-type-pickup {background:#F3E8FF;color:#7C3AED;border:1px solid #E9D5FF;}
        .order-type-delivery {background:#FFEDD5;color:#EA580C;border:1px solid #FDBA74;}
        
        /* === Payment Status Badge === */
        .payment-status-badge {
            padding:6px 12px;
            border-radius:15px;
            font-size:11px;
            font-weight:500;
            display:inline-block;
            margin-left:5px;
        }
        
        .payment-pending {background:#FFFBEB;color:#D97706;border:1px solid #FCD34D;}
        .payment-completed {background:#F0FDF4;color:#16A34A;border:1px solid #BBF7D0;}
        .payment-failed {background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;}
        .payment-refunded {background:#F8FAFC;color:#475569;border:1px solid #F1F5F9;}
        
        /* === Buttons === */
        .btn {
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:12px 20px;
            border-radius:10px;
            font-weight:500;
            transition: var(--transition);
            border:none;
            cursor:pointer;
            font-size: 14px;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color:white;
        }
        
        .btn-secondary {
            background: var(--accent-color);
            color:white;
        }
        
        .btn-outline {
            background:transparent;
            color: var(--primary-color);
            border:1px solid var(--primary-color);
        }
        
        .btn-sm {
            padding:8px 14px;
            font-size:12px;
        }
        
        .btn:hover {
            transform:translateY(-2px);
            box-shadow:0 8px 15px rgba(0,0,0,0.1);
        }
        
        .btn-primary:hover {
            background: #5a52e0;
            box-shadow: 0 8px 15px rgba(108, 99, 255, 0.3);
        }
        
        .btn-secondary:hover {
            background: #2bc4d9;
        }
        
        .btn-outline:hover {
            background: var(--primary-color);
            color:white;
        }
        
        /* === Quick Stats === */
        .quick-stats {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding:28px;
            margin-top:30px;
            border:1px solid var(--border-color);
            box-shadow: var(--shadow);
        }
        
        .quick-stats h3 {
            color: var(--text-color);
            margin-bottom:24px;
            font-size: 1.4rem;
            font-weight: 600;
        }
        
        .stats-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:20px;
        }
        
        .stat-item {
            text-align:center;
            padding:24px;
            background: var(--bg-color);
            border-radius:12px;
            transition: var(--transition);
            border: 1px solid var(--border-color);
        }
        
        .stat-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }
        
        .stat-label {
            display:block;
            font-size:12px;
            color: var(--text-secondary);
            margin-bottom:8px;
            text-transform:uppercase;
            font-weight:500;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            display:block;
            font-size:24px;
            font-weight:700;
            color: var(--primary-color);
        }
        
        [data-theme="dark"] .stat-value {
            color: var(--primary-color);
        }
        
        /* === Alert === */
        .alert {
            padding:16px 20px;
            border-radius:12px;
            margin-bottom:24px;
            border-left: 4px solid;
            background: var(--card-bg);
            box-shadow: var(--shadow);
        }
        
        .alert-warning {
            background:#FFFBEB;
            color:#92400E;
            border-color: #F59E0B;
        }
        
        .alert-info {
            background:#EFF6FF;
            color:#1E40AF;
            border-color: #3B82F6;
        }
        
        .no-data {
            text-align:center;
            padding:50px;
            color: var(--text-secondary);
        }
        
        .no-data p {
            font-size: 16px;
            margin-top: 10px;
        }
        
        /* === Modal === */
        .modal {
            display:none;
            position:fixed;
            z-index:1000;
            left:0;
            top:0;
            width:100%;
            height:100%;
            background-color:rgba(0,0,0,0.5);
            overflow-y:auto;
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background: var(--card-bg);
            margin:5% auto;
            border-radius: var(--border-radius);
            max-width:600px;
            box-shadow: var(--shadow-hover);
            overflow: hidden;
            animation: modalAppear 0.3s ease-out;
        }
        
        @keyframes modalAppear {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:24px;
            border-bottom:1px solid var(--border-color);
            background: var(--primary-color);
            color: white;
        }
        
        .modal-header h3 {
            margin:0;
            color: white;
            font-size: 1.3rem;
        }
        
        .close-modal {
            background:none;
            border:none;
            font-size:28px;
            cursor:pointer;
            color: white;
            line-height:1;
            transition: var(--transition);
        }
        
        .close-modal:hover {
            color: var(--secondary-color);
        }
        
        .modal-body {
            padding:24px;
        }
        
        .order-detail-row {
            display:flex;
            justify-content:space-between;
            padding:12px 0;
            border-bottom:1px solid var(--border-color);
        }
        
        .order-detail-label {
            font-weight:600;
            color: var(--text-secondary);
        }
        
        .order-detail-value {
            color: var(--text-color);
            font-weight: 500;
        }
        
        .order-items-list {
            margin-top:24px;
        }
        
        .order-item {
            display:flex;
            gap:16px;
            padding:16px;
            background: var(--bg-color);
            border-radius:10px;
            margin-bottom:12px;
            border: 1px solid var(--border-color);
        }
        
        .order-item-image {
            width:70px;
            height:70px;
            border-radius:10px;
            object-fit:cover;
            background: var(--border-color);
        }
        
        .order-item-details {
            flex:1;
        }
        
        .order-item-name {
            font-weight:600;
            color: var(--text-color);
            margin-bottom:8px;
            font-size: 1rem;
        }
        
        .order-item-price {
            color: var(--text-secondary);
            font-size:14px;
        }
        
        .order-total {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color:#fff;
            padding:20px;
            border-radius:12px;
            margin-top:24px;
            text-align:center;
        }
        
        .order-total-label {
            font-size:14px;
            opacity:0.9;
            margin-bottom:8px;
        }
        
        .order-total-amount {
            font-size:28px;
            font-weight:bold;
        }
        
        /* === Responsive === */
        @media(max-width:968px){
            .cards {grid-template-columns:repeat(2, 1fr);}
        }
        
        @media(max-width:768px){
            .main {margin-left:0; padding: 20px;}
            .sidebar {transform:translateX(-260px);}
            .cards {grid-template-columns:1fr;}
            .table-header {flex-direction:column;gap:16px;align-items:flex-start;}
            .btn {width:100%;justify-content:center;}
            table {font-size:14px;}
            th,td {padding:12px;}
            .topbar {padding: 0 20px;}
        }
        
        @media(max-width:480px){
            .stats-grid {grid-template-columns:1fr;}
            .topbar-right .profile-name {display: none;}
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
            <a href="dashboard.php" class="active">📊 Dashboard</a>
            <a href="orders.php">🧾 Orders</a>
            <a href="menus.php">🍽 Menu</a>
            <a href="payments.php">💰 Payments</a>
            <a href="settings.php">⚙️ Settings</a>
        </nav>
    </aside>
    <!-- MAIN CONTENT -->
    <main class="main" id="mainContent">
        <?php if (!$restaurantId): ?>
            <div class="alert alert-warning">
                <strong>⚠️ No Restaurant Assigned</strong><br>
                You don't have a restaurant assigned to your account. Please contact the system administrator.
            </div>
        <?php else: ?>
            <!-- Dashboard cards -->
            <div class="cards">
                <div class="card">
                    <div class="card-icon">📦</div>
                    <h3>Orders Today</h3>
                    <p><?= htmlspecialchars($ordersToday) ?></p>
                </div>
                <div class="card">
                    <div class="card-icon">💰</div>
                    <h3>Revenue Today</h3>
                    <p>₱<?= number_format($revenueToday, 2) ?></p>
                </div>
                <div class="card">
                    <div class="card-icon">🍽️</div>
                    <h3>Active Menu Items</h3>
                    <p><?= htmlspecialchars($activeMenuItems) ?></p>
                </div>
                <div class="card">
                    <div class="card-icon">⏳</div>
                    <h3>Preparing Orders</h3>
                    <p><?= htmlspecialchars($preparingOrders) ?></p>
                </div>
            </div>
            <!-- Recent Orders Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Recent Orders (Today & Yesterday)</h3>
                    <a href="orders.php" class="btn btn-outline">View All Orders</a>
                </div>
                
                <?php if (empty($recentOrders)): ?>
                    <div class="no-data">
                        <p>📭 No recent orders found.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Order Number</th>
                                <th>Customer</th>
                                <th>Total</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td>#<?= htmlspecialchars($order['order_number']) ?></td>
                                    <td><?= htmlspecialchars($order['customer']) ?></td>
                                    <td>₱<?= number_format($order['total'], 2) ?></td>
                                    <td>
                                        <span class="order-type-badge order-type-<?= strtolower($order['order_type']) ?>">
                                            <?= htmlspecialchars($order['order_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower($order['status']) ?>">
                                            <?= htmlspecialchars($order['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M d, Y h:i A', strtotime($order['order_date'])) ?></td>
                                    <td>
                                        <button onclick="viewOrderDetails(<?= $order['id'] ?>)" class="btn btn-sm btn-primary">👁️ View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <!-- Quick Stats Section -->
            <div class="quick-stats">
                <h3>Quick Overview</h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-label">Total Orders This Week</span>
                        <span class="stat-value"><?= htmlspecialchars($weeklyOrders) ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Average Order Value Today</span>
                        <span class="stat-value">₱<?= number_format($avgOrderValue, 2) ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Ready Orders</span>
                        <span class="stat-value">
                            <?php
                            try {
                                $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status = 'ready'");
                                $stmt->bind_param("i", $restaurantId);
                                $stmt->execute();
                                $stmt->bind_result($readyOrders);
                                $stmt->fetch();
                                $stmt->close();
                                echo htmlspecialchars($readyOrders);
                            } catch (Exception $e) {
                                echo '0';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Completed Orders Today</span>
                        <span class="stat-value">
                            <?php
                            try {
                                $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE restaurant_id = ? AND status = 'completed' AND DATE(order_date) = CURDATE()");
                                $stmt->bind_param("i", $restaurantId);
                                $stmt->execute();
                                $stmt->bind_result($completedToday);
                                $stmt->fetch();
                                $stmt->close();
                                echo htmlspecialchars($completedToday);
                            } catch (Exception $e) {
                                echo '0';
                            }
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
    
    <!-- ORDER DETAILS MODAL -->
    <div id="orderDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalOrderTitle">Order Details</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalOrderBody">
                <p style="text-align:center;color:var(--text-secondary);">Loading...</p>
            </div>
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
        
        // Profile dropdown toggle
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');
        const modal = document.getElementById('orderDetailsModal');
        
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
        
        // View order details
        function viewOrderDetails(orderId) {
            modal.style.display = 'block';
            document.getElementById('modalOrderBody').innerHTML = '<p style="text-align:center;color:var(--text-secondary);">Loading...</p>';
            
            const formData = new FormData();
            formData.append('action', 'get_order_details');
            formData.append('order_id', orderId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayOrderDetails(data.order);
                } else {
                    document.getElementById('modalOrderBody').innerHTML = `<p style="text-align:center;color:#DC2626;">${data.message}</p>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('modalOrderBody').innerHTML = '<p style="text-align:center;color:#DC2626;">Failed to load order details</p>';
            });
        }
        
        // Display order details in modal
        function displayOrderDetails(order) {
            document.getElementById('modalOrderTitle').textContent = `Order #${order.order_number}`;
            
            const statusClass = `status-${order.status}`;
            const orderTypeClass = `order-type-${order.order_type.toLowerCase()}`;
            
            // Payment status badge
            let paymentStatusHTML = '';
            if (order.payment_method && order.payment_status) {
                const paymentStatusClass = `payment-${order.payment_status.toLowerCase()}`;
                paymentStatusHTML = `
                    <div class="order-detail-row">
                        <span class="order-detail-label">Payment Method:</span>
                        <span class="order-detail-value">${order.payment_method}</span>
                    </div>
                    <div class="order-detail-row">
                        <span class="order-detail-label">Payment Status:</span>
                        <span class="order-detail-value">
                            <span class="payment-status-badge ${paymentStatusClass}">${order.payment_status}</span>
                        </span>
                    </div>
                `;
            }
            
            let itemsHTML = '';
            if (order.items && order.items.length > 0) {
                itemsHTML = '<div class="order-items-list"><h4 style="color:var(--text-color);margin-bottom:15px;">Order Items:</h4>';
                order.items.forEach(item => {
                    // FIXED: Use product_image_url from order_items OR product_image from products table
                    const imageUrl = item.product_image_url || item.product_image;
                    const imageHtml = imageUrl ? 
                        `<img src="${imageUrl}" alt="${item.item_name}" class="order-item-image">` :
                        `<div class="order-item-image" style="display:flex;align-items:center;justify-content:center;color:var(--text-secondary);">🍽️</div>`;
                    
                    itemsHTML += `
                        <div class="order-item">
                            ${imageHtml}
                            <div class="order-item-details">
                                <div class="order-item-name">${item.item_name}</div>
                                <div class="order-item-price">
                                    Quantity: ${item.quantity} × ₱${parseFloat(item.unit_price).toFixed(2)} = ₱${parseFloat(item.total_price).toFixed(2)}
                                </div>
                            </div>
                        </div>
                    `;
                });
                itemsHTML += '</div>';
            }
            
            document.getElementById('modalOrderBody').innerHTML = `
                <div class="order-detail-row">
                    <span class="order-detail-label">Customer:</span>
                    <span class="order-detail-value">${order.customer_name}</span>
                </div>
                <div class="order-detail-row">
                    <span class="order-detail-label">Email:</span>
                    <span class="order-detail-value">${order.customer_email}</span>
                </div>
                <div class="order-detail-row">
                    <span class="order-detail-label">Order Type:</span>
                    <span class="order-detail-value">
                        <span class="order-type-badge ${orderTypeClass}">${order.order_type}</span>
                    </span>
                </div>
                <div class="order-detail-row">
                    <span class="order-detail-label">Status:</span>
                    <span class="order-detail-value">
                        <span class="status-badge ${statusClass}">${order.status}</span>
                    </span>
                </div>
                ${paymentStatusHTML}
                <div class="order-detail-row">
                    <span class="order-detail-label">Order Date:</span>
                    <span class="order-detail-value">${new Date(order.order_date).toLocaleString()}</span>
                </div>
                ${itemsHTML}
                <div class="order-total">
                    <div class="order-total-label">Total Amount</div>
                    <div class="order-total-amount">₱${parseFloat(order.total_amount).toFixed(2)}</div>
                </div>
            `;
        }
        
        // Close modal
        function closeModal() {
            modal.style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(e) {
            if (e.target === modal) {
                closeModal();
            }
        };
    </script>
</body>
</html>