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

$currentPage = 'orders';
$pageTitle = 'Orders Management - Branch Admin';

// Admin info
$adminName = $_SESSION['name'] ?? "Branch Admin";
$firstLetter = strtoupper(substr($adminName, 0, 1));
$adminId = $_SESSION['user_id'] ?? 0;

// Get the restaurant owned by this branch admin
$restaurantId = null;
try {
    $stmt = $conn->prepare("SELECT id, name FROM restaurants WHERE owner_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $restaurantId = $row['id'];
        $restaurantName = $row['name'];
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching restaurant: " . $e->getMessage());
}

// Handle status updates via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_status' && $restaurantId) {
        $orderId = intval($_POST['order_id']);
        $newStatus = $_POST['status'];
        
        // Validate status - now including both 'cancelled' and 'failed'
        $validStatuses = ['preparing', 'ready', 'completed', 'cancelled', 'failed', 'refunded'];
        if (!in_array($newStatus, $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit();
        }
        
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Verify order belongs to this restaurant and update status
            $stmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND restaurant_id = ?");
            $stmt->bind_param("sii", $newStatus, $orderId, $restaurantId);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $stmt->close();
                
                // Get payment info for this order
                $stmt = $conn->prepare("
                    SELECT p.payment_id, p.payment_method, p.payment_status 
                    FROM payments p 
                    INNER JOIN orders o ON p.order_id = o.id 
                    WHERE o.id = ? AND o.restaurant_id = ?
                ");
                $stmt->bind_param("ii", $orderId, $restaurantId);
                $stmt->execute();
                $result = $stmt->get_result();
                $payment = $result->fetch_assoc();
                $stmt->close();
                
                // Update payment status based on order status
                if ($payment) {
                    $paymentId = $payment['payment_id'];
                    $newPaymentStatus = null;
                    
                    // If order status is 'completed', mark payment as Completed (for Cash payments)
                    if ($newStatus === 'completed' && $payment['payment_method'] === 'Cash' && $payment['payment_status'] !== 'Completed') {
                        $newPaymentStatus = 'Completed';
                    }
                    // If order status is 'cancelled', mark payment as Cancelled
                    elseif ($newStatus === 'cancelled') {
                        $newPaymentStatus = 'Cancelled';
                    }
                    // If order status is 'failed', mark payment as Failed
                    elseif ($newStatus === 'failed') {
                        $newPaymentStatus = 'Failed';
                    }
                    // If order status is 'refunded', mark payment as Refunded
                    elseif ($newStatus === 'refunded') {
                        $newPaymentStatus = 'Refunded';
                    }
                    
                    // Update payment status if needed
                    if ($newPaymentStatus !== null) {
                        $stmt = $conn->prepare("UPDATE payments SET payment_status = ? WHERE payment_id = ?");
                        $stmt->bind_param("si", $newPaymentStatus, $paymentId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                echo json_encode(['success' => true, 'message' => 'Order status updated successfully']);
            } else {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Order not found or no changes made']);
            }
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'get_order_details' && $restaurantId) {
        $orderId = intval($_POST['order_id']);
        
        try {
            // FIXED: Corrected the query to properly fetch product images
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
                // Get order items with product images - FIXED: Properly fetch product images
                $stmt = $conn->prepare("
                    SELECT 
                        oi.item_name,
                        oi.quantity,
                        oi.unit_price,
                        oi.total_price,
                        oi.product_image_url as order_item_image,
                        p.product_image_url as product_image,
                        r.name as restaurant_name
                    FROM order_items oi
                    LEFT JOIN products p ON oi.product_id = p.id
                    LEFT JOIN restaurants r ON p.restaurant_id = r.id
                    WHERE oi.order_id = ?
                ");
                $stmt->bind_param("i", $orderId);
                $stmt->execute();
                $result = $stmt->get_result();
                $orderItems = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                // Process image URLs to ensure they're correct
                foreach ($orderItems as &$item) {
                    // Priority: order_item_image > product_image > default
                    if (!empty($item['order_item_image'])) {
                        $item['display_image'] = $item['order_item_image'];
                    } elseif (!empty($item['product_image'])) {
                        $item['display_image'] = $item['product_image'];
                    } else {
                        $item['display_image'] = null;
                    }
                    
                    // If we have a restaurant name and product image, construct proper path
                    if (!empty($item['restaurant_name']) && !empty($item['product_image'])) {
                        $cleanRestaurantName = preg_replace('/[^a-zA-Z0-9_]/', '_', $item['restaurant_name']);
                        $item['display_image'] = 'uploads/menus/' . $cleanRestaurantName . '/' . $item['product_image'];
                    }
                }
                
                $orderData['items'] = $orderItems;
                echo json_encode(['success' => true, 'order' => $orderData]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Order not found or does not belong to your restaurant']);
            }
        } catch (Exception $e) {
            error_log("Order details error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
}

// Determine filter status
$filterStatus = $_GET['filter'] ?? 'all';

// Initialize orders array
$orders = [];

if ($restaurantId) {
    // Build SQL query with appropriate filtering
    $sql = "
        SELECT 
            o.id,
            o.order_number,
            o.total_amount,
            o.status,
            o.order_type,
            o.order_date,
            o.created_at,
            u.name as customer_name,
            COUNT(oi.id) as item_count
        FROM orders o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.restaurant_id = ?
    ";
    
    $params = [$restaurantId];
    $types = "i";
    
    // Apply status filter - UPDATED to include 'cancelled' and 'failed' as separate filters
    if ($filterStatus === 'preparing') {
        $sql .= " AND o.status = 'preparing'";
    } elseif ($filterStatus === 'ready') {
        $sql .= " AND o.status = 'ready'";
    } elseif ($filterStatus === 'completed') {
        $sql .= " AND o.status = 'completed'";
    } elseif ($filterStatus === 'cancelled') {
        $sql .= " AND o.status = 'cancelled'";
    } elseif ($filterStatus === 'failed') {
        $sql .= " AND o.status = 'failed'";
    } elseif ($filterStatus === 'refunded') {
        $sql .= " AND o.status = 'refunded'";
    }
    // 'all' shows everything
    
    $sql .= " GROUP BY o.id ORDER BY o.order_date DESC";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching orders: " . $e->getMessage());
    }
}

// Get order counts for filter badges - UPDATED to include 'cancelled' and 'failed'
$orderCounts = [
    'all' => 0,
    'preparing' => 0,
    'ready' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'failed' => 0,
    'refunded' => 0
];

if ($restaurantId) {
    try {
        $stmt = $conn->prepare("
            SELECT status, COUNT(*) as count 
            FROM orders 
            WHERE restaurant_id = ? 
            GROUP BY status
        ");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $orderCounts[$row['status']] = $row['count'];
            $orderCounts['all'] += $row['count'];
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching order counts: " . $e->getMessage());
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
        
        /* === Main === */
        .main {
            margin-left: var(--sidebar-width);
            margin-top: var(--topbar-height);
            padding:30px;
            transition: margin-left 0.3s ease;
        }
        
        .main.expanded {
            margin-left:0;
        }
        
        /* === Alerts === */
        .alert {
            padding:16px 20px;
            border-radius:12px;
            margin-bottom:24px;
            border-left: 4px solid;
            background: var(--card-bg);
            box-shadow: var(--shadow);
        }
        
        .alert-success {
            background:#F0FDF4;
            color:#166534;
            border-color: #22C55E;
        }
        
        .alert-error {
            background:#FEF2F2;
            color:#991B1B;
            border-color: #EF4444;
        }
        
        .alert-warning {
            background:#FFFBEB;
            color:#92400E;
            border-color: #F59E0B;
        }
        
        /* === Filter Tabs === */
        .filter-tabs {
            display:flex;
            gap:12px;
            margin-bottom:24px;
            flex-wrap:wrap;
        }
        
        .filter-tab {
            padding:12px 24px;
            background: var(--card-bg);
            border:2px solid var(--border-color);
            border-radius:12px;
            cursor:pointer;
            transition: var(--transition);
            display:flex;
            align-items:center;
            gap:10px;
            font-weight:500;
            color: var(--text-color);
        }
        
        .filter-tab:hover {
            background: var(--bg-color);
            transform: translateY(-2px);
        }
        
        .filter-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(108, 99, 255, 0.3);
        }
        
        .filter-badge {
            background: var(--secondary-color);
            color: white;
            padding:4px 10px;
            border-radius:12px;
            font-size:12px;
            font-weight:600;
        }
        
        .filter-tab.active .filter-badge {
            background: white;
            color: var(--primary-color);
        }
        
        /* === Table === */
        .table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding:28px;
            margin-top:20px;
            border:1px solid var(--border-color);
            box-shadow: var(--shadow);
        }
        
        .table-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:24px;
        }
        
        .table-header h2 {
            color: var(--text-color);
            margin:0;
            font-size: 1.5rem;
            font-weight: 700;
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
            color: var(--text-secondary);
            font-weight:600;
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
        
        /* === Status Select === */
        .status-select {
            padding:8px 14px;
            border-radius:20px;
            border:none;
            font-weight:600;
            font-size:12px;
            cursor:pointer;
            transition: var(--transition);
            background: var(--bg-color);
            color: var(--text-color);
        }
        
        .status-select:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.2);
        }
        
        .status-preparing {background:#FFFBEB;color:#D97706;border:1px solid #FCD34D;}
        .status-ready {background:#F0FDF4;color:#16A34A;border:1px solid #BBF7D0;}
        .status-completed {background:#EFF6FF;color:#2563EB;border:1px solid #BFDBFE;}
        .status-cancelled {background:#FFF7ED;color:#EA580C;border:1px solid #FDBA74;} 
        .status-failed {background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;} 
        .status-refunded {background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE;} 
        
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
        .payment-cancelled {background:#FFF7ED;color:#EA580C;border:1px solid #FDBA74;} /* Added for cancelled */
        .payment-failed {background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;}
        .payment-refunded {background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE;} /* ADDED: Refunded payment status */
        
        /* === Order Type Badge === */
        .order-type-badge {
            padding:6px 12px;
            border-radius:15px;
            font-size:11px;
            font-weight:500;
            display:inline-block;
        }
        
        .order-type-dine-in {background:#E0F2FE;color:#0369A1;border:1px solid #BAE6FD;}
        .order-type-pickup {background:#F3E8FF;color:#7C3AED;border:1px solid #E9D5FF;}
        .order-type-delivery {background:#FFEDD5;color:#EA580C;border:1px solid #FDBA74;}

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
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .order-item-image img {
            width: 100%;
            height: 100%;
            border-radius: 10px;
            object-fit: cover;
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
            .filter-tabs {justify-content: center;}
        }
        
        @media(max-width:768px){
            .main {margin-left:0; padding: 20px;}
            .sidebar {transform:translateX(-260px);}
            .filter-tabs {flex-direction:column; align-items: stretch;}
            .filter-tab {justify-content:center;}
            table {font-size:14px;}
            th,td {padding:12px;}
            .topbar {padding: 0 20px;}
            .table-header {flex-direction:column;gap:16px;align-items:flex-start;}
        }
        
        @media(max-width:480px){
            .topbar-right .profile-name {display: none;}
            .filter-tabs {gap: 8px;}
            .filter-tab {padding: 10px 16px;}
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
            <a href="orders.php" class="active">🧾 Orders</a>
            <a href="menus.php">🍽 Menu</a>
            <a href="payments.php">💰 Payments</a>
            <a href="settings.php">⚙️ Settings</a>
        </nav>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main" id="mainContent">
        <div id="alertContainer"></div>

        <?php if (!$restaurantId): ?>
            <div class="alert alert-warning">
                <strong>⚠️ No Restaurant Assigned</strong><br>
                You don't have a restaurant assigned to your account. Please contact the system administrator.
            </div>
        <?php else: ?>
            <div class="table-header">
                <h2>Orders Management - <?= htmlspecialchars($restaurantName) ?></h2>
            </div>

            <!-- Filter Tabs - UPDATED to include separate Cancelled and Failed tabs -->
            <div class="filter-tabs">
                <a href="orders.php?filter=all" class="filter-tab <?= $filterStatus === 'all' ? 'active' : '' ?>">
                    📋 All Orders
                    <?php if ($orderCounts['all'] > 0): ?>
                        <span class="filter-badge"><?= $orderCounts['all'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="orders.php?filter=preparing" class="filter-tab <?= $filterStatus === 'preparing' ? 'active' : '' ?>">
                    ⏳ Preparing
                    <?php if ($orderCounts['preparing'] > 0): ?>
                        <span class="filter-badge"><?= $orderCounts['preparing'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="orders.php?filter=ready" class="filter-tab <?= $filterStatus === 'ready' ? 'active' : '' ?>">
                    ✅ Ready
                    <?php if ($orderCounts['ready'] > 0): ?>
                        <span class="filter-badge"><?= $orderCounts['ready'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="orders.php?filter=completed" class="filter-tab <?= $filterStatus === 'completed' ? 'active' : '' ?>">
                    ✔️ Completed
                    <?php if ($orderCounts['completed'] > 0): ?>
                        <span class="filter-badge"><?= $orderCounts['completed'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="orders.php?filter=cancelled" class="filter-tab <?= $filterStatus === 'cancelled' ? 'active' : '' ?>">
                    🚫 Cancelled
                    <?php if ($orderCounts['cancelled'] > 0): ?>
                        <span class="filter-badge"><?= $orderCounts['cancelled'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="orders.php?filter=failed" class="filter-tab <?= $filterStatus === 'failed' ? 'active' : '' ?>">
                    ⚠️ Failed
                    <?php if ($orderCounts['failed'] > 0): ?>
                        <span class="filter-badge"><?= $orderCounts['failed'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="orders.php?filter=refunded" class="filter-tab <?= $filterStatus === 'refunded' ? 'active' : '' ?>">
                    💸 Refunded
                    <?php if ($orderCounts['refunded'] > 0): ?>
                        <span class="filter-badge"><?= $orderCounts['refunded'] ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <div class="table-container">
                <?php if (empty($orders)): ?>
                    <div class="no-data">
                        <p>📭 No orders found for the selected filter.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><strong>#<?= htmlspecialchars($order['order_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                    <td><?= htmlspecialchars($order['item_count']) ?> item(s)</td>
                                    <td><strong>₱<?= number_format($order['total_amount'], 2) ?></strong></td>
                                    <td>
                                        <span class="order-type-badge order-type-<?= strtolower($order['order_type']) ?>">
                                            <?= htmlspecialchars($order['order_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M d, Y h:i A', strtotime($order['order_date'])) ?></td>
                                    <td>
                                        <select 
                                            class="status-select status-<?= strtolower($order['status']) ?>" 
                                            onchange="updateOrderStatus(<?= $order['id'] ?>, this.value)"
                                            data-order-id="<?= $order['id'] ?>">
                                            <option value="preparing" <?= $order['status'] === 'preparing' ? 'selected' : '' ?>>Preparing</option>
                                            <option value="ready" <?= $order['status'] === 'ready' ? 'selected' : '' ?>>Ready</option>
                                            <option value="completed" <?= $order['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                            <option value="cancelled" <?= $order['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                            <option value="failed" <?= $order['status'] === 'failed' ? 'selected' : '' ?>>Failed</option>
                                            <option value="refunded" <?= $order['status'] === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                                        </select>
                                    </td>
                                    <td>
                                        <button onclick="viewOrderDetails(<?= $order['id'] ?>)" class="btn btn-primary btn-sm">
                                            👁️ View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
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

        // UI Elements
        const menuBtn = document.getElementById('menuBtn');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');
        const modal = document.getElementById('orderDetailsModal');

        // Sidebar toggle
        menuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        });

        // Profile dropdown toggle
        profileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.style.display = profileDropdown.style.display === 'flex' ? 'none' : 'flex';
        });

        document.addEventListener('click', (e) => {
            if (!profileToggle.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.style.display = 'none';
            }
        });

        // Show alert notification
        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.textContent = message;
            alertContainer.appendChild(alert);

            setTimeout(() => {
                alert.remove();
            }, 3000);
        }

        // Get status display name
        function getStatusDisplayName(status) {
            const statusMap = {
                'preparing': 'Preparing',
                'ready': 'Ready',
                'completed': 'Completed',
                'cancelled': 'Cancelled',
                'failed': 'Failed',
                'refunded': 'Refunded'
            };
            return statusMap[status] || status;
        }

        // Update order status
        function updateOrderStatus(orderId, newStatus) {
            const select = document.querySelector(`select[data-order-id="${orderId}"]`);
            const originalStatus = select.dataset.originalStatus || select.value;
            
            let confirmMessage = `Are you sure you want to change this order status to "${getStatusDisplayName(newStatus)}"?`;
            
            // Special messages based on status
            if (newStatus === 'completed') {
                confirmMessage += '\n\nNote: If this is a Cash payment, the payment status will also be marked as Completed.';
            } else if (newStatus === 'cancelled') {
                confirmMessage += '\n\nNote: The payment status will be automatically marked as Cancelled.';
            } else if (newStatus === 'failed') {
                confirmMessage += '\n\nNote: The payment status will be automatically marked as Failed.';
            } else if (newStatus === 'refunded') {
                confirmMessage += '\n\nNote: The payment status will be automatically marked as Refunded.';
            }
            
            if (!confirm(confirmMessage)) {
                select.value = originalStatus;
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('order_id', orderId);
            formData.append('status', newStatus);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    select.dataset.originalStatus = newStatus;
                    
                    // Update select styling
                    select.className = `status-select status-${newStatus}`;
                    
                    // Reload page after 1 second to update counts
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showAlert(data.message, 'error');
                    select.value = originalStatus;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while updating the order status', 'error');
                select.value = originalStatus;
            });
        }

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
            
            // Get display name for status
            const statusDisplayName = getStatusDisplayName(order.status);
            
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
                    // Use the processed display_image from backend
                    const imageUrl = item.display_image;
                    const imageHTML = imageUrl ? 
                        `<img src="${imageUrl}" alt="${item.item_name}" onerror="this.style.display='none'; this.parentNode.innerHTML='🍽️';">` : 
                        '🍽️';
                    
                    itemsHTML += `
                        <div class="order-item">
                            <div class="order-item-image">
                                ${imageHTML}
                            </div>
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
                        <span class="status-select ${statusClass}" style="display:inline-block;">${statusDisplayName}</span>
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

        // Store original status for all selects on page load
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.status-select').forEach(select => {
                select.dataset.originalStatus = select.value;
            });
        });
    </script>
</body>
</html>