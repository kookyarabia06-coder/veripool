<?php
/**
 * Veripool Reservation System - Admin Pools Page
 * Manage the two pools: Ernesto (Private) and Pavilion (Public)
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

// Get the two pools (should always be exactly 2)
$pools = $db->getRows("SELECT * FROM pools ORDER BY FIELD(name, 'Ernesto', 'Pavilion')");

// If pools don't exist, create them
if (count($pools) < 2) {
    // Check if Ernesto exists
    $ernesto = $db->getRow("SELECT * FROM pools WHERE name = 'Ernesto'");
    if (!$ernesto) {
        $db->insert('pools', [
            'name' => 'Ernesto',
            'type' => 'private',
            'capacity' => 20,
            'status' => 'open',
            'operating_hours' => '7:00 AM - 8:00 PM',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    // Check if Pavilion exists
    $pavilion = $db->getRow("SELECT * FROM pools WHERE name = 'Pavilion'");
    if (!$pavilion) {
        $db->insert('pools', [
            'name' => 'Pavilion',
            'type' => 'public',
            'capacity' => 50,
            'status' => 'open',
            'operating_hours' => '7:00 AM - 8:00 PM',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    // Refresh pools
    $pools = $db->getRows("SELECT * FROM pools ORDER BY FIELD(name, 'Ernesto', 'Pavilion')");
}

// First, let's check what columns exist in the reservations table
try {
    $columns = $db->getRows("SHOW COLUMNS FROM reservations");
    $reservation_columns = [];
    foreach ($columns as $column) {
        $reservation_columns[] = $column['Field'];
    }
} catch (Exception $e) {
    $reservation_columns = [];
}

// Determine which date column to use
$date_column = 'reservation_date'; // default
if (!in_array('reservation_date', $reservation_columns)) {
    if (in_array('booking_date', $reservation_columns)) {
        $date_column = 'booking_date';
    } elseif (in_array('reservation_date', $reservation_columns)) {
        $date_column = 'reservation_date';
    } elseif (in_array('date', $reservation_columns)) {
        $date_column = 'date';
    } elseif (in_array('booking_date', $reservation_columns)) {
        $date_column = 'booking_date';
    } else {
        $date_column = null;
    }
}

// Get today's date
$today = date('Y-m-d');

// Get today's reservations with error handling
$reservations_today = [];
$pool_reservations = [
    'Ernesto' => [],
    'Pavilion' => []
];

// Get active reservations for pools
try {
    // Check if reservation_pools table exists
    $pools_table_exists = $db->getValue("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'reservation_pools'");
    
    if ($pools_table_exists) {
        $pool_reservations_data = $db->getRows("
            SELECT rp.*, p.name as pool_name, u.full_name as user_name, r.check_in_date
            FROM reservation_pools rp
            JOIN reservations r ON rp.reservation_id = r.id
            JOIN pools p ON rp.pool_id = p.id
            JOIN users u ON r.user_id = u.id
            WHERE r.check_in_date >= CURDATE() 
            AND r.status IN ('confirmed', 'checked_in')
            ORDER BY r.check_in_date, rp.created_at
        ");
        
        // Group by pool
        foreach ($pool_reservations_data as $res) {
            $pool_reservations[$res['pool_name']][] = $res;
        }
    }
} catch (Exception $e) {
    // Table doesn't exist, ignore
    error_log("reservation_pools table may not exist: " . $e->getMessage());
}

// Get statistics
$total_pools = count($pools);
$private_pools = $db->getValue("SELECT COUNT(*) FROM pools WHERE type = 'private'");
$public_pools = $db->getValue("SELECT COUNT(*) FROM pools WHERE type = 'public'");
$open_pools = $db->getValue("SELECT COUNT(*) FROM pools WHERE status = 'open'");
$closed_pools = $db->getValue("SELECT COUNT(*) FROM pools WHERE status = 'closed'");
$maintenance_pools = $db->getValue("SELECT COUNT(*) FROM pools WHERE status = 'maintenance'");

// Get total active reservations count
$total_reservations = array_sum(array_map('count', $pool_reservations));

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Update pool settings
        if ($_POST['action'] === 'update_pool' && isset($_POST['pool_id'])) {
            $pool_id = (int)$_POST['pool_id'];
            
            $update_data = [
                'capacity' => (int)$_POST['capacity'],
                'status' => sanitize($_POST['status']),
                'operating_hours' => sanitize($_POST['operating_hours'])
            ];
            
            // Only allow type update if it's one of the two pools
            $pool = $db->getRow("SELECT * FROM pools WHERE id = ?", [$pool_id]);
            if ($pool && in_array($pool['name'], ['Ernesto', 'Pavilion'])) {
                // Add type to update data if provided
                if (isset($_POST['type'])) {
                    $update_data['type'] = sanitize($_POST['type']);
                }
                
                $db->update('pools', $update_data, 'id = :id', ['id' => $pool_id]);
                
                $message = "Pool settings updated successfully";
                $message_type = 'success';
                logAudit($_SESSION['user_id'], 'UPDATE_POOL', 'pools', $pool_id, $update_data);
            } else {
                $message = "Invalid pool";
                $message_type = 'error';
            }
        }
        
        // Reset pool to default settings
        if ($_POST['action'] === 'reset_pool' && isset($_POST['pool_id'])) {
            $pool_id = (int)$_POST['pool_id'];
            $pool = $db->getRow("SELECT * FROM pools WHERE id = ?", [$pool_id]);
            
            if ($pool) {
                $default_data = [];
                if ($pool['name'] == 'Ernesto') {
                    $default_data = [
                        'capacity' => 20,
                        'type' => 'private',
                        'operating_hours' => '7:00 AM - 8:00 PM'
                    ];
                } else if ($pool['name'] == 'Pavilion') {
                    $default_data = [
                        'capacity' => 50,
                        'type' => 'public',
                        'operating_hours' => '7:00 AM - 8:00 PM'
                    ];
                }
                
                if (!empty($default_data)) {
                    $default_data['status'] = 'open';
                    $db->update('pools', $default_data, 'id = :id', ['id' => $pool_id]);
                    
                    $message = "Pool reset to default settings successfully";
                    $message_type = 'success';
                    logAudit($_SESSION['user_id'], 'RESET_POOL', 'pools', $pool_id);
                }
            }
        }
        
        // Delete pool
        if ($_POST['action'] === 'delete_pool' && isset($_POST['pool_id'])) {
            $pool_id = (int)$_POST['pool_id'];
            $pool = $db->getRow("SELECT * FROM pools WHERE id = ?", [$pool_id]);
            
            if ($pool) {
                // Check if pool has any active reservations
                $has_reservations = 0;
                try {
                    $has_reservations = $db->getValue("
                        SELECT COUNT(*) FROM reservation_pools rp
                        JOIN reservations r ON rp.reservation_id = r.id
                        WHERE rp.pool_id = ? AND r.status IN ('confirmed', 'checked_in')
                    ", [$pool_id]);
                } catch (Exception $e) {
                    // reservation_pools table might not exist
                    error_log("Error checking pool reservations: " . $e->getMessage());
                }
                
                if ($has_reservations > 0) {
                    $message = "Cannot delete pool with active reservations";
                    $message_type = 'error';
                } else {
                    // Don't allow deletion of the two main pools
                    if (in_array($pool['name'], ['Ernesto', 'Pavilion'])) {
                        $message = "Cannot delete the main pools (Ernesto and Pavilion)";
                        $message_type = 'error';
                    } else {
                        // Delete the pool
                        $db->delete('pools', 'id = :id', ['id' => $pool_id]);
                        $message = "Pool deleted successfully";
                        $message_type = 'success';
                        logAudit($_SESSION['user_id'], 'DELETE_POOL', 'pools', $pool_id);
                    }
                }
            }
        }
        
        // Refresh data
        header("Location: pools.php");
        exit;
    }
}

// Get pool for editing if requested
$edit_pool = null;
if (isset($_GET['edit'])) {
    $edit_pool = $db->getRow("SELECT * FROM pools WHERE id = ?", [$_GET['edit']]);
    // Only allow editing of Ernesto or Pavilion
    if ($edit_pool && !in_array($edit_pool['name'], ['Ernesto', 'Pavilion'])) {
        $edit_pool = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pool Management - Admin Dashboard</title>
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
        .pools-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .pool-stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
        }
        
        .pool-stat-card .number {
            font-size: 2rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .pool-stat-card .label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .pools-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin: 30px 0;
        }
        
        .pool-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border-left: 6px solid #1679AB;
            transition: transform 0.3s;
        }
        
        .pool-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(22,121,171,0.15);
        }
        
        .pool-card.ernesto { border-left-color: #9b59b6; }
        .pool-card.pavilion { border-left-color: #3498db; }
        
        .pool-card.open { border-left-color: #28a745; }
        .pool-card.closed { border-left-color: #dc3545; }
        .pool-card.maintenance { border-left-color: #ffc107; }
        
        .pool-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .pool-name {
            font-size: 2rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .pool-type-badge {
            background: #FFCBCB;
            color: #102C57;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .pool-type-badge.private { background: #9b59b6; color: white; }
        .pool-type-badge.public { background: #3498db; color: white; }
        
        .pool-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        
        .pool-detail-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        
        .pool-detail-item i {
            font-size: 1.5rem;
            color: #1679AB;
            margin-bottom: 5px;
        }
        
        .pool-detail-item .label {
            font-size: 0.85rem;
            color: #666;
        }
        
        .pool-detail-item .value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .hours-badge {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            margin: 15px 0;
            font-weight: 500;
            color: #1679AB;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .status-open { background: #d4edda; color: #155724; }
        .status-closed { background: #f8d7da; color: #721c24; }
        .status-maintenance { background: #fff3cd; color: #856404; }
        
        .today-reservations {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
        }
        
        .today-reservations h4 {
            color: #102C57;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
        .reservation-list {
            max-height: 200px;
            overflow-y: auto;
        }
        
        .reservation-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 8px;
            font-size: 0.9rem;
            border-left: 3px solid #1679AB;
        }
        
        .reservation-item .time {
            font-weight: bold;
            color: #1679AB;
        }
        
        .reservation-item .guest {
            color: #102C57;
        }
        
        .no-reservations {
            color: #999;
            font-style: italic;
            text-align: center;
            padding: 15px;
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
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #102C57;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
        }
        
        .form-control:focus {
            border-color: #1679AB;
            outline: none;
        }
        
        .form-control[readonly] {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        
        .btn-submit {
            background: #1679AB;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            width: 100%;
            font-size: 1rem;
        }
        
        .btn-submit:hover {
            background: #102C57;
        }
        
        .btn-reset {
            background: #6c757d;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            width: 100%;
            font-size: 1rem;
            margin-top: 10px;
        }
        
        .btn-reset:hover {
            background: #5a6268;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-icon {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
            filter: brightness(0.95);
        }
        
        .btn-edit { background: #17a2b8; color: white; }
        .btn-reset-small { background: #6c757d; color: white; }
        .btn-delete { background: #dc3545; color: white; }
        
        .pool-info {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .pool-info p {
            margin: 5px 0;
            color: #102C57;
        }
        
        .delete-warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
        }
        
        @media (max-width: 768px) {
            .pools-stats {
                grid-template-columns: 1fr 1fr;
            }
            
            .pools-grid {
                grid-template-columns: 1fr;
            }
            
            .pool-details {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
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
                <i class="fas fa-swimmer"></i>
                Pool Management
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
        
        <!-- Pool Statistics -->
        <div class="pools-stats">
            <div class="pool-stat-card">
                <div class="number"><?php echo $total_pools; ?></div>
                <div class="label">Total Pools</div>
            </div>
            <div class="pool-stat-card">
                <div class="number"><?php echo $private_pools; ?></div>
                <div class="label">Private Pool (Ernesto)</div>
            </div>
            <div class="pool-stat-card">
                <div class="number"><?php echo $public_pools; ?></div>
                <div class="label">Public Pool (Pavilion)</div>
            </div>
            <div class="pool-stat-card">
                <div class="number"><?php echo $total_reservations; ?></div>
                <div class="label">Active Reservations</div>
            </div>
        </div>
        
       
        
        <!-- Pools Grid -->
        <div class="pools-grid">
            <?php foreach ($pools as $pool): 
                $pool_class = strtolower($pool['name']) == 'ernesto' ? 'ernesto' : 'pavilion';
            ?>
            <div class="pool-card <?php echo $pool_class; ?> <?php echo $pool['status']; ?>">
                <div class="pool-header">
                    <span class="pool-name">
                        <?php echo htmlspecialchars($pool['name']); ?>
                        <?php if (strtolower($pool['name']) == 'ernesto'): ?>
                            <i class="fas fa-star" style="color: #ffc107; font-size: 1.2rem; margin-left: 5px;"></i>
                        <?php endif; ?>
                    </span>
                    <span class="pool-type-badge <?php echo $pool['type']; ?>">
                        <?php echo ucfirst($pool['type']); ?> Pool
                    </span>
                </div>
                
                <div class="pool-details">
                    <div class="pool-detail-item">
                        <i class="fas fa-users"></i>
                        <div class="label">Capacity</div>
                        <div class="value"><?php echo $pool['capacity']; ?> people</div>
                    </div>
                    
                    <div class="pool-detail-item">
                        <i class="fas fa-clock"></i>
                        <div class="label">Status</div>
                        <div class="value">
                            <span class="status-badge status-<?php echo $pool['status']; ?>">
                                <?php echo ucfirst($pool['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="hours-badge">
                    <i class="far fa-clock"></i> Operating Hours: <?php echo htmlspecialchars($pool['operating_hours']); ?>
                </div>
                
                <!-- Today's Reservations -->
                <div class="today-reservations">
                    <h4>
                        <i class="fas fa-calendar-day"></i> 
                        Current Reservations (<?php echo count($pool_reservations[$pool['name']] ?? []); ?>)
                    </h4>
                    
                    <div class="reservation-list">
                        <?php if (!empty($pool_reservations[$pool['name']])): ?>
                            <?php foreach ($pool_reservations[$pool['name']] as $res): ?>
                                <div class="reservation-item">
                                    <?php if (isset($res['check_in_date'])): ?>
                                        <span class="time"><?php echo date('M d', strtotime($res['check_in_date'])); ?></span>
                                    <?php endif; ?>
                                    <span class="guest"> - <?php echo htmlspecialchars($res['user_name'] ?? 'Guest'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-reservations">
                                <i class="fas fa-check-circle" style="color: #28a745;"></i> No active reservations
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="?edit=<?php echo $pool['id']; ?>" class="btn-icon btn-edit">
                        <i class="fas fa-edit"></i> Edit Settings
                    </a>
                    <form method="POST" style="flex: 1;" onsubmit="return confirm('Are you sure you want to reset this pool to default settings?');">
                        <input type="hidden" name="action" value="reset_pool">
                        <input type="hidden" name="pool_id" value="<?php echo $pool['id']; ?>">
                        <button type="submit" class="btn-icon btn-reset-small">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </form>
                    <?php if (!in_array($pool['name'], ['Ernesto', 'Pavilion'])): ?>
                    <form method="POST" style="flex: 1;" onsubmit="return confirmDelete('<?php echo htmlspecialchars($pool['name']); ?>');">
                        <input type="hidden" name="action" value="delete_pool">
                        <input type="hidden" name="pool_id" value="<?php echo $pool['id']; ?>">
                        <button type="submit" class="btn-icon btn-delete">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Quick Info -->
        <div class="pool-info">
            <p><i class="fas fa-info-circle"></i> <strong>Ernesto Pool:</strong> Private pool - Ideal for small groups and families. Capacity: 20 people.</p>
            <p><i class="fas fa-info-circle"></i> <strong>Pavilion Pool:</strong> Public pool - Larger pool for events and gatherings. Capacity: 50 people.</p>
            <p><i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> The main pools (Ernesto and Pavilion) cannot be deleted as they are core to the system.</p>
        </div>
    </div>
    
    <!-- Edit Pool Modal -->
    <?php if ($edit_pool): ?>
    <div class="modal active" id="editPoolModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit <?php echo htmlspecialchars($edit_pool['name']); ?> Settings</h3>
                <a href="pools.php" class="modal-close">&times;</a>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_pool">
                <input type="hidden" name="pool_id" value="<?php echo $edit_pool['id']; ?>">
                
                <div class="form-group">
                    <label for="edit_name">Pool Name</label>
                    <input type="text" name="name" id="edit_name" class="form-control" 
                           value="<?php echo htmlspecialchars($edit_pool['name']); ?>" readonly>
                    <small style="color: #666;">Pool name cannot be changed</small>
                </div>
                
                <div class="form-group">
                    <label for="edit_type">Pool Type</label>
                    <select name="type" id="edit_type" class="form-control" required>
                        <option value="private" <?php echo $edit_pool['type'] == 'private' ? 'selected' : ''; ?>>Private Pool</option>
                        <option value="public" <?php echo $edit_pool['type'] == 'public' ? 'selected' : ''; ?>>Public Pool</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_capacity">Capacity *</label>
                    <input type="number" name="capacity" id="edit_capacity" class="form-control" 
                           value="<?php echo $edit_pool['capacity']; ?>" required min="1" max="100">
                    <small>Recommended: Ernesto - 20, Pavilion - 50</small>
                </div>
                
                <div class="form-group">
                    <label for="edit_operating_hours">Operating Hours *</label>
                    <input type="text" name="operating_hours" id="edit_operating_hours" class="form-control" 
                           value="<?php echo htmlspecialchars($edit_pool['operating_hours']); ?>" required
                           placeholder="e.g., 7:00 AM - 8:00 PM">
                </div>
                
                <div class="form-group">
                    <label for="edit_status">Status *</label>
                    <select name="status" id="edit_status" class="form-control" required>
                        <option value="open" <?php echo $edit_pool['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                        <option value="closed" <?php echo $edit_pool['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                        <option value="maintenance" <?php echo $edit_pool['status'] == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    </select>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Update Pool Settings
                </button>
                
                <div style="text-align: center; margin-top: 15px;">
                    <a href="pools.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }
        
        function confirmDelete(poolName) {
            return confirm(`Are you sure you want to delete "${poolName}"?\n\nThis action cannot be undone.`);
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editPoolModal');
            if (event.target == modal) {
                window.location.href = 'pools.php';
            }
        }
    </script>
</body>
</html>