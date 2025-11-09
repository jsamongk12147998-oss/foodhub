<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');
require_once(SUPERADMIN_PATH . 'functions.php');
// Debug: Check if tables exist
$check_tables = "SHOW TABLES LIKE 'user_sessions'";
$tables_result = $conn->query($check_tables);
$sessions_table_exists = $tables_result && $tables_result->num_rows > 0;

echo "<!-- Debug: user_sessions table exists: " . ($sessions_table_exists ? 'YES' : 'NO') . " -->";
// Enhanced debug: Check what's actually in the sessions table
$debug_detailed = "SELECT 
    us.user_id, 
    u.name, 
    u.role,
    us.login_time,
    us.last_activity, 
    us.logout_time,
    us.is_active,
    TIMESTAMPDIFF(SECOND, us.last_activity, NOW()) as seconds_ago,
    (CASE 
        WHEN us.is_active = 1 AND TIMESTAMPDIFF(SECOND, us.last_activity, NOW()) <= 30 
        THEN 'ONLINE' 
        ELSE 'OFFLINE' 
    END) as status
FROM user_sessions us 
JOIN users u ON us.user_id = u.id 
WHERE u.role = 'branch_admin'
ORDER BY us.last_activity DESC";

$detailed_result = $conn->query($debug_detailed);
echo "<!-- Detailed Session Debug -->";
if ($detailed_result && $detailed_result->num_rows > 0) {
    while($detail = $detailed_result->fetch_assoc()) {
        echo "<!-- Session Detail - User: " . htmlspecialchars($detail['name']) . 
             " | Active: " . $detail['is_active'] . 
             " | Seconds Ago: " . $detail['seconds_ago'] . 
             " | Status: " . $detail['status'] . 
             " | Last Activity: " . $detail['last_activity'] . 
             " | Logout Time: " . $detail['logout_time'] . " -->";
    }
} else {
    echo "<!-- No detailed session data found -->";
}

// ✅ Improved query to fetch branch admins with login/logout history
$query = "
SELECT 
    u.id AS branch_id,
    u.name,
    u.email,
    u.owner_id,
    u.profile_image,
    u.created_at,
    ul.last_login,
    ul.ip_address,
    us.last_activity,
    us.login_time,
    us.logout_time,
    us.is_active,
    CASE 
        WHEN us.last_activity IS NOT NULL AND us.last_activity >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
        THEN 1 ELSE 0
    END AS is_currently_online,
    (SELECT COUNT(*) FROM user_sessions us2 WHERE us2.user_id = u.id) as total_sessions,
    (SELECT MAX(login_time) FROM user_sessions us3 WHERE us3.user_id = u.id AND us3.logout_time IS NOT NULL) as last_logout
FROM users u
LEFT JOIN (
    SELECT ul1.user_id, ul1.login_time AS last_login, ul1.ip_address
    FROM user_logins ul1
    INNER JOIN (
        SELECT user_id, MAX(id) AS max_id
        FROM user_logins
        GROUP BY user_id
    ) ul2 ON ul1.id = ul2.max_id
) ul ON u.id = ul.user_id
LEFT JOIN (
    SELECT us1.user_id, us1.last_activity, us1.login_time, us1.logout_time, us1.is_active
    FROM user_sessions us1
    INNER JOIN (
        SELECT user_id, MAX(id) AS max_id
        FROM user_sessions
        GROUP BY user_id
    ) us2 ON us1.id = us2.max_id
) us ON u.id = us.user_id
WHERE u.role = 'branch_admin'
ORDER BY is_currently_online DESC, us.last_activity DESC
";

echo "<!-- Query: " . htmlspecialchars($query) . " -->";

$branchAdmins = safeQuery($conn, $query);

$admins = [];
$activeAdminsCount = 0;

