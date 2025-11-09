<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');
require_once(SUPERADMIN_PATH . 'functions.php');

// Time period filter
$period = $_GET['period'] ?? 'today';

// Set date ranges based on period
$dateFilter = "";
$dateLabel = "";
switch ($period) {
    case 'today':
        $dateFilter = "DATE(created_at) = CURDATE()";
        $dateLabel = "Today";
        break;
    case 'week':
        $dateFilter = "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $dateLabel = "Last 7 Days";
        break;
    case 'month':
        $dateFilter = "created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $dateLabel = "Last 30 Days";
        break;
    case 'year':
        $dateFilter = "created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $dateLabel = "Last Year";
        break;
    default:
        $dateFilter = "1=1";
        $dateLabel = "All Time";
}

// Get main metrics
function getMetric($conn, $query) {
    $result = safeQuery($conn, $query);
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['total'] ?? 0;
    }
    return 0;
}

// Get restaurant count function
function getRestaurantCount($conn) {
    return getMetric($conn, "SELECT COUNT(*) AS total FROM restaurants WHERE status = 'active'");
}

// Get metrics - Updated to reflect branch_admin = restaurant relationship
$totalUsers = getMetric($conn, "SELECT COUNT(*) AS total FROM users WHERE role != 'super_admin'");
$totalStudents = getMetric($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'user'");
$totalBranchAdmins = getMetric($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'branch_admin'");

$totalRestaurants = getRestaurantCount($conn);
$totalOrders = getMetric($conn, "SELECT COUNT(*) AS total FROM orders");
$totalRevenue = getMetric($conn, "SELECT COALESCE(SUM(total_amount), 0) AS total FROM orders WHERE status='completed'");

// Period-specific metrics
$periodUsers = getMetric($conn, "SELECT COUNT(*) AS total FROM users WHERE role != 'super_admin' AND $dateFilter");
$periodStudents = getMetric($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND $dateFilter");
$periodBranchAdmins = getMetric($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'branch_admin' AND $dateFilter");
$periodOrders = getMetric($conn, "SELECT COUNT(*) AS total FROM orders WHERE $dateFilter");
$periodRevenue = getMetric($conn, "SELECT COALESCE(SUM(total_amount), 0) AS total FROM orders WHERE status='completed' AND $dateFilter");

// Active restaurants (with orders in period) - Updated to include branch_admin restaurants
$activeRestaurants = getMetric($conn, "
    SELECT COUNT(DISTINCT r.id) AS total 
    FROM restaurants r 
    JOIN orders o ON r.id = o.restaurant_id 
    WHERE o.$dateFilter
");

// Get restaurant performance data including branch admin info
$restaurantPerformance = [];
$restaurantQuery = safeQuery($conn, "
    SELECT 
        r.id,
        r.name,
        r.description,
        r.location,
        r.restaurant_rating,
        u.name as admin_name,
        u.email as admin_email,
        COUNT(o.id) as total_orders,
        COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_amount ELSE 0 END), 0) as total_revenue,
        COUNT(CASE WHEN o.$dateFilter THEN 1 END) as period_orders,
        COALESCE(SUM(CASE WHEN o.status='completed' AND o.$dateFilter THEN o.total_amount ELSE 0 END), 0) as period_revenue
    FROM restaurants r
    LEFT JOIN users u ON r.owner_id = u.id AND u.role = 'branch_admin'
    LEFT JOIN orders o ON r.id = o.restaurant_id
    GROUP BY r.id, r.name, r.description, r.location, r.restaurant_rating, u.name, u.email
    ORDER BY total_revenue DESC
");
if ($restaurantQuery) {
    while ($row = $restaurantQuery->fetch_assoc()) {
        $restaurantPerformance[] = $row;
    }
}

// Get top performing restaurants (for chart) - based on rating, sales, and orders
$topRestaurants = [];
$topRestaurantsQuery = safeQuery($conn, "
    SELECT 
        r.id,
        r.name,
        r.restaurant_rating,
        COUNT(o.id) as total_orders,
        COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_amount ELSE 0 END), 0) as total_revenue
    FROM restaurants r
    LEFT JOIN orders o ON r.id = o.restaurant_id
    GROUP BY r.id, r.name, r.restaurant_rating
    ORDER BY total_revenue DESC, total_orders DESC, r.restaurant_rating DESC
    LIMIT 5
");
if ($topRestaurantsQuery) {
    while ($row = $topRestaurantsQuery->fetch_assoc()) {
        $topRestaurants[] = $row;
    }
}

// Get order status breakdown (completed, cancelled, refunded)
$orderStatusData = [];
$orderStatusQuery = safeQuery($conn, "
    SELECT status, COUNT(*) as count 
    FROM orders 
    WHERE status IN ('completed', 'cancelled', 'refunded')
    AND $dateFilter 
    GROUP BY status
");
if ($orderStatusQuery) {
    while ($row = $orderStatusQuery->fetch_assoc()) {
        $orderStatusData[] = $row;
    }
}

// Get user growth data by month
$userGrowthData = [];
$userGrowthQuery = safeQuery($conn, "
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(CASE WHEN role = 'user' THEN 1 END) as students,
        COUNT(CASE WHEN role = 'branch_admin' THEN 1 END) as branch_admins,
        COUNT(*) as total_users
    FROM users 
    WHERE role IN ('user', 'branch_admin') 
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month
");
if ($userGrowthQuery) {
    while ($row = $userGrowthQuery->fetch_assoc()) {
        $userGrowthData[] = $row;
    }
}

// Get revenue by restaurant type
$revenueByType = [];
$revenueTypeQuery = safeQuery($conn, "
    SELECT 
        r.category as restaurant_type,
        COUNT(o.id) as order_count,
        COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_amount ELSE 0 END), 0) as total_revenue
    FROM restaurants r
    LEFT JOIN orders o ON r.id = o.restaurant_id
    WHERE r.category IS NOT NULL
    GROUP BY r.category
    ORDER BY total_revenue DESC
");
if ($revenueTypeQuery) {
    while ($row = $revenueTypeQuery->fetch_assoc()) {
        $revenueByType[] = $row;
    }
}

// Get restaurant revenue comparison data
$restaurantRevenueComparison = [];
$revenueComparisonQuery = safeQuery($conn, "
    SELECT 
        r.name as restaurant_name,
        COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_amount ELSE 0 END), 0) as total_revenue,
        COUNT(o.id) as total_orders,
        r.restaurant_rating
    FROM restaurants r
    LEFT JOIN orders o ON r.id = o.restaurant_id
    GROUP BY r.id, r.name, r.restaurant_rating
    ORDER BY total_revenue DESC
");
if ($revenueComparisonQuery) {
    while ($row = $revenueComparisonQuery->fetch_assoc()) {
        $restaurantRevenueComparison[] = $row;
    }
}

// Get order status breakdown by restaurant
$orderStatusByRestaurant = [];
$orderStatusRestaurantQuery = safeQuery($conn, "
    SELECT 
        r.name as restaurant_name,
        o.status,
        COUNT(o.id) as order_count
    FROM restaurants r
    LEFT JOIN orders o ON r.id = o.restaurant_id
    WHERE o.status IS NOT NULL
    AND $dateFilter
    GROUP BY r.name, o.status
    ORDER BY r.name, o.status
");
if ($orderStatusRestaurantQuery) {
    while ($row = $orderStatusRestaurantQuery->fetch_assoc()) {
        $orderStatusByRestaurant[] = $row;
    }
}

// NEW: Get revenue trend data (monthly)
$revenueTrendData = [];
$revenueTrendQuery = safeQuery($conn, "
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COALESCE(SUM(CASE WHEN status='completed' THEN total_amount ELSE 0 END), 0) as monthly_revenue
    FROM orders 
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month
");
if ($revenueTrendQuery) {
    while ($row = $revenueTrendQuery->fetch_assoc()) {
        $revenueTrendData[] = $row;
    }
}

// NEW: Get order volume data (daily for current period)
$orderVolumeData = [];
$orderVolumeQuery = safeQuery($conn, "
    SELECT 
        DATE(created_at) as order_date,
        COUNT(*) as order_count,
        COALESCE(SUM(CASE WHEN status='completed' THEN total_amount ELSE 0 END), 0) as daily_revenue
    FROM orders 
    WHERE $dateFilter
    GROUP BY DATE(created_at)
    ORDER BY order_date
");
if ($orderVolumeQuery) {
    while ($row = $orderVolumeQuery->fetch_assoc()) {
        $orderVolumeData[] = $row;
    }
}

// NEW: Get hourly order distribution
$hourlyOrderData = [];
$hourlyOrderQuery = safeQuery($conn, "
    SELECT 
        HOUR(created_at) as hour,
        COUNT(*) as order_count,
        COALESCE(SUM(CASE WHEN status='completed' THEN total_amount ELSE 0 END), 0) as hourly_revenue
    FROM orders 
    WHERE $dateFilter
    GROUP BY HOUR(created_at)
    ORDER BY hour
");
if ($hourlyOrderQuery) {
    while ($row = $hourlyOrderQuery->fetch_assoc()) {
        $hourlyOrderData[] = $row;
    }
}

// NEW: Get popular products from orders
$popularProducts = [];
$popularProductsQuery = safeQuery($conn, "
    SELECT 
        p.name as product_name,
        COUNT(oi.id) as total_orders,
        SUM(oi.quantity) as total_quantity,
        COALESCE(SUM(oi.total_price), 0) as total_revenue
    FROM products p
    JOIN order_items oi ON p.id = oi.product_id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.$dateFilter
    GROUP BY p.id, p.name
    ORDER BY total_orders DESC
    LIMIT 10
");
if ($popularProductsQuery) {
    while ($row = $popularProductsQuery->fetch_assoc()) {
        $popularProducts[] = $row;
    }
}

// NEW: Calculate revenue growth
$previousPeriodRevenue = 0;
$revenueGrowth = 0;

// Get previous period revenue for comparison
switch ($period) {
    case 'today':
        $prevDateFilter = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        break;
    case 'week':
        $prevDateFilter = "created_at BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        break;
    case 'month':
        $prevDateFilter = "created_at BETWEEN DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        break;
    case 'year':
        $prevDateFilter = "created_at BETWEEN DATE_SUB(CURDATE(), INTERVAL 2 YEAR) AND DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        break;
    default:
        $prevDateFilter = "1=0";
}

$previousRevenueQuery = safeQuery($conn, "
    SELECT COALESCE(SUM(total_amount), 0) AS total 
    FROM orders 
    WHERE status='completed' AND $prevDateFilter
");
if ($previousRevenueQuery && $previousRevenueQuery->num_rows > 0) {
    $previousPeriodRevenue = $previousRevenueQuery->fetch_assoc()['total'];
    if ($previousPeriodRevenue > 0) {
        $revenueGrowth = (($periodRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100;
    }
}

// Prepare chart data
$statusLabels = [];
$statusValues = [];
foreach ($orderStatusData as $data) {
    $statusLabels[] = ucfirst($data['status']);
    $statusValues[] = $data['count'];
}

// Top restaurants chart data
$topRestaurantLabels = [];
$topRestaurantRevenueValues = [];
$topRestaurantOrderValues = [];
$topRestaurantRatingValues = [];
foreach ($topRestaurants as $restaurant) {
    $topRestaurantLabels[] = $restaurant['name'];
    $topRestaurantRevenueValues[] = $restaurant['total_revenue'];
    $topRestaurantOrderValues[] = $restaurant['total_orders'];
    $topRestaurantRatingValues[] = $restaurant['restaurant_rating'] * 20; // Scale for better visualization
}

// User growth chart data
$userGrowthLabels = [];
$studentGrowthValues = [];
$adminGrowthValues = [];
$totalUserGrowthValues = [];
foreach ($userGrowthData as $data) {
    $userGrowthLabels[] = date('M Y', strtotime($data['month'] . '-01'));
    $studentGrowthValues[] = $data['students'];
    $adminGrowthValues[] = $data['branch_admins'];
    $totalUserGrowthValues[] = $data['total_users'];
}

// Revenue by type chart data
$revenueTypeLabels = [];
$revenueTypeValues = [];
foreach ($revenueByType as $type) {
    $revenueTypeLabels[] = $type['restaurant_type'] ?: 'Uncategorized';
    $revenueTypeValues[] = $type['total_revenue'];
}

// Restaurant revenue comparison chart data
$restaurantComparisonLabels = [];
$restaurantComparisonValues = [];
foreach ($restaurantRevenueComparison as $restaurant) {
    $restaurantComparisonLabels[] = $restaurant['restaurant_name'];
    $restaurantComparisonValues[] = $restaurant['total_revenue'];
}

// NEW: Revenue trend chart data
$revenueTrendLabels = [];
$revenueTrendValues = [];
foreach ($revenueTrendData as $data) {
    $revenueTrendLabels[] = date('M Y', strtotime($data['month'] . '-01'));
    $revenueTrendValues[] = $data['monthly_revenue'];
}

// NEW: Order volume chart data
$orderVolumeLabels = [];
$orderVolumeCounts = [];
$orderVolumeRevenues = [];
foreach ($orderVolumeData as $data) {
    $orderVolumeLabels[] = date('M j', strtotime($data['order_date']));
    $orderVolumeCounts[] = $data['order_count'];
    $orderVolumeRevenues[] = $data['daily_revenue'];
}

// NEW: Hourly order distribution data
$hourlyLabels = [];
$hourlyOrderCounts = [];
$hourlyRevenues = [];
foreach ($hourlyOrderData as $data) {
    $hourlyLabels[] = sprintf('%02d:00', $data['hour']);
    $hourlyOrderCounts[] = $data['order_count'];
    $hourlyRevenues[] = $data['hourly_revenue'];
}

// NEW: Popular products data
$popularProductLabels = [];
$popularProductOrders = [];
$popularProductRevenues = [];
foreach ($popularProducts as $product) {
    $popularProductLabels[] = $product['product_name'];
    $popularProductOrders[] = $product['total_orders'];
    $popularProductRevenues[] = $product['total_revenue'];
}

// Order status by restaurant data for table
$restaurantOrderStatus = [];
foreach ($orderStatusByRestaurant as $data) {
    $restaurantName = $data['restaurant_name'];
    if (!isset($restaurantOrderStatus[$restaurantName])) {
        $restaurantOrderStatus[$restaurantName] = [
            'restaurant_name' => $restaurantName,
            'completed' => 0,
            'cancelled' => 0,
            'refunded' => 0,
            'total' => 0
        ];
    }
    $restaurantOrderStatus[$restaurantName][$data['status']] = $data['order_count'];
    $restaurantOrderStatus[$restaurantName]['total'] += $data['order_count'];
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Analytics Dashboard</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* Your existing CSS styles remain the same */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
      background: #f8f9fa;
      color: #002147;
    }

    .dashboard-container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px;
    }

    .dashboard-header {
      text-align: center;
      margin-bottom: 20px;
    }

    .dashboard-header h1 {
      font-size: 1.8rem;
      margin-bottom: 5px;
      color: #002147;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
    }

    .dashboard-header p {
      color: #666;
      font-size: 0.9rem;
    }

    .controls-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      gap: 15px;
      flex-wrap: wrap;
    }

    .period-filter {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .period-btn {
      padding: 8px 16px;
      background: white;
      color: #002147;
      border: 1px solid #ddd;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.2s;
      font-size: 0.85rem;
      font-weight: 500;
    }

    .period-btn:hover {
      background: #002147;
      color: white;
    }

    .period-btn.active {
      background: #ffcc00;
      color: #002147;
      border-color: #ffcc00;
    }

    .export-btn {
      background: #28a745;
      color: white;
      padding: 8px 16px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-size: 0.85rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .export-btn:hover {
      background: #218838;
    }

    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 15px;
      margin-bottom: 20px;
    }

    .metric-card {
      background: white;
      padding: 20px;
      border-radius: 10px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      text-align: center;
      border-left: 4px solid #ffcc00;
    }

    .metric-card.users { border-left-color: #002147; }
    .metric-card.students { border-left-color: #007bff; }
    .metric-card.branch-admins { border-left-color: #28a745; }
    .metric-card.restaurants { border-left-color: #6f42c1; }
    .metric-card.orders { border-left-color: #fd7e14; }
    .metric-card.revenue { border-left-color: #dc3545; }

    .metric-icon {
      font-size: 1.5rem;
      margin-bottom: 10px;
      color: #002147;
    }

    .metric-card h3 {
      color: #666;
      font-size: 0.8rem;
      margin-bottom: 8px;
      text-transform: uppercase;
    }

    .metric-value {
      font-size: 1.5rem;
      font-weight: bold;
      color: #002147;
      margin: 0;
      line-height: 1.2;
    }

    .metric-subtext {
      font-size: 0.75rem;
      color: #888;
      margin-top: 5px;
    }

    .charts-section {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 15px;
      margin-bottom: 20px;
    }

    .chart-card {
      background: white;
      padding: 20px;
      border-radius: 10px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      position: relative;
    }

    .chart-card h3 {
      color: #002147;
      margin-bottom: 15px;
      font-size: 1rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .chart-container {
      position: relative;
      height: 200px;
      width: 100%;
    }

    .chart-stats {
      margin-top: 15px;
      padding-top: 15px;
      border-top: 1px solid #f0f0f0;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .stat-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }

    .stat-label {
      font-size: 0.7rem;
      color: #666;
      margin-bottom: 2px;
    }

    .stat-value {
      font-size: 0.85rem;
      font-weight: 600;
      color: #002147;
    }

    .stat-value.revenue {
      color: #28a745;
    }

    .stat-value.positive {
      color: #28a745;
    }

    .stat-value.negative {
      color: #dc3545;
    }

    .chart-actions {
      position: absolute;
      top: 15px;
      right: 15px;
      display: flex;
      gap: 5px;
    }

    .chart-action-btn {
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid #ddd;
      border-radius: 4px;
      padding: 4px 8px;
      cursor: pointer;
      font-size: 0.7rem;
      color: #002147;
      transition: all 0.2s;
    }

    .chart-action-btn:hover {
      background: #002147;
      color: white;
    }

    .tables-section {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 15px;
    }

    .table-card {
      background: white;
      padding: 20px;
      border-radius: 10px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .table-card h3 {
      color: #002147;
      margin-bottom: 15px;
      font-size: 1rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .table-responsive {
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
    }

    thead {
      background: #002147;
    }

    th {
      padding: 12px 8px;
      text-align: left;
      color: white;
      font-weight: 600;
      font-size: 0.8rem;
    }

    td {
      padding: 10px 8px;
      text-align: left;
      border-bottom: 1px solid #f0f0f0;
    }

    tbody tr:hover {
      background: #f8f9fa;
    }

    .status-badge {
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 0.7rem;
      font-weight: 600;
      text-transform: capitalize;
    }

    .status-pending { background: #fff3cd; color: #856404; }
    .status-confirmed { background: #d1ecf1; color: #0c5460; }
    .status-preparing { background: #ffeaa7; color: #856404; }
    .status-completed { background: #d4edda; color: #155724; }
    .status-cancelled { background: #f8d7da; color: #721c24; }
    .status-failed { background: #f8d7da; color: #721c24; }
    .status-refunded { background: #e2e3e5; color: #383d41; }

    .restaurant-name {
      font-weight: 600;
      color: #002147;
    }

    .admin-name {
      font-size: 0.8rem;
      color: #666;
    }

    .revenue-amount {
      font-weight: 600;
      color: #28a745;
    }

    .no-data {
      padding: 40px 20px;
      text-align: center;
      color: #666;
      font-size: 0.9rem;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .dashboard-container {
        padding: 15px;
      }

      .controls-row {
        flex-direction: column;
        align-items: stretch;
      }

      .period-filter {
        justify-content: center;
      }

      .metrics-grid {
        grid-template-columns: 1fr 1fr;
      }

      .charts-section,
      .tables-section {
        grid-template-columns: 1fr;
      }

      .chart-stats {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 480px) {
      .metrics-grid {
        grid-template-columns: 1fr;
      }

      .metric-card {
        padding: 15px;
      }

      .chart-card,
      .table-card {
        padding: 15px;
      }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <div class="dashboard-header">
    <h1><i class="fas fa-chart-line"></i> Analytics Dashboard</h1>
    <p>Platform performance overview with Branch Admin integration</p>
  </div>
  
  <!-- Controls -->
  <div class="controls-row">
    <div class="period-filter">
      <button class="period-btn <?= $period === 'today' ? 'active' : '' ?>" onclick="setPeriod('today')">
        <i class="fas fa-calendar-day"></i> Today
      </button>
      <button class="period-btn <?= $period === 'week' ? 'active' : '' ?>" onclick="setPeriod('week')">
        <i class="fas fa-calendar-week"></i> Week
      </button>
      <button class="period-btn <?= $period === 'month' ? 'active' : '' ?>" onclick="setPeriod('month')">
        <i class="fas fa-calendar-alt"></i> Month
      </button>
      <button class="period-btn <?= $period === 'year' ? 'active' : '' ?>" onclick="setPeriod('year')">
        <i class="fas fa-calendar"></i> Year
      </button>
      <button class="period-btn <?= $period === 'all' ? 'active' : '' ?>" onclick="setPeriod('all')">
        <i class="fas fa-infinity"></i> All
      </button>
    </div>
    
    <button class="export-btn" onclick="exportAnalytics()">
      <i class="fas fa-file-export"></i> Export
    </button>
  </div>

  <!-- Key Metrics -->
  <div class="metrics-grid">
    <div class="metric-card users">
      <div class="metric-icon">
        <i class="fas fa-users"></i>
      </div>
      <h3>Total Users</h3>
      <p class="metric-value"><?= number_format($totalUsers) ?></p>
      <div class="metric-subtext"><?= number_format($periodUsers) ?> in <?= $dateLabel ?></div>
    </div>
    
    <div class="metric-card students">
      <div class="metric-icon">
        <i class="fas fa-user-graduate"></i>
      </div>
      <h3>Students</h3>
      <p class="metric-value"><?= number_format($totalStudents) ?></p>
      <div class="metric-subtext"><?= number_format($periodStudents) ?> in <?= $dateLabel ?></div>
    </div>
    
    <div class="metric-card branch-admins">
      <div class="metric-icon">
        <i class="fas fa-user-tie"></i>
      </div>
      <h3>Branch Admins</h3>
      <p class="metric-value"><?= number_format($totalBranchAdmins) ?></p>
      <div class="metric-subtext"><?= number_format($periodBranchAdmins) ?> in <?= $dateLabel ?></div>
    </div>
    
    <div class="metric-card restaurants">
      <div class="metric-icon">
        <i class="fas fa-utensils"></i>
      </div>
      <h3>Restaurants</h3>
      <p class="metric-value"><?= number_format($totalRestaurants) ?></p>
      <div class="metric-subtext"><?= number_format($activeRestaurants) ?> active</div>
    </div>
    
    <div class="metric-card orders">
      <div class="metric-icon">
        <i class="fas fa-shopping-cart"></i>
      </div>
      <h3>Total Orders</h3>
      <p class="metric-value"><?= number_format($totalOrders) ?></p>
      <div class="metric-subtext"><?= number_format($periodOrders) ?> in <?= $dateLabel ?></div>
    </div>
    
    <div class="metric-card revenue">
      <div class="metric-icon">
        <i class="fas fa-money-bill-wave"></i>
      </div>
      <h3>Total Revenue</h3>
      <p class="metric-value">₱<?= number_format($totalRevenue, 2) ?></p>
      <div class="metric-subtext">₱<?= number_format($periodRevenue, 2) ?> in <?= $dateLabel ?></div>
    </div>
  </div>

  <!-- Charts -->
  <div class="charts-section">
    <!-- Order Status Chart -->
    <div class="chart-card">
      <h3><i class="fas fa-chart-pie"></i> Order Status Distribution</h3>
      <div class="chart-container">
        <?php if (!empty($statusValues)): ?>
          <canvas id="orderStatusChart"></canvas>
        <?php else: ?>
          <div class="no-data">No order status data available</div>
        <?php endif; ?>
      </div>
      <div class="chart-stats">
        <?php 
        $totalStatusOrders = array_sum($statusValues);
        if ($totalStatusOrders > 0): 
        ?>
          <div class="stat-item">
            <span class="stat-label">Completion Rate:</span>
            <span class="stat-value">
              <?= number_format(($statusValues[array_search('Completed', $statusLabels)] / $totalStatusOrders) * 100, 1) ?>%
            </span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Revenue Trend Chart -->
    <div class="chart-card">
      <h3><i class="fas fa-chart-line"></i> Revenue Trend</h3>
      <div class="chart-container">
        <?php if (!empty($revenueTrendData)): ?>
          <canvas id="revenueTrendChart"></canvas>
        <?php else: ?>
          <div class="no-data">No revenue trend data available</div>
        <?php endif; ?>
      </div>
      <div class="chart-stats">
        <div class="stat-item">
          <span class="stat-label">Current Period:</span>
          <span class="stat-value revenue">₱<?= number_format($periodRevenue, 2) ?></span>
        </div>
        <div class="stat-item">
          <span class="stat-label">Growth:</span>
          <span class="stat-value <?= $revenueGrowth >= 0 ? 'positive' : 'negative' ?>">
            <?= $revenueGrowth >= 0 ? '+' : '' ?><?= number_format($revenueGrowth, 1) ?>%
          </span>
        </div>
      </div>
    </div>

    <!-- User Growth Chart -->
    <div class="chart-card">
      <h3><i class="fas fa-users"></i> User Growth</h3>
      <div class="chart-container">
        <?php if (!empty($userGrowthData)): ?>
          <canvas id="userGrowthChart"></canvas>
        <?php else: ?>
          <div class="no-data">No user growth data available</div>
        <?php endif; ?>
      </div>
      <div class="chart-stats">
        <div class="stat-item">
          <span class="stat-label">New Users (<?= $dateLabel ?>):</span>
          <span class="stat-value"><?= number_format($periodUsers) ?></span>
        </div>
        <div class="stat-item">
          <span class="stat-label">Total Users:</span>
          <span class="stat-value"><?= number_format($totalUsers) ?></span>
        </div>
      </div>
    </div>

    <!-- Restaurant Performance Chart -->
    <div class="chart-card">
      <h3><i class="fas fa-trophy"></i> Top Restaurants Performance</h3>
      <div class="chart-container">
        <?php if (!empty($topRestaurants)): ?>
          <canvas id="topRestaurantsChart"></canvas>
        <?php else: ?>
          <div class="no-data">No restaurant performance data available</div>
        <?php endif; ?>
      </div>
      <div class="chart-stats">
        <?php if (!empty($topRestaurants)): ?>
          <div class="stat-item">
            <span class="stat-label">Top Restaurant:</span>
            <span class="stat-value"><?= htmlspecialchars($topRestaurants[0]['name']) ?></span>
          </div>
          <div class="stat-item">
            <span class="stat-label">Revenue:</span>
            <span class="stat-value revenue">₱<?= number_format($topRestaurants[0]['total_revenue'], 2) ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Order Volume Chart -->
    <div class="chart-card">
      <h3><i class="fas fa-shopping-cart"></i> Order Volume Trend</h3>
      <div class="chart-container">
        <?php if (!empty($orderVolumeData)): ?>
          <canvas id="orderVolumeChart"></canvas>
        <?php else: ?>
          <div class="no-data">No order volume data available</div>
        <?php endif; ?>
      </div>
      <div class="chart-stats">
        <div class="stat-item">
          <span class="stat-label">Total Orders (<?= $dateLabel ?>):</span>
          <span class="stat-value"><?= number_format($periodOrders) ?></span>
        </div>
        <div class="stat-item">
          <span class="stat-label">Avg Daily:</span>
          <span class="stat-value">
            <?= !empty($orderVolumeData) ? number_format(array_sum($orderVolumeCounts) / count($orderVolumeCounts), 1) : 0 ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Hourly Distribution Chart -->
    <div class="chart-card">
      <h3><i class="fas fa-clock"></i> Hourly Order Distribution</h3>
      <div class="chart-container">
        <?php if (!empty($hourlyOrderData)): ?>
          <canvas id="hourlyDistributionChart"></canvas>
        <?php else: ?>
          <div class="no-data">No hourly order data available</div>
        <?php endif; ?>
      </div>
      <div class="chart-stats">
        <?php if (!empty($hourlyOrderData)): 
          $peakHourIndex = array_search(max($hourlyOrderCounts), $hourlyOrderCounts);
        ?>
          <div class="stat-item">
            <span class="stat-label">Peak Hour:</span>
            <span class="stat-value"><?= $hourlyLabels[$peakHourIndex] ?></span>
          </div>
          <div class="stat-item">
            <span class="stat-label">Peak Orders:</span>
            <span class="stat-value"><?= max($hourlyOrderCounts) ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Tables Section -->
  <div class="tables-section">
    <!-- Restaurant Performance Table -->
    <div class="table-card">
      <h3><i class="fas fa-list"></i> Restaurant Performance</h3>
      <div class="table-responsive">
        <?php if (!empty($restaurantPerformance)): ?>
          <table>
            <thead>
              <tr>
                <th>Restaurant</th>
                <th>Admin</th>
                <th>Rating</th>
                <th>Orders</th>
                <th>Revenue</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($restaurantPerformance as $restaurant): ?>
                <tr>
                  <td>
                    <div class="restaurant-name"><?= htmlspecialchars($restaurant['name']) ?></div>
                    <div class="admin-name"><?= htmlspecialchars($restaurant['admin_name'] ?? 'N/A') ?></div>
                  </td>
                  <td><?= htmlspecialchars($restaurant['admin_email'] ?? 'N/A') ?></td>
                  <td><?= number_format($restaurant['restaurant_rating'], 1) ?>/5</td>
                  <td><?= number_format($restaurant['total_orders']) ?></td>
                  <td class="revenue-amount">₱<?= number_format($restaurant['total_revenue'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="no-data">No restaurant performance data available</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Order Status by Restaurant Table -->
    <div class="table-card">
      <h3><i class="fas fa-table"></i> Order Status by Restaurant</h3>
      <div class="table-responsive">
        <?php if (!empty($restaurantOrderStatus)): ?>
          <table>
            <thead>
              <tr>
                <th>Restaurant</th>
                <th>Completed</th>
                <th>Cancelled</th>
                <th>Refunded</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($restaurantOrderStatus as $status): ?>
                <tr>
                  <td class="restaurant-name"><?= htmlspecialchars($status['restaurant_name']) ?></td>
                  <td><?= number_format($status['completed']) ?></td>
                  <td><?= number_format($status['cancelled']) ?></td>
                  <td><?= number_format($status['refunded']) ?></td>
                  <td><?= number_format($status['total']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="no-data">No order status data available</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
// Set period function
function setPeriod(period) {
  const url = new URL(window.location.href);
  url.searchParams.set('period', period);
  window.location.href = url.toString();
}

// Export function
function exportAnalytics() {
  alert('Export functionality would be implemented here');
  // In a real implementation, this would generate a CSV or PDF report
}

// Initialize charts when the page loads
document.addEventListener('DOMContentLoaded', function() {
  // Order Status Chart (Doughnut)
  <?php if (!empty($statusValues)): ?>
  new Chart(document.getElementById('orderStatusChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode($statusLabels) ?>,
      datasets: [{
        data: <?= json_encode($statusValues) ?>,
        backgroundColor: [
          '#28a745', // Completed - Green
          '#dc3545', // Cancelled - Red
          '#6c757d'  // Refunded - Gray
        ],
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            boxWidth: 12,
            padding: 15
          }
        }
      },
      cutout: '60%'
    }
  });
  <?php endif; ?>

  // Revenue Trend Chart (Line)
  <?php if (!empty($revenueTrendData)): ?>
  new Chart(document.getElementById('revenueTrendChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($revenueTrendLabels) ?>,
      datasets: [{
        label: 'Monthly Revenue',
        data: <?= json_encode($revenueTrendValues) ?>,
        borderColor: '#28a745',
        backgroundColor: 'rgba(40, 167, 69, 0.1)',
        borderWidth: 2,
        fill: true,
        tension: 0.4
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              return '₱' + value.toLocaleString();
            }
          }
        }
      }
    }
  });
  <?php endif; ?>

  // User Growth Chart (Line)
  <?php if (!empty($userGrowthData)): ?>
  new Chart(document.getElementById('userGrowthChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($userGrowthLabels) ?>,
      datasets: [
        {
          label: 'Students',
          data: <?= json_encode($studentGrowthValues) ?>,
          borderColor: '#007bff',
          backgroundColor: 'rgba(0, 123, 255, 0.1)',
          borderWidth: 2,
          fill: true
        },
        {
          label: 'Branch Admins',
          data: <?= json_encode($adminGrowthValues) ?>,
          borderColor: '#28a745',
          backgroundColor: 'rgba(40, 167, 69, 0.1)',
          borderWidth: 2,
          fill: true
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom'
        }
      },
      scales: {
        y: {
          beginAtZero: true
        }
      }
    }
  });
  <?php endif; ?>

  // Top Restaurants Chart (Bar)
  <?php if (!empty($topRestaurants)): ?>
  new Chart(document.getElementById('topRestaurantsChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($topRestaurantLabels) ?>,
      datasets: [
        {
          label: 'Revenue (₱)',
          data: <?= json_encode($topRestaurantRevenueValues) ?>,
          backgroundColor: '#28a745',
          borderColor: '#28a745',
          borderWidth: 1,
          yAxisID: 'y'
        },
        {
          label: 'Orders',
          data: <?= json_encode($topRestaurantOrderValues) ?>,
          backgroundColor: '#007bff',
          borderColor: '#007bff',
          borderWidth: 1,
          yAxisID: 'y1',
          type: 'line'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom'
        }
      },
      scales: {
        y: {
          type: 'linear',
          display: true,
          position: 'left',
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              return '₱' + value.toLocaleString();
            }
          }
        },
        y1: {
          type: 'linear',
          display: true,
          position: 'right',
          beginAtZero: true,
          grid: {
            drawOnChartArea: false
          }
        }
      }
    }
  });
  <?php endif; ?>

  // Order Volume Chart (Line)
  <?php if (!empty($orderVolumeData)): ?>
  new Chart(document.getElementById('orderVolumeChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($orderVolumeLabels) ?>,
      datasets: [
        {
          label: 'Order Count',
          data: <?= json_encode($orderVolumeCounts) ?>,
          borderColor: '#007bff',
          backgroundColor: 'rgba(0, 123, 255, 0.1)',
          borderWidth: 2,
          fill: true,
          tension: 0.4
        },
        {
          label: 'Daily Revenue',
          data: <?= json_encode($orderVolumeRevenues) ?>,
          borderColor: '#28a745',
          backgroundColor: 'rgba(40, 167, 69, 0.1)',
          borderWidth: 2,
          fill: true,
          tension: 0.4,
          yAxisID: 'y1'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom'
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          title: {
            display: true,
            text: 'Order Count'
          }
        },
        y1: {
          beginAtZero: true,
          position: 'right',
          grid: {
            drawOnChartArea: false
          },
          title: {
            display: true,
            text: 'Revenue (₱)'
          },
          ticks: {
            callback: function(value) {
              return '₱' + value.toLocaleString();
            }
          }
        }
      }
    }
  });
  <?php endif; ?>

  // Hourly Distribution Chart (Bar)
  <?php if (!empty($hourlyOrderData)): ?>
  new Chart(document.getElementById('hourlyDistributionChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($hourlyLabels) ?>,
      datasets: [{
        label: 'Order Count',
        data: <?= json_encode($hourlyOrderCounts) ?>,
        backgroundColor: 'rgba(0, 123, 255, 0.7)',
        borderColor: '#007bff',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          beginAtZero: true
        }
      }
    }
  });
  <?php endif; ?>
});
</script>
</body>
</html>