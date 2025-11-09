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

$currentPage = 'payments';
$pageTitle = 'Payments - Branch Admin';

// Admin info
$adminName = $_SESSION['name'] ?? "Branch Admin";
$firstLetter = strtoupper(substr($adminName, 0, 1));
$adminId = $_SESSION['user_id'] ?? 0;

// Get the restaurant owned by this branch admin
$restaurantId = null;
$restaurantName = '';
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

// Initialize variables
$payments = [];
$totalRevenue = 0;
$pendingAmount = 0;
$completedCount = 0;
$pendingCount = 0;
$failedCount = 0;
$cancelledCount = 0;
$refundedCount = 0;

// Fetch payments for this restaurant
if ($restaurantId) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                p.payment_id, 
                p.order_id,
                o.order_number,
                u.name as customer_name,
                u.email as customer_email,
                p.amount, 
                p.payment_method, 
                p.payment_status,
                p.created_at,
                o.order_type,
                o.status as order_status
            FROM payments p
            INNER JOIN orders o ON p.order_id = o.id
            INNER JOIN users u ON o.user_id = u.id
            WHERE o.restaurant_id = ?
            ORDER BY p.created_at DESC
        ");
        $stmt->bind_param("i", $restaurantId);
        $stmt->execute();
        $result = $stmt->get_result();
        $payments = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Calculate totals and counts
        foreach ($payments as $payment) {
            $status = strtolower($payment['payment_status']);
            if ($status === 'completed') {
                $totalRevenue += $payment['amount'];
                $completedCount++;
            } elseif ($status === 'pending') {
                $pendingAmount += $payment['amount'];
                $pendingCount++;
            } elseif ($status === 'failed') {
                $failedCount++;
            } elseif ($status === 'cancelled') {
                $cancelledCount++;
            } elseif ($status === 'refunded') {
                $refundedCount++;
            }
        }
    } catch (Exception $e) {
        error_log("Payments query failed: " . $e->getMessage());
    }
}

// Get filter parameter
$filterStatus = $_GET['filter'] ?? 'all';

