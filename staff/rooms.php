<?php
/**
 * Veripool Reservation System - Staff Room Status Page
 * View and manage room status with cleaning status
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

// Check if user is logged in and is staff
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'staff') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get current user
$user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// FIXED: Get all rooms with their current status and cleaning status
$rooms = $db->getRows("
    SELECT r.*, rt.name as room_type, rt.base_price,
           (SELECT u.full_name 
            FROM reservations res
            JOIN users u ON res.user_id = u.id
            WHERE res.room_id = r.id AND res.status = 'checked_in'
            LIMIT 1) as current_guest,
           (SELECT res.check_out_date 
            FROM reservations res
            WHERE res.room_id = r.id AND res.status = 'checked_in'
            LIMIT 1) as check_out_date
    FROM rooms r
    JOIN room_types rt ON r.room_type_id = rt.id
    ORDER BY r.room_number
");

// Get statistics
$available_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'available'");
$occupied_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'occupied'");
$maintenance_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'maintenance'");
$reserved_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'reserved'");

// Cleaning statistics
$clean_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE cleaning_status = 'clean'");
$dirty_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE cleaning_status = 'dirty'");
$in_progress_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE cleaning_status = 'in_progress'");

// Handle action
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Update room status
        if ($_POST['action'] === 'update_room_status') {
            $room_id = (int)$_POST['room_id'];
            $new_status = sanitize($_POST['status']);
            
            $db->update('rooms', 
                ['status' => $new_status], 
                'id = :id', 
                ['id' => $room_id]
            );
            
            $message = "Room status updated successfully";
            $message_type = 'success';
        }
        
        // Update cleaning status
        if ($_POST['action'] === 'update_cleaning_status') {
            $room_id = (int)$_POST['room_id'];
            $new_cleaning_status = sanitize($_POST['cleaning_status']);
            
            $db->update('rooms', 
                ['cleaning_status' => $new_cleaning_status], 
                'id = :id', 
                ['id' => $room_id]
            );
            
            $message = "Cleaning status updated successfully";
            $message_type = 'success';
        }
        
        // Mark room for cleaning (quick action)
        if ($_POST['action'] === 'mark_for_cleaning') {
            $room_id = (int)$_POST['room_id'];
            
            $db->update('rooms', 
                ['cleaning_status' => 'dirty'], 
                'id = :id', 
                ['id' => $room_id]
            );
            
            $message = "Room marked for cleaning";
            $message_type = 'success';
        }
        
        // Mark room as clean (quick action)
        if ($_POST['action'] === 'mark_as_clean') {
            $room_id = (int)$_POST['room_id'];
            
            $db->update('rooms', 
                ['cleaning_status' => 'clean'], 
                'id = :id', 
                ['id' => $room_id]
            );
            
            $message = "Room marked as clean";
            $message_type = 'success';
        }
        
        // Refresh rooms data
        $rooms = $db->getRows("
            SELECT r.*, rt.name as room_type, rt.base_price,
                   (SELECT u.full_name 
                    FROM reservations res
                    JOIN users u ON res.user_id = u.id
                    WHERE res.room_id = r.id AND res.status = 'checked_in'
                    LIMIT 1) as current_guest,
                   (SELECT res.check_out_date 
                    FROM reservations res
                    WHERE res.room_id = r.id AND res.status = 'checked_in'
                    LIMIT 1) as check_out_date
            FROM rooms r
            JOIN room_types rt ON r.room_type_id = rt.id
            ORDER BY r.room_number
        ");
        
        // Refresh statistics
        $available_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'available'");
        $occupied_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'occupied'");
        $maintenance_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'maintenance'");
        $reserved_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'reserved'");
        $clean_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE cleaning_status = 'clean'");
        $dirty_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE cleaning_status = 'dirty'");
        $in_progress_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE cleaning_status = 'in_progress'");
    }
}

// Helper function to get cleaning status badge class
function getCleaningBadgeClass($status) {
    switch($status) {
        case 'clean':
            return 'cleaning-clean';
        case 'dirty':
            return 'cleaning-dirty';
        case 'in_progress':
            return 'cleaning-progress';
        default:
            return '';
    }
}

// Helper function to format cleaning status
function formatCleaningStatus($status) {
    switch($status) {
        case 'clean':
            return 'Clean';
        case 'dirty':
            return 'Needs Cleaning';
        case 'in_progress':
            return 'Cleaning in Progress';
        default:
            return ucfirst($status);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Status - Staff Portal</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/staff.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Main Content Area */
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
            min-height: 100vh;
            background: #f4f7fc;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .top-bar h1 {
            color: #102C57;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        
        .top-bar h1 i {
            color: #1679AB;
        }
        
        .top-bar .date {
            background: #f8f9fa;
            padding: 8px 15px;
            border-radius: 20px;
            color: #102C57;
            font-size: 0.9rem;
        }
        
        .top-bar .date i {
            margin-right: 5px;
            color: #1679AB;
        }
        
        /* Stats Sections */
        .stats-section {
            margin-bottom: 30px;
        }
        
        .stats-section h3 {
            color: #102C57;
            font-size: 1rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stats-section h3 i {
            color: #1679AB;
        }
        
        .room-stats, .cleaning-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .room-stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
        }
        
        .room-stat-card .number {
            font-size: 2rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .room-stat-card .label {
            color: #666;
            font-size: 0.85rem;
            margin-top: 5px;
        }
        
        /* Cleaning Stats Colors */
        .cleaning-stats .room-stat-card:nth-child(1) { border-left-color: #28a745; }
        .cleaning-stats .room-stat-card:nth-child(2) { border-left-color: #dc3545; }
        .cleaning-stats .room-stat-card:nth-child(3) { border-left-color: #ffc107; }
        
        /* Room Grid */
        .room-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .room-item {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
            transition: all 0.3s;
        }
        
        .room-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .room-item.available { border-left-color: #28a745; }
        .room-item.occupied { border-left-color: #dc3545; }
        .room-item.maintenance { border-left-color: #ffc107; }
        .room-item.reserved { border-left-color: #17a2b8; }
        
        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .room-number {
            font-size: 1.3rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .room-number i {
            color: #1679AB;
            margin-right: 5px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-available { background: #d4edda; color: #155724; }
        .status-occupied { background: #f8d7da; color: #721c24; }
        .status-maintenance { background: #fff3cd; color: #856404; }
        .status-reserved { background: #cce5ff; color: #004085; }
        
        /* Cleaning Badges */
        .cleaning-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .cleaning-clean {
            background: #d4edda;
            color: #155724;
            border: 1px solid #28a745;
        }
        
        .cleaning-dirty {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #dc3545;
        }
        
        .cleaning-progress {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
        }
        
        .room-details {
            margin: 15px 0;
            padding: 10px 0;
            border-top: 1px solid #e0e0e0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .detail-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 8px 0;
            color: #666;
            font-size: 0.9rem;
        }
        
        .detail-row i {
            width: 20px;
            color: #1679AB;
        }
        
        .current-guest {
            background: #e8f4fd;
            padding: 12px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 0.9rem;
            border-left: 3px solid #1679AB;
        }
        
        .current-guest i {
            color: #1679AB;
            margin-right: 5px;
        }
        
        .checkout-info {
            background: #fff3cd;
            padding: 8px 12px;
            border-radius: 5px;
            margin-top: 8px;
            font-size: 0.85rem;
            color: #856404;
        }
        
        .action-section {
            margin-top: 15px;
        }
        
        .action-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: #102C57;
            margin-bottom: 10px;
        }
        
        .status-select {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            margin: 5px 0;
            font-size: 0.9rem;
        }
        
        .status-select:focus {
            border-color: #1679AB;
            outline: none;
        }
        
        .btn-update {
            width: 100%;
            padding: 8px;
            background: #1679AB;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-update:hover {
            background: #102C57;
            transform: translateY(-2px);
        }
        
        .btn-clean {
            width: 100%;
            padding: 8px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
            margin-top: 5px;
        }
        
        .btn-clean:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .btn-dirty {
            width: 100%;
            padding: 8px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
            margin-top: 5px;
        }
        
        .btn-dirty:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 10px;
        }
        
        .debug-info {
            background: #f8f9fa;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.8rem;
            color: #666;
            border-left: 4px solid #1679AB;
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
            background: #1679AB;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            cursor: pointer;
            font-size: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
            
            .room-stats, .cleaning-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .room-stats, .cleaning-stats {
                grid-template-columns: 1fr;
            }
            
            .room-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
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
                <i class="fas fa-bed"></i>
                Room Status & Cleaning
            </h1>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
            </div>
        </div>
        
        <!-- Debug Info (remove in production) -->
        <div class="debug-info">
            <i class="fas fa-info-circle"></i> 
            <strong>Debug:</strong> Found <?php echo count($rooms); ?> rooms in database
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        
        <!-- Room Grid -->
        <h3 style="margin: 20px 0 10px; color: #102C57;">
            <i class="fas fa-list"></i> All Rooms
        </h3>
        
        <div class="room-grid">
            <?php if (empty($rooms)): ?>
                <p style="grid-column: 1/-1; text-align: center; color: #666; padding: 60px; background: white; border-radius: 10px;">
                    <i class="fas fa-door-open" style="font-size: 3rem; color: #FFCBCB; margin-bottom: 15px; display: block;"></i>
                    No rooms found in database.
                </p>
            <?php else: ?>
                <?php foreach ($rooms as $room): ?>
                <div class="room-item <?php echo $room['status']; ?>">
                    <div class="room-header">
                        <span class="room-number">
                            <i class="fas fa-door-open"></i> Room <?php echo $room['room_number']; ?>
                        </span>
                        <span class="status-badge status-<?php echo $room['status']; ?>">
                            <?php echo ucfirst($room['status']); ?>
                        </span>
                    </div>
                    
                    <div style="margin-bottom: 10px;">
                        <span class="cleaning-badge <?php echo getCleaningBadgeClass($room['cleaning_status'] ?? 'clean'); ?>">
                            <i class="fas fa-broom"></i> <?php echo formatCleaningStatus($room['cleaning_status'] ?? 'clean'); ?>
                        </span>
                    </div>
                  <div class="room-details">
    <div class="detail-row">
        <i class="fas fa-tag"></i> <strong>Type:</strong> <?php echo $room['room_type']; ?>
    </div>
    <div class="detail-row">
        <i class="fas fa-layer-group"></i> <strong>Floor:</strong> <?php echo $room['floor']; ?>
    </div>
    <div class="detail-row">
        <i class="fas fa-money-bill"></i> <strong>Rate:</strong> ₱<?php echo number_format($room['base_price'], 2); ?>/night
    </div>
    <!-- FIXED: Removed capacity line or use alternative -->
    <?php if (!empty($room['max_occupancy'])): ?>
    <div class="detail-row">
        <i class="fas fa-users"></i> <strong>Max Occupancy:</strong> <?php echo $room['max_occupancy']; ?> persons
    </div>
    <?php endif; ?>
</div>
                    
                    <!-- Room Status Update Form -->
                    <div class="action-section">
                        <div class="action-title">
                            <i class="fas fa-edit"></i> Update Room Status
                        </div>
                        <form method="POST" style="margin-bottom: 10px;">
                            <input type="hidden" name="action" value="update_room_status">
                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                            <select name="status" class="status-select" <?php echo ($room['status'] == 'occupied') ? 'disabled' : ''; ?>>
                                <option value="available" <?php echo $room['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="reserved" <?php echo $room['status'] == 'reserved' ? 'selected' : ''; ?>>Reserved</option>
                                <option value="maintenance" <?php echo $room['status'] == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                            <?php if ($room['status'] != 'occupied'): ?>
                                <button type="submit" class="btn-update">
                                    <i class="fas fa-save"></i> Update Status
                                </button>
                            <?php else: ?>
                                <div style="background: #f8f9fa; padding: 8px; border-radius: 5px; text-align: center; color: #666; font-size: 0.85rem;">
                                    <i class="fas fa-lock"></i> Cannot change while occupied
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <!-- Cleaning Status Update Form -->
                    <div class="action-section">
                        <div class="action-title">
                            <i class="fas fa-broom"></i> Update Cleaning Status
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_cleaning_status">
                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                            <select name="cleaning_status" class="status-select">
                                <option value="clean" <?php echo ($room['cleaning_status'] ?? 'clean') == 'clean' ? 'selected' : ''; ?>>Clean</option>
                                <option value="dirty" <?php echo ($room['cleaning_status'] ?? '') == 'dirty' ? 'selected' : ''; ?>>Needs Cleaning</option>
                                <option value="in_progress" <?php echo ($room['cleaning_status'] ?? '') == 'in_progress' ? 'selected' : ''; ?>>Cleaning in Progress</option>
                            </select>
                            <button type="submit" class="btn-update">
                                <i class="fas fa-save"></i> Update Cleaning
                            </button>
                        </form>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <?php if (($room['cleaning_status'] ?? 'clean') != 'dirty'): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="mark_for_cleaning">
                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                            <button type="submit" class="btn-dirty">
                                <i class="fas fa-broom"></i> Needs Cleaning
                            </button>
                        </form>
                        <?php endif; ?>
                        
                        <?php if (($room['cleaning_status'] ?? 'clean') != 'clean'): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="mark_as_clean">
                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                            <button type="submit" class="btn-clean">
                                <i class="fas fa-check"></i> Mark Clean
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
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