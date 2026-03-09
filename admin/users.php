<?php
/**
 * Veripool Reservation System - Admin Users Page
 * Edit, view, and delete users (No add functionality)
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define base path
define('BASE_PATH', __DIR__ . '/..');
define('BASE_URL', 'http://localhost/veripool');

// Include required files
require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/Database.php';
require_once BASE_PATH . '/includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get current user
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Get filter from URL
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build query based on filters
$where = "WHERE 1=1";
$params = [];

if ($role_filter != 'all') {
    $where .= " AND role = :role";
    $params['role'] = $role_filter;
}

if ($status_filter != 'all') {
    $where .= " AND status = :status";
    $params['status'] = $status_filter;
}

// Get all users
$users = $db->getRows("
    SELECT * FROM users 
    $where
    ORDER BY created_at DESC
", $params);

// Get statistics
$total_users = $db->getValue("SELECT COUNT(*) FROM users");
$total_guests = $db->getValue("SELECT COUNT(*) FROM users WHERE role = 'guest'");
$total_staff = $db->getValue("SELECT COUNT(*) FROM users WHERE role = 'staff'");
$total_admins = $db->getValue("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'super_admin')");
$total_active = $db->getValue("SELECT COUNT(*) FROM users WHERE status = 'active'");
$total_inactive = $db->getValue("SELECT COUNT(*) FROM users WHERE status = 'inactive'");

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Update user (Edit)
        if ($_POST['action'] === 'update_user' && isset($_POST['user_id'])) {
            $user_id = (int)$_POST['user_id'];
            $full_name = sanitize($_POST['full_name']);
            $phone = sanitize($_POST['phone']) ?: null;
            $address = sanitize($_POST['address']) ?: null;
            $role = sanitize($_POST['role']);
            $status = sanitize($_POST['status']);
            
            $update_data = [
                'full_name' => $full_name,
                'phone' => $phone,
                'address' => $address,
                'role' => $role,
                'status' => $status
            ];
            
            // Update password if provided
            if (!empty($_POST['new_password'])) {
                if (strlen($_POST['new_password']) < 6) {
                    $message = "Password must be at least 6 characters";
                    $message_type = 'error';
                } else {
                    $update_data['password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                }
            }
            
            if (empty($message)) {
                $db->update('users', $update_data, 'id = :id', ['id' => $user_id]);
                $message = "User updated successfully";
                $message_type = 'success';
                logAudit($_SESSION['user_id'], 'UPDATE_USER', 'users', $user_id, $update_data);
            }
        }
        
        // Delete user - FIXED VERSION
        if ($_POST['action'] === 'delete_user' && isset($_POST['user_id'])) {
            $user_id = (int)$_POST['user_id'];
            
            // Don't allow deleting yourself
            if ($user_id == $_SESSION['user_id']) {
                $message = "You cannot delete your own account";
                $message_type = 'error';
            } else {
                // Start transaction
                $db->beginTransaction();
                
                try {
                    // Check if user has reservations
                    $has_reservations = $db->getValue("SELECT COUNT(*) FROM reservations WHERE user_id = ?", [$user_id]);
                    if ($has_reservations > 0) {
                        throw new Exception("Cannot delete user with existing reservations");
                    }
                    
                    // Check if user has payments
                    $has_payments = $db->getValue("
                        SELECT COUNT(*) FROM payments WHERE created_by = ?", [$user_id]
                    );
                    
                    // Check if user has audit trails
                    $has_audit_trails = $db->getValue("SELECT COUNT(*) FROM audit_trails WHERE user_id = ?", [$user_id]);
                    
                    // If there are audit trails, either delete them or update them
                    if ($has_audit_trails > 0) {
                        // Option 1: Delete audit trails first (if you want to completely remove user)
                        $db->delete('audit_trails', 'user_id = :user_id', ['user_id' => $user_id]);
                        
                        // Option 2: Or update them to set user_id to NULL (if you want to keep history)
                        // $db->update('audit_trails', ['user_id' => null], 'user_id = :user_id', ['user_id' => $user_id]);
                    }
                    
                    // If user created payments, either delete them or update them
                    if ($has_payments > 0) {
                        // Option: Set created_by to NULL
                        $db->update('payments', ['created_by' => null], 'created_by = :user_id', ['user_id' => $user_id]);
                    }
                    
                    // Finally delete the user
                    $db->delete('users', 'id = :id', ['id' => $user_id]);
                    
                    $db->commit();
                    
                    $message = "User deleted successfully";
                    $message_type = 'success';
                    logAudit($_SESSION['user_id'], 'DELETE_USER', 'users', $user_id);
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $message = "Error deleting user: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
        
        // Refresh users data
        $users = $db->getRows("
            SELECT * FROM users 
            $where
            ORDER BY created_at DESC
        ", $params);
    }
}

// Get user for editing if requested
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_GET['edit']]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - Admin Dashboard</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">
    
      <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/sidebar.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .user-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .user-stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
        }
        
        .user-stat-card .number {
            font-size: 2rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .user-stat-card .label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            color: #102C57;
            font-weight: 500;
        }
        
        .filter-select {
            width: 100%;
            padding: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .modal-header h3 {
            color: #102C57;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .modal-close:hover {
            color: #dc3545;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #102C57;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
        }
        
        .form-control:focus {
            border-color: #1679AB;
            outline: none;
        }
        
        .btn-submit {
            background: #1679AB;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            width: 100%;
        }
        
        .btn-submit:hover {
            background: #102C57;
        }
        
        .role-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .role-super_admin { background: #102C57; color: #FFCBCB; }
        .role-admin { background: #1679AB; color: white; }
        .role-staff { background: #FFB1B1; color: #102C57; }
        .role-guest { background: #FFCBCB; color: #102C57; }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-icon {
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8rem;
        }
        
        .btn-edit { background: #17a2b8; color: white; }
        .btn-delete { background: #dc3545; color: white; }
        
        .no-add-badge {
            background: #6c757d;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-left: 10px;
        }
        
        .info-message {
            background: #cce5ff;
            color: #004085;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .warning-message {
            background: #fff3cd;
            color: #856404;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            .user-stats {
                grid-template-columns: 1fr 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
      <?php include '../includes/sidebar.php'; ?>
    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i> Menu
    </button>
    
   
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <h1>
                <i class="fas fa-users"></i>
                Users Management
              
            </h1>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        
        
       
        
        <!-- User Statistics -->
        <div class="user-stats">
            <div class="user-stat-card">
                <div class="number"><?php echo $total_users; ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="user-stat-card">
                <div class="number"><?php echo $total_guests; ?></div>
                <div class="label">Guests</div>
            </div>
            <div class="user-stat-card">
                <div class="number"><?php echo $total_staff; ?></div>
                <div class="label">Staff</div>
            </div>
            <div class="user-stat-card">
                <div class="number"><?php echo $total_admins; ?></div>
                <div class="label">Admins</div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; width: 100%; align-items: flex-end;">
                <div class="filter-group">
                    <label>Role</label>
                    <select name="role" class="filter-select">
                        <option value="all" <?php echo $role_filter == 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <option value="guest" <?php echo $role_filter == 'guest' ? 'selected' : ''; ?>>Guest</option>
                        <option value="staff" <?php echo $role_filter == 'staff' ? 'selected' : ''; ?>>Staff</option>
                        <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="super_admin" <?php echo $role_filter == 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" class="filter-select">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
                <div class="filter-group">
                    <a href="users.php" class="btn btn-outline">Clear Filters</a>
                </div>
            </form>
        </div>
        
        <!-- Users Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Users List</h3>
                <span class="badge"><?php echo count($users); ?> users</span>
            </div>
            <div class="card-body">
                <?php if (empty($users)): ?>
                    <p style="text-align: center; color: #666; padding: 40px;">No users found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>#<?php echo $user['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo $user['phone'] ?: 'N/A'; ?></td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $user['status']; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : 'Never'; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $user['id']; ?>" class="btn-icon btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?\n\nThis will also delete all associated audit trails.');">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn-icon btn-delete" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <?php if ($edit_user): ?>
    <div class="modal active" id="editUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit User</h3>
                <a href="users.php" class="modal-close">&times;</a>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_user['username']); ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($edit_user['email']); ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" name="full_name" id="full_name" class="form-control" 
                           value="<?php echo htmlspecialchars($edit_user['full_name']); ?>" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" name="phone" id="phone" class="form-control" 
                               value="<?php echo htmlspecialchars($edit_user['phone']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Role</label>
                        <select name="role" id="role" class="form-control" required>
                            <option value="guest" <?php echo $edit_user['role'] == 'guest' ? 'selected' : ''; ?>>Guest</option>
                            <option value="staff" <?php echo $edit_user['role'] == 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="admin" <?php echo $edit_user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <?php if ($current_user['role'] == 'super_admin'): ?>
                            <option value="super_admin" <?php echo $edit_user['role'] == 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="form-control" required>
                            <option value="active" <?php echo $edit_user['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $edit_user['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" name="address" id="address" class="form-control" 
                               value="<?php echo htmlspecialchars($edit_user['address']); ?>">
                    </div>
                </div>
                
                <h4 style="color: #102C57; margin: 20px 0 10px;">Change Password (Optional)</h4>
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Leave blank to keep current">
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Update User
                </button>
                
                <div style="text-align: center; margin-top: 15px;">
                    <a href="users.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
    </script>
</body>
</html>