if ($branchAdmins && $branchAdmins->num_rows > 0) {
    while($row = $branchAdmins->fetch_assoc()) {
        $admins[] = $row;
        if ($row['is_currently_online']) {
            $activeAdminsCount++;
        }

        // Debug output for each admin
        echo "<!-- Admin: " . htmlspecialchars($row['name']) . 
             " | Online: " . ($row['is_currently_online'] ? 'YES' : 'NO') . 
             " | Last Activity: " . $row['last_activity'] . 
             " | Login Time: " . $row['login_time'] . 
             " | Logout Time: " . $row['logout_time'] . 
             " | is_active: " . $row['is_active'] . " -->";
    }
} else {
    echo "<!-- No branch admins found or query failed -->";
    
    // Debug: Let's see what users exist
    $users_debug = "SELECT id, name, role FROM users WHERE role = 'branch_admin'";
    $users_result = $conn->query($users_debug);
    if ($users_result && $users_result->num_rows > 0) {
        echo "<!-- Found " . $users_result->num_rows . " branch admin users -->";
        while($user = $users_result->fetch_assoc()) {
            echo "<!-- User: " . htmlspecialchars($user['name']) . " | Role: " . $user['role'] . " -->";
        }
    }
}

// Handle manual logout if requested
if (isset($_GET['force_logout']) && isset($_GET['admin_id'])) {
    $adminId = intval($_GET['admin_id']);
    if ($sessionManager->logLogout($adminId)) {
        echo "<!-- Successfully logged out admin ID: $adminId -->";
        // Redirect to avoid resubmission
        header("Location: " . str_replace(["&force_logout=1", "?force_logout=1"], "", $_SERVER['REQUEST_URI']));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Branch Admin Accounts</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
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
      max-width: 1400px;
      margin: 0 auto;
      padding: 30px 20px;
    }

    .dashboard-header {
      text-align: center;
      margin-bottom: 30px;
    }

    .dashboard-header h1 {
      font-size: 2.5rem;
      margin-bottom: 10px;
      color: #002147;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 15px;
    }

    .dashboard-header p {
      color: #666;
      font-size: 1.1rem;
    }

    /* Statistics Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }
    
    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 10px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        border-left: 4px solid #002147;
    }
    
    .stat-card.active {
        border-left-color: #28a745;
    }
    
    .stat-card.inactive {
        border-left-color: #6c757d;
    }
    
    .stat-number {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.9rem;
        color: #666;
    }

    .management-section {
      background: white;
      padding: 30px;
      border-radius: 15px;
      box-shadow: 0 4px 15px rgba(0, 33, 71, 0.1);
      margin-bottom: 30px;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 25px;
      flex-wrap: wrap;
      gap: 15px;
    }

    .section-header h2 {
      color: #002147;
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .stats-card {
      background: linear-gradient(135deg, #002147 0%, #003366 100%);
      color: white;
      padding: 20px;
      border-radius: 12px;
      text-align: center;
      min-width: 150px;
    }

    .stats-number {
      font-size: 2rem;
      font-weight: bold;
      margin-bottom: 5px;
    }

    .stats-label {
      font-size: 0.9rem;
      opacity: 0.9;
    }

    .table-responsive {
      overflow-x: auto;
      border-radius: 10px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 900px;
    }

    thead {
      background: linear-gradient(135deg, #002147 0%, #003366 100%);
    }

    th {
      padding: 16px 12px;
      text-align: left;
      color: white;
      font-weight: 600;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    tbody tr {
      border-bottom: 1px solid #f0f0f0;
      transition: background 0.3s;
    }

    tbody tr:hover {
      background: #f8fafc;
    }

    td {
      padding: 14px 12px;
      text-align: left;
      vertical-align: middle;
    }

    .user-info {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .user-avatar {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      background: linear-gradient(135deg, #ffcc00 0%, #ffd633 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #002147;
      font-weight: bold;
      font-size: 1.1rem;
      flex-shrink: 0;
    }

    .user-avatar img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      object-fit: cover;
    }

    .user-details {
      display: flex;
      flex-direction: column;
    }

    .user-name {
      font-weight: 600;
      color: #002147;
    }

    .user-email {
      color: #666;
      font-size: 0.85rem;
    }

    .owner-id {
      background: #e9ecef;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
      color: #495057;
      display: inline-block;
    }

    .status-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: capitalize;
      transition: all 0.3s ease;
    }

    .status-active {
      background: #d4edda;
      color: #155724;
    }

    .status-inactive {
      background: #f8d7da;
      color: #721c24;
    }

    .status-offline {
      background: #e2e3e5;
      color: #383d41;
    }

    .last-active {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .last-active-time {
      font-weight: 600;
      font-size: 0.9rem;
      transition: color 0.3s ease;
    }

    .last-active-details {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.75rem;
      color: #666;
    }

    .ip-address {
      background: #f8f9fa;
      padding: 2px 6px;
      border-radius: 4px;
      font-family: monospace;
      font-size: 0.7rem;
    }

    .online-indicator {
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .online-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #28a745;
      animation: pulse 2s infinite;
    }

    .offline-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #6c757d;
    }

    @keyframes pulse {
      0% { opacity: 1; }
      50% { opacity: 0.5; }
      100% { opacity: 1; }
    }

    .date-info {
      display: flex;
      flex-direction: column;
    }

    .date-main {
      font-weight: 600;
      color: #002147;
    }

    .date-sub {
      color: #666;
      font-size: 0.8rem;
    }

    .no-data {
      padding: 60px 20px;
      text-align: center;
      color: #666;
    }

    .no-data-content {
      color: #666;
    }

    .no-data-icon {
      font-size: 4rem;
      margin-bottom: 20px;
      display: block;
      opacity: 0.5;
    }

    /* Time-based status colors */
    .time-recent { color: #28a745; }
    .time-moderate { color: #fd7e14; }
    .time-old { color: #dc3545; }

    /* Real-time styles */
    .real-time-badge {
        position: relative;
    }
    
    .real-time-pulse {
        animation: realtimePulse 1s infinite;
    }
    
    @keyframes realtimePulse {
        0% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.1); opacity: 0.7; }
        100% { transform: scale(1); opacity: 1; }
    }

    /* Auto-refresh indicator */
    .refresh-indicator {
      display: flex;
      align-items: center;
      gap: 8px;
      background: #e7f3ff;
      padding: 8px 12px;
      border-radius: 6px;
      margin-bottom: 15px;
      font-size: 0.85rem;
      color: #0066cc;
    }

    .refresh-spinner {
      width: 16px;
      height: 16px;
      border: 2px solid #0066cc;
      border-top: 2px solid transparent;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .dashboard-container {
        padding: 20px 15px;
      }

      .dashboard-header h1 {
        font-size: 2rem;
      }

      .management-section {
        padding: 20px;
      }

      .section-header {
        flex-direction: column;
        align-items: stretch;
      }

      .stats-card {
        width: 100%;
      }

      .user-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
      }

      .user-avatar {
        align-self: flex-start;
      }

      table {
        min-width: 1000px;
      }
    }

    @media (max-width: 480px) {
      .dashboard-header h1 {
        font-size: 1.8rem;
      }

      .management-section {
        padding: 15px;
      }

      th, td {
        padding: 12px 8px;
      }
    }
  </style>
</head>
<body>

<div class="dashboard-container">
  <!-- Header -->
  <div class="dashboard-header">
    <h1><i class="fas fa-user-tie"></i> Branch Admin Accounts</h1>
    <p>Real-time activity tracking - Updates within 5 seconds of logout</p>
  </div>

  <!-- Statistics Cards -->
  <div class="stats-grid">
    <div class="stat-card active">
      <div class="stat-number"><?= $activeAdminsCount ?></div>
      <div class="stat-label">Live Online</div>
      <small>Active right now</small>
    </div>
    <div class="stat-card">
      <div class="stat-number"><?= count($admins) ?></div>
      <div class="stat-label">Total Admins</div>
      <small>All branch administrators</small>
    </div>
    <div class="stat-card inactive">
      <div class="stat-number"><?= count($admins) - $activeAdminsCount ?></div>
      <div class="stat-label">Currently Offline</div>
      <small>Not active</small>
    </div>
  </div>

  <!-- Management Section -->
  <div class="management-section">
    <div class="section-header">
      <h2><i class="fas fa-list-alt"></i> All Branch Admins <span class="real-time-badge" style="background: #28a745; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.7rem; margin-left: 10px;">LIVE</span></h2>
      <div class="stats-card">
        <div class="stats-number"><?= count($admins) ?></div>
        <div class="stats-label">Total Admins</div>
      </div>
    </div>

   <div class="table-responsive">
  <?php if (isset($admins) && count($admins) > 0): ?>
    <table>
      <thead>
        <tr>
          <th>Admin Information</th>
          <th>Owner ID</th>
          <th>Live Status</th>
          <th>Last Activity</th>
          <th>Login/Logout History</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $row): 
          $isOnline = $row['is_currently_online'];
          $lastActivity = $row['last_activity'];
          $loginTime = $row['login_time'];
          $logoutTime = $row['logout_time'];
          $timeAgo = "Never active";
          $timeClass = "time-old";
          
          if ($lastActivity) {
            $lastActivityTime = strtotime($lastActivity);
            $currentTime = time();
            $timeDiff = $currentTime - $lastActivityTime;
            
            // Calculate time ago
            if ($timeDiff < 10) {
              $timeAgo = "Just now";
              $timeClass = "time-recent";
            } elseif ($timeDiff < 60) {
              $timeAgo = $timeDiff . " sec ago";
              $timeClass = "time-recent";
            } elseif ($timeDiff < 3600) {
              $minutes = floor($timeDiff / 60);
              $timeAgo = $minutes . " min ago";
              $timeClass = $minutes <= 5 ? "time-recent" : "time-moderate";
            } elseif ($timeDiff < 86400) {
              $hours = floor($timeDiff / 3600);
              $timeAgo = $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
              $timeClass = $hours <= 1 ? "time-recent" : "time-moderate";
            } else {
              $days = floor($timeDiff / 86400);
              $timeAgo = $days . " day" . ($days > 1 ? "s" : "") . " ago";
              $timeClass = "time-old";
            }
          }
        ?>
          <tr>
            <td>
              <div class="user-info">
                <?php if (!empty($row['profile_image'])): ?>
                  <img src="uploads/<?= htmlspecialchars($row['profile_image']); ?>" alt="Profile" class="user-avatar">
                <?php else: ?>
                  <div class="user-avatar">
                    <?= strtoupper(substr($row['name'], 0, 1)) ?>
                  </div>
                <?php endif; ?>
                <div class="user-details">
                  <div class="user-name"><?= htmlspecialchars($row['name']) ?></div>
                  <div class="user-email"><?= htmlspecialchars($row['email']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <?php if (!empty($row['owner_id'])): ?>
                <span class="owner-id">#<?= htmlspecialchars($row['owner_id']) ?></span>
              <?php else: ?>
                <span style="color: #888; font-style: italic;">Not set</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isOnline): ?>
                <span class="status-badge status-active real-time-pulse" id="status-<?= $row['branch_id'] ?>">
                  <span class="online-indicator">
                    <span class="online-dot"></span>
                    LIVE ONLINE
                  </span>
                </span>
              <?php else: ?>
                <span class="status-badge status-offline" id="status-<?= $row['branch_id'] ?>">
                  <span class="online-indicator">
                    <span class="offline-dot"></span>
                    OFFLINE
                  </span>
                </span>
              <?php endif; ?>
            </td>
            <td>
              <div class="last-active">
                <div class="last-active-time <?= $timeClass ?>" id="time-<?= $row['branch_id'] ?>">
                  <?= $timeAgo ?>
                </div>
                <?php if ($lastActivity): ?>
                  <div class="last-active-details">
                    <i class="fas fa-clock"></i>
                    <?= date('M j, Y g:i A', strtotime($lastActivity)) ?>
                  </div>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div class="login-history">
                <?php if ($loginTime): ?>
                  <div class="session-info">
                    <strong>Last Login:</strong><br>
                    <?= date('M j, Y g:i A', strtotime($loginTime)) ?>
                  </div>
                <?php endif; ?>
                
                <?php if ($logoutTime): ?>
                  <div class="session-info" style="margin-top: 5px;">
                    <strong>Last Logout:</strong><br>
                    <?= date('M j, Y g:i A', strtotime($logoutTime)) ?>
                  </div>
                <?php elseif ($isOnline): ?>
                  <div class="session-info" style="margin-top: 5px;">
                    <strong>Current Session:</strong><br>
                    <span style="color: #28a745;">Active since <?= date('g:i A', strtotime($loginTime)) ?></span>
                  </div>
                <?php endif; ?>
                
                <?php if ($row['total_sessions'] > 0): ?>
                  <div class="session-info" style="margin-top: 5px; font-size: 0.8rem; color: #666;">
                    <i class="fas fa-history"></i> Total sessions: <?= $row['total_sessions'] ?>
                  </div>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <?php if ($isOnline): ?>
                <button onclick="forceLogout(<?= $row['branch_id'] ?>)" class="logout-btn" style="background: #dc3545; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                  <i class="fas fa-sign-out-alt"></i> Force Logout
                </button>
              <?php else: ?>
                <span style="color: #888; font-style: italic;">Offline</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="no-data">
      <div class="no-data-content">
        <span class="no-data-icon">👥</span>
        <h3>No Branch Admins Found</h3>
        <p>There are currently no branch admin accounts in the system</p>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
