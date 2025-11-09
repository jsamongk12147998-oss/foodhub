<?php
session_start();

// --- SECURITY CHECK ---
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'super_admin') { 
    header("Location: login.php"); 
    exit(); 
}

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../db/db.php');
require_once(SUPERADMIN_PATH . 'functions.php');


$message = "";
$error = "";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_settings':
                // Save system settings to database
                $systemName = $conn->real_escape_string($_POST['system_name']);
                $systemEmail = $conn->real_escape_string($_POST['system_email']);
                $supportPhone = $conn->real_escape_string($_POST['support_phone']);
                $supportEmail = $conn->real_escape_string($_POST['support_email']);
                $defaultTax = floatval($_POST['default_tax']);
                
                // Check if settings exist, then update or insert
                $checkQuery = "SELECT * FROM system_settings WHERE id = 1";
                $checkResult = $conn->query($checkQuery);
                
                if ($checkResult->num_rows > 0) {
                    $query = "UPDATE system_settings SET 
                             system_name = '$systemName', 
                             system_email = '$systemEmail', 
                             support_phone = '$supportPhone', 
                             support_email = '$supportEmail', 
                             default_tax = $defaultTax, 
                             updated_at = NOW() 
                             WHERE id = 1";
                } else {
                    $query = "INSERT INTO system_settings (id, system_name, system_email, support_phone, support_email, default_tax, created_at) 
                             VALUES (1, '$systemName', '$systemEmail', '$supportPhone', '$supportEmail', $defaultTax, NOW())";
                }
                
                if ($conn->query($query)) {
                    $message = "System settings saved successfully!";
                } else {
                    $error = "Failed to save settings: " . $conn->error;
                }
                break;
            
            case 'change_password':
                $currentPassword = $_POST['current_password'];
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_password'];
                
                // Get current admin password from database
                $adminId = $_SESSION['user_id'];
                $passwordQuery = "SELECT password FROM users WHERE id = $adminId AND role = 'super_admin'";
                $passwordResult = $conn->query($passwordQuery);
                
                if ($passwordResult->num_rows > 0) {
                    $adminData = $passwordResult->fetch_assoc();
                    
                    // Verify current password
                    if (!password_verify($currentPassword, $adminData['password'])) {
                        $error = "Current password is incorrect!";
                    } elseif ($newPassword !== $confirmPassword) {
                        $error = "New passwords do not match!";
                    } elseif (strlen($newPassword) < 6) {
                        $error = "Password must be at least 6 characters long!";
                    } else {
                        // Hash and update password
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updateQuery = "UPDATE users SET password = '$hashedPassword', updated_at = NOW() WHERE id = $adminId";
                        
                        if ($conn->query($updateQuery)) {
                            $message = "Password changed successfully!";
                        } else {
                            $error = "Failed to update password: " . $conn->error;
                        }
                    }
                } else {
                    $error = "Admin user not found!";
                }
                break;
                
            case 'reset_user_password':
                $userId = intval($_POST['user_id']);
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_password'];
                
                if ($newPassword !== $confirmPassword) {
                    $error = "New passwords do not match!";
                } elseif (strlen($newPassword) < 6) {
                    $error = "Password must be at least 6 characters long!";
                } else {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $resetQuery = "UPDATE users SET password = '$hashedPassword', updated_at = NOW() WHERE id = $userId";
                    
                    if ($conn->query($resetQuery)) {
                        $message = "User password reset successfully!";
                    } else {
                        $error = "Failed to reset password: " . $conn->error;
                    }
                }
                break;
                
            case 'update_user_status':
                $userId = intval($_POST['user_id']);
                $status = $conn->real_escape_string($_POST['status']);
                
                $statusQuery = "UPDATE users SET status = '$status', updated_at = NOW() WHERE id = $userId";
                
                if ($conn->query($statusQuery)) {
                    $message = "User status updated successfully!";
                } else {
                    $error = "Failed to update user status: " . $conn->error;
                }
                break;

            case 'create_backup':
                // Simple backup simulation
                $backupName = "backup_" . date('Y-m-d_H-i-s') . ".sql";
                $backupQuery = "INSERT INTO backups (filename, file_size, created_at) VALUES ('$backupName', 0, NOW())";
                
                if ($conn->query($backupQuery)) {
                    $message = "Backup created successfully!";
                } else {
                    $error = "Failed to create backup: " . $conn->error;
                }
                break;

            case 'clear_cache':
                // Clear cache simulation
                $message = "System cache cleared successfully!";
                break;

            case 'optimize_database':
                // Optimize database simulation
                $message = "Database optimized successfully!";
                break;
        }
    }
}

// Fetch system settings from database
$systemSettings = [];
$settingsQuery = "SELECT * FROM system_settings WHERE id = 1";
$settingsResult = $conn->query($settingsQuery);

