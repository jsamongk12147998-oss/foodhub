<?php
session_start();
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');

$currentPage = 'reports';
$pageTitle = 'Reports - Branch Admin';

// Check if viewing all orders
$showAll = isset($_GET['all']) && $_GET['all'] == '1';

// Fetch totals
$totalOrders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalRevenue = $pdo->query("SELECT SUM(total) FROM orders")->fetchColumn() ?: 0;
$totalDelivered = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='Delivered'")->fetchColumn();
$totalCancelled = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='Cancelled'")->fetchColumn();
$totalPending = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='Pending'")->fetchColumn();

if ($showAll) {
    $stmt = $pdo->query("SELECT * FROM orders ORDER BY order_date DESC");
    $allOrders = $stmt->fetchAll();
} else {
    $stmt = $pdo->query("
        SELECT DATE(order_date) AS order_day, COUNT(*) AS order_count, SUM(total) AS total_amount
        FROM orders
        GROUP BY DATE(order_date)
        ORDER BY order_day DESC
        LIMIT 7
    ");
    $ordersByDate = $stmt->fetchAll();
}

require_once 'includes/header.php';
?>

<style>
/* Print-friendly styling */
@media print {
    body * {
        visibility: hidden;
    }
    .printable, .printable * {
        visibility: visible;
    }
    .printable {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    .no-print {
        display: none !important;
    }
}
</style>

<div class="printable">
    <h2 style="color: #002147; margin-bottom: 20px;">Branch Admin Report</h2>
    <p style="margin-bottom: 30px;">Generated on: <?php echo date('F j, Y, g:i A'); ?></p>

    <!-- Summary Cards -->
    <div class="cards">
        <div class="card">
            <h3>Total Orders</h3>
            <p><?php echo $totalOrders; ?></p>
        </div>
        <div class="card">
            <h3>Total Revenue</h3>
            <p>₱<?php echo number_format($totalRevenue, 2); ?></p>
        </div>
        <div class="card">
            <h3>Delivered Orders</h3>
            <p><?php echo $totalDelivered; ?></p>
        </div>
        <div class="card">
            <h3>Pending Orders</h3>
            <p><?php echo $totalPending; ?></p>
        </div>
        <div class="card">
            <h3>Cancelled Orders</h3>
            <p><?php echo $totalCancelled; ?></p>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="table-container" style="margin-top: 30px;">
        <?php if ($showAll): ?>
            <h3>All Orders List</h3>
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date & Time</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Total (₱)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allOrders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                            <td><?php echo htmlspecialchars($order['order_date']); ?></td>
                            <td><?php echo htmlspecialchars($order['customer']); ?></td>
                            <td><?php echo htmlspecialchars($order['items']); ?></td>
                            <td>₱<?php echo number_format($order['total'], 2); ?></td>
                            <td><?php echo htmlspecialchars($order['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <h3>Recent Orders Summary (Last 7 Days)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Total Orders</th>
                        <th>Total Revenue (₱)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ordersByDate as $day): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($day['order_day']); ?></td>
                            <td><?php echo htmlspecialchars($day['order_count']); ?></td>
                            <td>₱<?php echo number_format($day['total_amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Buttons -->
<div style="margin-top: 20px;" class="no-print">
    <button class="btn" onclick="window.print();">Print Report</button>
    <?php if ($showAll): ?>
        <a href="reports.php" class="btn">Show Last 7 Days</a>
    <?php else: ?>
        <a href="reports.php?all=1" class="btn">Show All Orders</a>
    <?php endif; ?>
    <a href="dashboard.php" class="btn">Back to Dashboard</a>
</div>

<?php require_once 'includes/footer.php'; ?>