// Enhanced auto-refresh functionality
// Enhanced auto-refresh functionality with AJAX
let refreshInterval;
let refreshCountdown = 10; // Increased to 10 seconds

function startAutoRefresh() {
    refreshInterval = setInterval(() => {
        refreshCountdown--;
        updateRefreshIndicator();
        
        if (refreshCountdown <= 0) {
            refreshData();
        }
    }, 1000);
}

function updateRefreshIndicator() {
    let indicator = document.getElementById('refreshIndicator');
    if (!indicator) {
        // Create refresh indicator if it doesn't exist
        indicator = document.createElement('div');
        indicator.id = 'refreshIndicator';
        indicator.className = 'refresh-indicator';
        indicator.innerHTML = '<div class="refresh-spinner"></div><span>Auto-refreshing in ' + refreshCountdown + ' seconds...</span>';
        document.querySelector('.management-section').prepend(indicator);
    } else {
        indicator.querySelector('span').textContent = `Auto-refreshing in ${refreshCountdown} seconds...`;
    }
}

function refreshData() {
    const indicator = document.getElementById('refreshIndicator');
    if (indicator) {
        indicator.querySelector('span').textContent = 'Refreshing data...';
    }
    
    // Use AJAX to refresh data without page reload
    fetch(window.location.href + '&ajax=1&_=' + new Date().getTime())
        .then(response => response.text())
        .then(html => {
            // This would require more sophisticated DOM updating
            // For now, we'll do a full page reload
            window.location.reload();
        })
        .catch(error => {
            console.error('Refresh failed:', error);
            // Fallback to full reload
            window.location.reload();
        });
}

function forceLogout(adminId) {
    if (confirm('Are you sure you want to force logout this admin?')) {
        window.location.href = window.location.href + '&force_logout=1&admin_id=' + adminId;
    }
}

// Update last update time every second
function updateTimers() {
    document.querySelectorAll('.last-active-time').forEach(element => {
        const timeText = element.textContent;
        if (timeText.includes('sec ago')) {
            const seconds = parseInt(timeText);
            element.textContent = (seconds + 1) + ' sec ago';
        }
    });
}

// Start auto-refresh when page loads
document.addEventListener('DOMContentLoaded', function() {
    startAutoRefresh();
    setInterval(updateTimers, 1000);
    
    // Refresh when page becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            refreshCountdown = 1;
        }
    });
});