// Filter payments based on status
$filteredPayments = $payments;
if ($filterStatus !== 'all') {
    $filteredPayments = array_filter($payments, function($payment) use ($filterStatus) {
        return strtolower($payment['payment_status']) === strtolower($filterStatus);
    });
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

        /* === Main === */
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

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 1rem;
        }

        /* === Alert === */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            border-left: 4px solid;
            background: var(--card-bg);
            box-shadow: var(--shadow);
        }

        .alert-warning {
            background: #FFFBEB;
            color: #92400E;
            border-color: #F59E0B;
        }

        /* === Cards === */
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 28px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
            text-align: center;
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
        }

        .card-success::before {
            background: linear-gradient(90deg, #10B981, #34D399);
        }

        .card-warning::before {
            background: linear-gradient(90deg, #F59E0B, #FBBF24);
        }

        .card-info::before {
            background: linear-gradient(90deg, #3B82F6, #60A5FA);
        }

        .card-danger::before {
            background: linear-gradient(90deg, #EF4444, #F87171);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .card-icon {
            font-size: 2.5rem;
            margin-bottom: 16px;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .card h3 {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card p {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-color);
            margin: 0;
        }

        .card small {
            color: var(--text-secondary);
            font-size: 12px;
            margin-top: 8px;
            display: block;
        }

        /* === Filter Tabs === */
        .filter-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 12px 24px;
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
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
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .filter-tab.active .filter-badge {
            background: white;
            color: var(--primary-color);
        }

        /* === Table === */
        .table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 28px;
            margin-top: 20px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .table-header h3 {
            color: var(--text-color);
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }

        th {
            background: var(--bg-color);
            color: var(--text-secondary);
            font-weight: 600;
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

        /* === Status Badges === */
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
            display: inline-block;
            border: 1px solid;
        }

        /* Payment Status Badges - Matching order status colors from reference image */
        .status-completed {
            background: #F0FDF4;
            color: #166534;
            border-color: #BBF7D0;
        }

        .status-preparing {
            background: #FFFBEB;
            color: #92400E;
            border-color: #FCD34D;
        }

        .status-ready {
            background: #E0F2FE;
            color: #0369A1;
            border-color: #BAE6FD;
        }

        .status-pending {
            background: #FFFBEB;
            color: #92400E;
            border-color: #FCD34D;
        }

        .status-failed {
            background: #FEF2F2;
            color: #991B1B;
            border-color: #FECACA;
        }

        .status-cancelled {
            background: #FEF2F2;
            color: #EA580C;
            border-color: #FDBA74;
        }

        .status-refunded {
            background: #EFF6FF;
            color: #1E40AF;
            border-color: #BFDBFE;
        }

        .status-controlled {
            background: #F3E8FF;
            color: #6B21A8;
            border-color: #E9D5FF;
        }

        /* === Payment Method Badge === */
        .payment-method-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
            border: 1px solid;
        }

        .method-cash {
            background: #F0FDF4;
            color: #166534;
            border-color: #BBF7D0;
        }

        .method-card {
            background: #EFF6FF;
            color: #1E40AF;
            border-color: #BFDBFE;
        }

        .method-online {
            background: #F3E8FF;
            color: #6B21A8;
            border-color: #E9D5FF;
        }

        /* === Order Type Badge === */
        .order-type-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
            border: 1px solid;
        }

        .order-type-dine-in {
            background: #E0F2FE;
            color: #0369A1;
            border-color: #BAE6FD;
        }

        .order-type-pickup {
            background: #F3E8FF;
            color: #7C3AED;
            border-color: #E9D5FF;
        }

        .order-type-delivery {
            background: #FFEDD5;
            color: #EA580C;
            border-color: #FDBA74;
        }

        .customer-info {
            display: flex;
            flex-direction: column;
        }

        .customer-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .customer-email {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-top: 2px;
        }

        .amount-display {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .no-data {
            text-align: center;
            padding: 50px;
            color: var(--text-secondary);
        }

        .no-data p {
            font-size: 16px;
            margin-top: 10px;
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

        /* === Responsive === */
        @media (max-width: 968px) {
            .filter-tabs {
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .main {
                margin-left: 0;
                padding: 20px;
            }
            
            .sidebar {
                transform: translateX(-260px);
            }
            
            .cards {
                grid-template-columns: 1fr;
            }
            
            .filter-tabs {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-tab {
                justify-content: center;
            }
            
            table {
                font-size: 14px;
            }
            
            th, td {
                padding: 12px;
            }
            
            .topbar {
                padding: 0 20px;
            }
            
            .topbar-right .profile-name {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .filter-tabs {
                gap: 8px;
            }
            
            .filter-tab {
                padding: 10px 16px;
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
            <a href="payments.php" class="active">💰 Payments</a>
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
            <div class="page-header">
                <h1 class="page-title">Payments - <?= htmlspecialchars($restaurantName) ?></h1>
                <p class="page-subtitle">Track and manage payment transactions</p>
            </div>

            <!-- Summary Cards -->
            <div class="cards">
                <div class="card card-success">
                    <div class="card-icon">💵</div>
                    <h3>Total Revenue</h3>
                    <p>₱<?= number_format($totalRevenue, 2) ?></p>
                    <small><?= $completedCount ?> completed transactions</small>
                </div>
                <div class="card card-warning">
                    <div class="card-icon">⏳</div>
                    <h3>Pending Payments</h3>
                    <p>₱<?= number_format($pendingAmount, 2) ?></p>
                    <small><?= $pendingCount ?> pending transactions</small>
                </div>
                <div class="card card-info">
                    <div class="card-icon">📊</div>
                    <h3>Total Transactions</h3>
                    <p><?= count($payments) ?></p>
                    <small>All payment records</small>
                </div>
                <div class="card card-danger">
                    <div class="card-icon">❌</div>
                    <h3>Failed & Cancelled</h3>
                    <p><?= $failedCount + $cancelledCount ?></p>
                    <small>Requires attention</small>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="payments.php?filter=all" class="filter-tab <?= $filterStatus === 'all' ? 'active' : '' ?>">
                    📋 All Payments
                    <span class="filter-badge"><?= count($payments) ?></span>
                </a>
                <a href="payments.php?filter=completed" class="filter-tab <?= $filterStatus === 'completed' ? 'active' : '' ?>">
                    ✅ Completed
                    <span class="filter-badge"><?= $completedCount ?></span>
                </a>
                <a href="payments.php?filter=pending" class="filter-tab <?= $filterStatus === 'pending' ? 'active' : '' ?>">
                    ⏳ Pending
                    <span class="filter-badge"><?= $pendingCount ?></span>
                </a>
                <a href="payments.php?filter=failed" class="filter-tab <?= $filterStatus === 'failed' ? 'active' : '' ?>">
                    ❌ Failed
                    <span class="filter-badge"><?= $failedCount ?></span>
                </a>
                <a href="payments.php?filter=cancelled" class="filter-tab <?= $filterStatus === 'cancelled' ? 'active' : '' ?>">
                    🚫 Cancelled
                    <span class="filter-badge"><?= $cancelledCount ?></span>
                </a>
                <a href="payments.php?filter=refunded" class="filter-tab <?= $filterStatus === 'refunded' ? 'active' : '' ?>">
                    💸 Refunded
                    <span class="filter-badge"><?= $refundedCount ?></span>
                </a>
            </div>

            <!-- Payments Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Payment History</h3>
                </div>
                
                <?php if (empty($filteredPayments)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">💰</div>
                        <div class="empty-state-title">No payment records found</div>
                        <div class="empty-state-description">
                            No payment records match the selected filter criteria.
                        </div>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Order Type</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filteredPayments as $payment): ?>
                                <tr>
                                    <td><strong>#<?= htmlspecialchars($payment['payment_id']) ?></strong></td>
                                    <td>#<?= htmlspecialchars($payment['order_number']) ?></td>
                                    <td>
                                        <div class="customer-info">
                                            <span class="customer-name"><?= htmlspecialchars($payment['customer_name']) ?></span>
                                            <span class="customer-email"><?= htmlspecialchars($payment['customer_email']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="amount-display">₱<?= number_format($payment['amount'], 2) ?></span>
                                    </td>
                                    <td>
                                        <span class="payment-method-badge method-<?= strtolower($payment['payment_method']) ?>">
                                            <?= htmlspecialchars($payment['payment_method']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="order-type-badge order-type-<?= strtolower($payment['order_type']) ?>">
                                            <?= htmlspecialchars($payment['order_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M d, Y h:i A', strtotime($payment['created_at'])) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower($payment['payment_status']) ?>">
                                            <?= htmlspecialchars($payment['payment_status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Additional Statistics -->
            <div class="cards" style="margin-top:30px;">
                <div class="card">
                    <div class="card-icon">📈</div>
                    <h3>Average Transaction Value</h3>
                    <p>₱<?= $completedCount > 0 ? number_format($totalRevenue / $completedCount, 2) : '0.00' ?></p>
                </div>
                <div class="card">
                    <div class="card-icon">💵</div>
                    <h3>Cash Payments</h3>
                    <p>
                        <?php
                        $cashCount = count(array_filter($payments, function($p) {
                            return strtolower($p['payment_method']) === 'cash';
                        }));
                        echo $cashCount;
                        ?>
                    </p>
                </div>
                <div class="card">
                    <div class="card-icon">💳</div>
                    <h3>Card Payments</h3>
                    <p>
                        <?php
                        $cardCount = count(array_filter($payments, function($p) {
                            return strtolower($p['payment_method']) === 'card';
                        }));
                        echo $cardCount;
                        ?>
                    </p>
                </div>
                <div class="card">
                    <div class="card-icon">🌐</div>
                    <h3>Online Payments</h3>
                    <p>
                        <?php
                        $onlineCount = count(array_filter($payments, function($p) {
                            return strtolower($p['payment_method']) === 'online';
                        }));
                        echo $onlineCount;
                        ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </main>

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
    </script>
</body>
</html>