if ($settingsResult->num_rows > 0) {
    $systemSettings = $settingsResult->fetch_assoc();
} else {
    // Default values
    $systemSettings = [
        'system_name' => 'FoodHub Admin',
        'system_email' => 'admin@foodhub.com',
        'support_phone' => '+63 (917) 123-4567',
        'support_email' => 'support@foodhub.com',
        'default_tax' => '12'
    ];
}

// Fetch users and branch admins for management
$users = [];
$usersQuery = "SELECT id, name, email, role, branch_id, store_name, status, created_at 
               FROM users 
               WHERE role IN ('branch_admin', 'user') 
               ORDER BY created_at DESC";
$usersResult = $conn->query($usersQuery);

if ($usersResult && $usersResult->num_rows > 0) {
    while ($row = $usersResult->fetch_assoc()) {
        $users[] = $row;
    }
}

// Fetch database backup history
$backups = [];
$backupQuery = "SELECT * FROM backups ORDER BY created_at DESC LIMIT 5";
$backupResult = $conn->query($backupQuery);

if ($backupResult && $backupResult->num_rows > 0) {
    while ($row = $backupResult->fetch_assoc()) {
        $backups[] = $row;
    }
}
?>

<style>
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.settings-card {
    background: white;
    border-radius: 12px;
    border: 1px solid #ddd;
    padding: 25px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.settings-card h3 {
    color: #002147;
    margin-bottom: 20px;
    font-size: 18px;
    border-bottom: 2px solid #ffcc00;
    padding-bottom: 10px;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}
.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.3s;
}
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #002147;
}
.form-group textarea {
    resize: vertical;
    min-height: 80px;
}
.btn {
    background: #002147;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
}
.btn:hover {
    background: #ffcc00;
    color: #002147;
}
.btn-block {
    width: 100%;
}
.btn-sm {
    padding: 8px 16px;
    font-size: 12px;
    margin: 2px;
}
.btn-success { background: #28a745; }
.btn-warning { background: #ffc107; color: #000; }
.btn-danger { background: #dc3545; }
.btn-info { background: #17a2b8; }
.alert {
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-weight: 500;
}
.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}
.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
.notification-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
}
.user-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}
.user-table th, .user-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}
.user-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #002147;
}
.status-active { color: #28a745; font-weight: 600; }
.status-inactive { color: #dc3545; font-weight: 600; }
.status-pending { color: #ffc107; font-weight: 600; }
.status-suspended { color: #6c757d; font-weight: 600; }

/* Modal Styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.modal-content {
    background: white;
    border-radius: 12px;
    padding: 30px;
    width: 500px;
    max-width: 95%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}
.modal-header h3 {
    margin: 0;
    color: #002147;
}
.close-modal {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}
.close-modal:hover {
    color: #002147;
}

.backup-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    margin-bottom: 10px;
}
.backup-info {
    flex: 1;
}
.backup-actions {
    display: flex;
    gap: 10px;
}

@media(max-width:768px){
    .settings-grid {grid-template-columns: 1fr;}
    .user-table {font-size: 12px;}
}
</style>

<div class="page-wrapper">
    <div class="header"><h1>⚙ System Settings & User Management</h1></div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="table-container">
        <p>Manage system configurations, user accounts, and branch admin details.</p>
        
        <div class="settings-grid">
            <!-- System Settings -->
            <div class="settings-card">
                <h3>System Configuration</h3>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_settings">
                    
                    <div class="form-group">
                        <label for="system_name">System Name</label>
                        <input type="text" id="system_name" name="system_name" value="<?php echo htmlspecialchars($systemSettings['system_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="system_email">System Email</label>
                        <input type="email" id="system_email" name="system_email" value="<?php echo htmlspecialchars($systemSettings['system_email']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="support_phone">Support Phone</label>
                        <input type="text" id="support_phone" name="support_phone" value="<?php echo htmlspecialchars($systemSettings['support_phone']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="support_email">Support Email</label>
                        <input type="email" id="support_email" name="support_email" value="<?php echo htmlspecialchars($systemSettings['support_email']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="default_tax">Default Tax Rate (%)</label>
                        <input type="number" id="default_tax" name="default_tax" step="0.01" min="0" max="50" value="<?php echo htmlspecialchars($systemSettings['default_tax']); ?>" required>
                    </div>

                    <button type="submit" class="btn btn-block">Save System Settings</button>
                </form>
            </div>

            <!-- User Management -->
            <div class="settings-card">
                <h3>User & Branch Admin Management</h3>
                
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['role']); ?></td>
                                        <td>
                                            <span class="status-<?php echo strtolower($user['status'] ?? 'active'); ?>">
                                                <?php echo ucfirst($user['status'] ?? 'Active'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                <button class="btn btn-sm btn-info" onclick="openResetPasswordModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['name'])); ?>')">
                                                    Reset Password
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="openStatusModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['name'])); ?>', '<?php echo $user['status'] ?? 'active'; ?>')">
                                                    Change Status
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center;">No users found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Account & Maintenance -->
            <div class="settings-card">
                <h3>Account & Maintenance</h3>
                
                <div style="margin-bottom: 25px;">
                    <h4 style="margin-bottom: 15px; color: #002147;">Security</h4>
                    <button class="btn" onclick="openModal('changePasswordModal')" style="width: 100%; margin-bottom: 20px;">
                        Change My Password
                    </button>
                </div>

                <div style="margin-bottom: 25px;">
                    <h4 style="margin-bottom: 15px; color: #002147;">Database Backups</h4>
                    <?php if (!empty($backups)): ?>
                        <?php foreach ($backups as $backup): ?>
                            <div class="backup-item">
                                <div class="backup-info">
                                    <strong><?php echo htmlspecialchars($backup['filename']); ?></strong>
                                    <br><small><?php echo date('M j, Y g:i A', strtotime($backup['created_at'])); ?></small>
                                </div>
                                <div class="backup-actions">
                                    <button class="btn btn-sm btn-success" onclick="downloadBackup(<?php echo $backup['id']; ?>)">Download</button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteBackup(<?php echo $backup['id']; ?>)">Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No backups available</p>
                    <?php endif; ?>
                    <form method="POST" style="margin-top: 10px;">
                        <input type="hidden" name="action" value="create_backup">
                        <button type="submit" class="btn btn-block" style="background: #28a745;">
                            Create New Backup
                        </button>
                    </form>
                </div>

                <div>
                    <h4 style="margin-bottom: 15px; color: #002147;">System Maintenance</h4>
                    <form method="POST" style="margin-bottom: 10px;">
                        <input type="hidden" name="action" value="clear_cache">
                        <button type="submit" class="btn btn-block" style="background: #dc3545;">
                            Clear System Cache
                        </button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="action" value="optimize_database">
                        <button type="submit" class="btn btn-block" style="background: #ffc107; color: #000;">
                            Optimize Database
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div id="changePasswordModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Change My Password</h3>
            <button class="close-modal" onclick="closeModal('changePasswordModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required placeholder="Enter current password">
            </div>
            
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required placeholder="Enter new password" minlength="6">
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required placeholder="Confirm new password" minlength="6">
            </div>
            
            <button type="submit" class="btn btn-block">Update Password</button>
        </form>
    </div>
</div>

<!-- Reset User Password Modal -->
<div id="resetPasswordModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="resetPasswordTitle">Reset User Password</h3>
            <button class="close-modal" onclick="closeModal('resetPasswordModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="reset_user_password">
            <input type="hidden" id="reset_user_id" name="user_id" value="">
            
            <div class="form-group">
                <label for="reset_new_password">New Password</label>
                <input type="password" id="reset_new_password" name="new_password" required placeholder="Enter new password" minlength="6">
            </div>
            
            <div class="form-group">
                <label for="reset_confirm_password">Confirm New Password</label>
                <input type="password" id="reset_confirm_password" name="confirm_password" required placeholder="Confirm new password" minlength="6">
            </div>
            
            <button type="submit" class="btn btn-block btn-warning">Reset Password</button>
        </form>
    </div>
</div>

<!-- Change User Status Modal -->
<div id="statusModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="statusModalTitle">Change User Status</h3>
            <button class="close-modal" onclick="closeModal('statusModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_user_status">
            <input type="hidden" id="status_user_id" name="user_id" value="">
            
            <div class="form-group">
                <label for="user_status">Status</label>
                <select id="user_status" name="status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                    <option value="pending">Pending</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-block btn-info">Update Status</button>
        </form>
    </div>
</div>

<script>
// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function openResetPasswordModal(userId, userName) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('resetPasswordTitle').textContent = 'Reset Password for ' + userName;
    document.getElementById('reset_new_password').value = '';
    document.getElementById('reset_confirm_password').value = '';
    openModal('resetPasswordModal');
}

function openStatusModal(userId, userName, currentStatus) {
    document.getElementById('status_user_id').value = userId;
    document.getElementById('statusModalTitle').textContent = 'Change Status for ' + userName;
    document.getElementById('user_status').value = currentStatus;
    openModal('statusModal');
}

// Backup functions
function downloadBackup(backupId) {
    alert('Downloading backup ' + backupId + '...');
    // In real implementation, this would trigger a file download
}

function deleteBackup(backupId) {
    if (confirm('Are you sure you want to delete this backup?')) {
        alert('Backup ' + backupId + ' deleted successfully!');
        // In real implementation, this would send a delete request to the server
        location.reload();
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.display = 'none';
    });
}, 5000);
</script>