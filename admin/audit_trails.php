<?php
/**
 * Veripool Reservation System - Admin Audit Trails Page
 * View all system activities and logs
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

// Get filter parameters
$user_filter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$table_filter = isset($_GET['table']) ? $_GET['table'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Build query
$where = "WHERE 1=1";
$params = [];

if ($user_filter > 0) {
    $where .= " AND a.user_id = :user_id";
    $params['user_id'] = $user_filter;
}

if (!empty($action_filter)) {
    $where .= " AND a.action LIKE :action";
    $params['action'] = '%' . $action_filter . '%';
}

if (!empty($table_filter)) {
    $where .= " AND a.table_name = :table_name";
    $params['table_name'] = $table_filter;
}

if (isset($_GET['date_from']) && isset($_GET['date_to'])) {
    $where .= " AND DATE(a.created_at) BETWEEN :date_from AND :date_to";
    $params['date_from'] = $date_from;
    $params['date_to'] = $date_to;
}

// Get audit trails with user details
$audit_trails = $db->getRows("
    SELECT a.*, u.username, u.full_name, u.role
    FROM audit_trails a
    LEFT JOIN users u ON a.user_id = u.id
    $where
    ORDER BY a.created_at DESC
    LIMIT 1000
", $params);

// Get statistics
$total_logs = $db->getValue("SELECT COUNT(*) FROM audit_trails");
$today_logs = $db->getValue("SELECT COUNT(*) FROM audit_trails WHERE DATE(created_at) = CURDATE()");
$unique_users = $db->getValue("SELECT COUNT(DISTINCT user_id) FROM audit_trails WHERE user_id IS NOT NULL");
$unique_actions = $db->getValue("SELECT COUNT(DISTINCT action) FROM audit_trails");

// Get unique actions for filter dropdown
$unique_actions_list = $db->getRows("SELECT DISTINCT action FROM audit_trails ORDER BY action");

// Get unique tables for filter dropdown
$unique_tables_list = $db->getRows("SELECT DISTINCT table_name FROM audit_trails WHERE table_name IS NOT NULL ORDER BY table_name");

// Get users for filter dropdown
$users_list = $db->getRows("SELECT id, username, full_name FROM users WHERE role IN ('admin', 'super_admin', 'staff') ORDER BY username");

// Handle clear logs (super admin only)
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $current_user['role'] === 'super_admin') {
    if (isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
        $days = isset($_POST['days']) ? (int)$_POST['days'] : 30;
        
        // Delete logs older than specified days
        $db->delete('audit_trails', 'created_at < DATE_SUB(NOW(), INTERVAL :days DAY)', ['days' => $days]);
        
        $message = "Audit logs older than $days days have been cleared.";
        $message_type = 'success';
        
        // Log this action
        logAudit($_SESSION['user_id'], 'CLEAR_AUDIT_LOGS', 'audit_trails', 0, ['days' => $days]);
        
        // Refresh data
        $audit_trails = $db->getRows("
            SELECT a.*, u.username, u.full_name, u.role
            FROM audit_trails a
            LEFT JOIN users u ON a.user_id = u.id
            $where
            ORDER BY a.created_at DESC
            LIMIT 1000
        ", $params);
        
        $total_logs = $db->getValue("SELECT COUNT(*) FROM audit_trails");
        $today_logs = $db->getValue("SELECT COUNT(*) FROM audit_trails WHERE DATE(created_at) = CURDATE()");
    }
}

// Handle export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $filename = 'audit_trails_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['Date/Time', 'User', 'Role', 'Action', 'Table', 'Record ID', 'IP Address', 'Details']);
    
    foreach ($audit_trails as $log) {
        $details = [];
        if ($log['old_data']) $details[] = 'Old: ' . substr($log['old_data'], 0, 100);
        if ($log['new_data']) $details[] = 'New: ' . substr($log['new_data'], 0, 100);
        
        fputcsv($output, [
            date('Y-m-d H:i:s', strtotime($log['created_at'])),
            $log['username'] ?: 'System',
            $log['role'] ?: 'N/A',
            $log['action'],
            $log['table_name'] ?: '-',
            $log['record_id'] ?: '-',
            $log['ip_address'] ?: '-',
            implode(' | ', $details)
        ]);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trails - Admin Dashboard</title>
     <!-- POP UP ICON  -->
     <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">
    
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .audit-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .audit-stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
        }
        
        .audit-stat-card .number {
            font-size: 2rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .audit-stat-card .label {
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
        
        .filter-input {
            width: 100%;
            padding: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
        }
        
        .action-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .action-login { background: #cce5ff; color: #004085; }
        .action-logout { background: #e2e3e5; color: #383d41; }
        .action-create { background: #d4edda; color: #155724; }
        .action-update { background: #fff3cd; color: #856404; }
        .action-delete { background: #f8d7da; color: #721c24; }
        
        .details-popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            z-index: 2000;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .details-popup.active {
            display: block;
        }
        
        .details-popup .popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .details-popup .popup-header h3 {
            color: #102C57;
        }
        
        .details-popup .close-popup {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .details-popup .close-popup:hover {
            color: #dc3545;
        }
        
        .details-popup pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 0.85rem;
        }
        
        .view-details {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.75rem;
        }
        
        .view-details:hover {
            background: #138496;
        }
        
        .clear-logs-section {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: <?php echo $current_user['role'] === 'super_admin' ? 'block' : 'none'; ?>;
        }
        
        .export-btn {
            background: #28a745;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .export-btn:hover {
            background: #218838;
        }
        
        .ip-address {
            font-family: monospace;
            font-size: 0.85rem;
            color: #666;
        }
        
        @media (max-width: 1024px) {
            .audit-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .audit-stats {
                grid-template-columns: 1fr;
            }
            
            .filter-section {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<?php include BASE_PATH . '/includes/sidebar.php'; ?>
    
    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i> Menu
    </button>
    

    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <h1>
                <i class="fas fa-history"></i>
                Audit Trails
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
        
        <!-- Audit Statistics -->
        <div class="audit-stats">
            <div class="audit-stat-card">
                <div class="number"><?php echo number_format($total_logs); ?></div>
                <div class="label">Total Logs</div>
            </div>
            <div class="audit-stat-card">
                <div class="number"><?php echo number_format($today_logs); ?></div>
                <div class="label">Today's Logs</div>
            </div>
            <div class="audit-stat-card">
                <div class="number"><?php echo $unique_users; ?></div>
                <div class="label">Active Users</div>
            </div>
            <div class="audit-stat-card">
                <div class="number"><?php echo $unique_actions; ?></div>
                <div class="label">Action Types</div>
            </div>
        </div>
        
        <!-- Clear Logs Section (Super Admin Only) -->
        <?php if ($current_user['role'] === 'super_admin'): ?>
        <div class="clear-logs-section">
            <form method="POST" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;" onsubmit="return confirm('Are you sure you want to clear old logs? This action cannot be undone.');">
                <input type="hidden" name="action" value="clear_logs">
                <label for="days" style="color: #856404;"><i class="fas fa-exclamation-triangle"></i> Clear logs older than:</label>
                <select name="days" id="days" class="filter-input" style="width: auto;">
                    <option value="30">30 days</option>
                    <option value="60">60 days</option>
                    <option value="90">90 days</option>
                    <option value="180">180 days</option>
                    <option value="365">1 year</option>
                </select>
                <button type="submit" class="btn btn-warning" style="background: #ffc107; color: #102C57;">
                    <i class="fas fa-trash-alt"></i> Clear Old Logs
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; width: 100%; align-items: flex-end;">
                <div class="filter-group">
                    <label>User</label>
                    <select name="user_id" class="filter-input">
                        <option value="0">All Users</option>
                        <?php foreach ($users_list as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $user_filter == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['username']); ?> (<?php echo htmlspecialchars($u['full_name']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Action</label>
                    <input type="text" name="action" class="filter-input" placeholder="Search action..." value="<?php echo htmlspecialchars($action_filter); ?>">
                </div>
                
                <div class="filter-group">
                    <label>Table</label>
                    <select name="table" class="filter-input">
                        <option value="">All Tables</option>
                        <?php foreach ($unique_tables_list as $t): ?>
                        <option value="<?php echo $t['table_name']; ?>" <?php echo $table_filter == $t['table_name'] ? 'selected' : ''; ?>>
                            <?php echo $t['table_name']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
                </div>
                
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
                </div>
                
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
                <div class="filter-group">
                    <a href="audit_trails.php" class="btn btn-outline">Clear Filters</a>
                </div>
                <div class="filter-group">
                    <a href="?export=csv&<?php echo http_build_query($_GET); ?>" class="export-btn">
                        <i class="fas fa-download"></i> Export CSV
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Audit Trails Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> System Activity Logs</h3>
                <span class="badge"><?php echo count($audit_trails); ?> records</span>
            </div>
            <div class="card-body">
                <?php if (empty($audit_trails)): ?>
                    <p style="text-align: center; color: #666; padding: 40px;">No audit logs found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                    <th>Table</th>
                                    <th>Record ID</th>
                                    <th>IP Address</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($audit_trails as $log): 
                                    $action_class = 'action-';
                                    if (strpos($log['action'], 'LOGIN') !== false) $action_class .= 'login';
                                    elseif (strpos($log['action'], 'LOGOUT') !== false) $action_class .= 'logout';
                                    elseif (strpos($log['action'], 'CREATE') !== false || strpos($log['action'], 'ADD') !== false || strpos($log['action'], 'NEW') !== false) $action_class .= 'create';
                                    elseif (strpos($log['action'], 'UPDATE') !== false || strpos($log['action'], 'EDIT') !== false) $action_class .= 'update';
                                    elseif (strpos($log['action'], 'DELETE') !== false) $action_class .= 'delete';
                                    else $action_class = 'action-login';
                                ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo $log['username'] ?: 'System'; ?></strong>
                                        <?php if ($log['full_name']): ?>
                                            <br><small><?php echo htmlspecialchars($log['full_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['role']): ?>
                                            <span class="role-badge role-<?php echo $log['role']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $log['role'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="role-badge role-guest">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="action-badge <?php echo $action_class; ?>">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $log['table_name'] ?: '-'; ?></td>
                                    <td><?php echo $log['record_id'] ?: '-'; ?></td>
                                    <td class="ip-address"><?php echo $log['ip_address'] ?: '-'; ?></td>
                                    <td>
                                        <?php if ($log['old_data'] || $log['new_data']): ?>
                                            <button class="view-details" onclick='showDetails(<?php echo json_encode([
                                                'old' => $log['old_data'] ? json_decode($log['old_data'], true) : null,
                                                'new' => $log['new_data'] ? json_decode($log['new_data'], true) : null,
                                                'action' => $log['action'],
                                                'user' => $log['username'],
                                                'time' => $log['created_at']
                                            ]); ?>)'>
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        <?php else: ?>
                                            <span style="color: #999;">No data</span>
                                        <?php endif; ?>
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
    
    <!-- Details Popup -->
    <div class="details-popup" id="detailsPopup">
        <div class="popup-header">
            <h3><i class="fas fa-info-circle"></i> <span id="popupTitle">Record Details</span></h3>
            <button class="close-popup" onclick="closePopup()">&times;</button>
        </div>
        <div id="popupContent"></div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }
        
        function showDetails(data) {
            let html = '';
            
            html += '<p><strong>Action:</strong> ' + data.action + '</p>';
            html += '<p><strong>User:</strong> ' + (data.user || 'System') + '</p>';
            html += '<p><strong>Time:</strong> ' + data.time + '</p>';
            
            if (data.old) {
                html += '<h4 style="margin: 15px 0 5px; color: #856404;">Old Data:</h4>';
                html += '<pre>' + JSON.stringify(data.old, null, 2) + '</pre>';
            }
            
            if (data.new) {
                html += '<h4 style="margin: 15px 0 5px; color: #155724;">New Data:</h4>';
                html += '<pre>' + JSON.stringify(data.new, null, 2) + '</pre>';
            }
            
            document.getElementById('popupContent').innerHTML = html;
            document.getElementById('detailsPopup').classList.add('active');
        }
        
        function closePopup() {
            document.getElementById('detailsPopup').classList.remove('active');
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
        
        // Close popup when clicking outside
        window.onclick = function(event) {
            const popup = document.getElementById('detailsPopup');
            if (event.target == popup) {
                closePopup();
            }
        }
    </script>
</body>
</html>