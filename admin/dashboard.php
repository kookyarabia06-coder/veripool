<?php
/**
 * Veripool Reservation System - Admin Dashboard
 * Dashboard view showing recent online and walk-in reservations
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
require_once BASE_PATH . '/includes/EntryPassManager.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin', 'staff'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get current user
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// ========== DASHBOARD STATISTICS ==========
$today = date('Y-m-d');

// Get today's check-ins (expected arrivals) - separated by type
$today_checkins_online = $db->getRows("
    SELECT r.*, 
           u.full_name as guest_name, 
           u.phone as guest_phone,
           rm.room_number,
           rt.name as room_type,
           ep.otp_code,
           ep.status as pass_status
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.check_in_date = ? 
    AND r.status IN ('confirmed', 'pending')
    AND (r.created_by IS NULL OR r.created_by != 'walkin')
    ORDER BY r.created_at DESC
", [$today]);

$today_checkins_walkin = $db->getRows("
    SELECT r.*, 
           u.full_name as guest_name, 
           u.phone as guest_phone,
           rm.room_number,
           rt.name as room_type
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE r.check_in_date = ? 
    AND r.status IN ('confirmed', 'pending')
    AND r.created_by = 'walkin'
    ORDER BY r.created_at DESC
", [$today]);

// Get today's check-outs (expected departures)
$today_checkouts = $db->getRows("
    SELECT r.*, 
           u.full_name as guest_name, 
           u.phone as guest_phone,
           rm.room_number,
           rt.name as room_type,
           r.created_by
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE r.check_out_date = ? 
    AND r.status = 'checked_in'
    ORDER BY r.created_at DESC
", [$today]);

// Get recent online reservations (last 7 days)
$recent_online = $db->getRows("
    SELECT r.*, 
           u.full_name as guest_name, 
           u.phone as guest_phone,
           rm.room_number,
           rt.name as room_type,
           (SELECT COUNT(*) FROM payments WHERE reservation_id = r.id) as payment_count,
           ep.otp_code,
           ep.status as pass_status,
           DATEDIFF(r.check_in_date, CURDATE()) as days_until_checkin
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND (r.created_by IS NULL OR r.created_by != 'walkin')
    ORDER BY 
        CASE 
            WHEN r.status = 'pending' THEN 1
            WHEN r.status = 'confirmed' THEN 2
            WHEN r.status = 'checked_in' THEN 3
            WHEN r.status = 'checked_out' THEN 4
            ELSE 5
        END,
        r.created_at DESC
    LIMIT 10
");

// Get recent walk-in reservations (last 7 days)
$recent_walkin = $db->getRows("
    SELECT r.*, 
           u.full_name as guest_name, 
           u.phone as guest_phone,
           rm.room_number,
           rt.name as room_type,
           DATEDIFF(r.check_in_date, CURDATE()) as days_until_checkin
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND r.created_by = 'walkin'
    ORDER BY 
        CASE 
            WHEN r.status = 'pending' THEN 1
            WHEN r.status = 'confirmed' THEN 2
            WHEN r.status = 'checked_in' THEN 3
            WHEN r.status = 'checked_out' THEN 4
            ELSE 5
        END,
        r.created_at DESC
    LIMIT 10
");

// Get quick stats for header
$online_count = count($recent_online);
$walkin_count = count($recent_walkin);
$pending_count = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'pending'") ?: 0;
$confirmed_count = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'confirmed'") ?: 0;
$checked_in_count = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'checked_in'") ?: 0;

// ========== HANDLE ACTIONS ==========
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Update reservation status (quick actions from dashboard)
        if ($_POST['action'] === 'quick_update' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            $new_status = sanitize($_POST['status']);
            
            // Get current reservation data
            $reservation = $db->getRow("SELECT * FROM reservations WHERE id = ?", [$reservation_id]);
            
            if ($reservation) {
                // For online reservations, prevent direct check-in without OTP
                if ($new_status === 'checked_in' && $reservation['created_by'] != 'walkin') {
                    $message = "Online reservations require OTP verification";
                    $message_type = 'error';
                } else {
                    // Update reservation status
                    $db->update('reservations', 
                        ['status' => $new_status], 
                        'id = :id', 
                        ['id' => $reservation_id]
                    );
                    
                    // Update room status if checking in/out
                    if ($new_status === 'checked_in' && $reservation['room_id']) {
                        $db->update('rooms', 
                            ['status' => 'occupied'], 
                            'id = :id', 
                            ['id' => $reservation['room_id']]
                        );
                    }
                    
                    if ($new_status === 'checked_out' && $reservation['room_id']) {
                        $db->update('rooms', 
                            ['status' => 'available'], 
                            'id = :id', 
                            ['id' => $reservation['room_id']]
                        );
                    }
                    
                    $message = "Reservation status updated successfully";
                    $message_type = 'success';
                }
            }
        }
        
        // Refresh data
        header("Location: index.php");
        exit;
    }
}

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    switch($status) {
        case 'pending': return 'status-pending';
        case 'confirmed': return 'status-confirmed';
        case 'checked_in': return 'status-checked_in';
        case 'checked_out': return 'status-checked_out';
        case 'cancelled': return 'status-cancelled';
        default: return 'status-pending';
    }
}

// Helper function to get status display text
function getStatusDisplay($status) {
    switch($status) {
        case 'checked_in': return 'In';
        case 'checked_out': return 'Out';
        case 'confirmed': return 'Conf';
        case 'pending': return 'Pen';
        case 'cancelled': return 'Canc';
        default: return substr($status, 0, 3);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Veripool Admin</title>
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
        /* Dashboard Specific Styles */
        .dashboard-header {
            margin-bottom: 25px;
        }
        
        .dashboard-header h1 {
            font-size: 1.6rem;
            color: #102C57;
            margin-bottom: 5px;
        }
        
        .dashboard-header .date {
            color: #666;
            font-size: 0.9rem;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(22,121,171,0.1);
        }
        
        .stat-card.online {
            border-left-color: #1679AB;
        }
        
        .stat-card.walkin {
            border-left-color: #28a745;
        }
        
        .stat-card .stat-title {
            color: #666;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        
        .stat-card .stat-number {
            color: #102C57;
            font-size: 2rem;
            font-weight: bold;
        }
        
        .stat-card .stat-icon {
            float: right;
            color: #FFB1B1;
            font-size: 2.5rem;
            opacity: 0.5;
        }
        
        /* Section Headers */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .section-header h2 {
            color: #102C57;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-header h2 i {
            color: #1679AB;
        }
        
        .section-header .badge {
            background: #FFB1B1;
            color: #102C57;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .section-header .view-link {
            color: #1679AB;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .section-header .view-link:hover {
            text-decoration: underline;
        }
        
        /* Type Badge */
        .type-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.6rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .type-online {
            background: #1679AB;
            color: white;
        }
        
        .type-walkin {
            background: #28a745;
            color: white;
        }
        
        /* Activity Cards */
        .activity-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .activity-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        
        .activity-card .card-header {
            padding: 12px 15px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .activity-card.checkin-online .card-header {
            background: #1679AB;
            color: white;
        }
        
        .activity-card.checkin-walkin .card-header {
            background: #28a745;
            color: white;
        }
        
        .activity-card.checkout .card-header {
            background: #dc3545;
            color: white;
        }
        
        .activity-card .card-header i {
            margin-right: 5px;
        }
        
        .activity-card .card-header .count {
            background: rgba(255,255,255,0.3);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }
        
        .activity-card .card-body {
            padding: 10px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        
        .activity-item:hover {
            background: #f8f9fa;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 30px;
            height: 30px;
            background: #f0f0f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            color: #1679AB;
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-guest {
            font-weight: 600;
            color: #102C57;
            font-size: 0.8rem;
        }
        
        .activity-room {
            font-size: 0.65rem;
            color: #1679AB;
        }
        
        .activity-time {
            font-size: 0.6rem;
            color: #666;
        }
        
        .activity-otp {
            font-family: monospace;
            font-size: 0.65rem;
            background: #102C57;
            color: #FFCBCB;
            padding: 2px 4px;
            border-radius: 3px;
        }
        
        .empty-state {
            text-align: center;
            padding: 20px 10px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 1.5rem;
            color: #FFCBCB;
            margin-bottom: 5px;
        }
        
        /* Recent Reservations Tables */
        .reservations-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 10px;
        }
        
        .reservation-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        
        .reservation-card .card-header {
            padding: 12px 15px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .reservation-card.online .card-header {
            background: #1679AB;
            color: white;
        }
        
        .reservation-card.walkin .card-header {
            background: #28a745;
            color: white;
        }
        
        .reservation-card .card-header .badge {
            background: rgba(255,255,255,0.3);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }
        
        .reservation-card .card-body {
            padding: 15px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .reservation-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }
        
        .reservation-table th {
            text-align: left;
            padding: 8px 5px;
            background: #f8f9fa;
            color: #102C57;
            font-weight: 600;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .reservation-table td {
            padding: 8px 5px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .reservation-table tr:hover td {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.65rem;
            font-weight: 500;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d4edda; color: #155724; }
        .status-checked_in { background: #cce5ff; color: #004085; }
        .status-checked_out { background: #e2e3e5; color: #383d41; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .otp-badge {
            background: #102C57;
            color: #FFCBCB;
            padding: 2px 5px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .days-badge {
            background: #e7f5ff;
            color: #1679AB;
            padding: 2px 5px;
            border-radius: 10px;
            font-size: 0.6rem;
        }
        
        .action-select {
            padding: 3px;
            font-size: 0.65rem;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
            width: 65px;
        }
        
        .action-select.online option[value="checked_in"] {
            color: #999;
            background: #f5f5f5;
        }
        
        .btn-icon {
            padding: 4px 6px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.65rem;
            transition: all 0.2s;
            background: #1679AB;
            color: white;
        }
        
        .btn-icon:hover {
            transform: translateY(-1px);
            filter: brightness(0.95);
        }
        
        .alert {
            padding: 8px 12px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
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
        
        .view-all-link {
            text-align: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }
        
        .view-all-link a {
            color: #1679AB;
            text-decoration: none;
            font-size: 0.8rem;
        }
        
        .view-all-link a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .activity-grid {
                grid-template-columns: 1fr;
            }
            
            .reservations-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include BASE_PATH . '/includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar" style="padding: 12px 20px; margin-bottom: 20px;">
            <h1 style="font-size: 1.4rem;">
                <i class="fas fa-calendar-check"></i>
                Dashboard
            </h1>
            <div class="date" style="font-size: 0.8rem;">
                <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
                <span style="margin-left: 10px;"><i class="far fa-clock"></i> <?php echo date('h:i A'); ?></span>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        
        <!-- Recent Reservations - Split View -->
        <div class="reservations-grid">
            <!-- Online Reservations -->
            <div class="reservation-card online">
                <div class="card-header">
                    <span><i class="fas fa-globe"></i> Recent Online Reservations</span>
                    <span class="badge">Last 7 days</span>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_online)): ?>
                        <div class="empty-state" style="padding: 30px;">
                            <i class="fas fa-globe"></i>
                            <p>No online reservations found</p>
                        </div>
                    <?php else: ?>
                        <table class="reservation-table">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Dates</th>
                                    <th>Status</th>
                                    <th>OTP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_online as $res): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars(substr($res['guest_name'], 0, 15)); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($res['room_number']): ?>
                                            Rm <?php echo $res['room_number']; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('m/d', strtotime($res['check_in_date'])); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo getStatusBadgeClass($res['status']); ?>">
                                            <?php echo getStatusDisplay($res['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($res['otp_code'])): ?>
                                            <span class="otp-badge"><?php echo $res['otp_code']; ?></span>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="view-all-link">
                            <a href="reservations.php?type=online"><i class="fas fa-arrow-right"></i> View All Online Reservations</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Walk-in Reservations -->
            <div class="reservation-card walkin">
                <div class="card-header">
                    <span><i class="fas fa-user-plus"></i> Recent Walk-in Reservations</span>
                    <span class="badge">Last 7 days</span>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_walkin)): ?>
                        <div class="empty-state" style="padding: 30px;">
                            <i class="fas fa-user-plus"></i>
                            <p>No walk-in reservations found</p>
                        </div>
                    <?php else: ?>
                        <table class="reservation-table">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Dates</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_walkin as $res): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars(substr($res['guest_name'], 0, 15)); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($res['room_number']): ?>
                                            Rm <?php echo $res['room_number']; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('m/d', strtotime($res['check_in_date'])); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo getStatusBadgeClass($res['status']); ?>">
                                            <?php echo getStatusDisplay($res['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="quick_update">
                                            <input type="hidden" name="reservation_id" value="<?php echo $res['id']; ?>">
                                            <select name="status" class="action-select" onchange="this.form.submit()">
                                                <option value="pending" <?php echo $res['status'] == 'pending' ? 'selected' : ''; ?>>Pen</option>
                                                <option value="confirmed" <?php echo $res['status'] == 'confirmed' ? 'selected' : ''; ?>>Conf</option>
                                                <option value="checked_in" <?php echo $res['status'] == 'checked_in' ? 'selected' : ''; ?>>In</option>
                                                <option value="checked_out" <?php echo $res['status'] == 'checked_out' ? 'selected' : ''; ?>>Out</option>
                                                <option value="cancelled" <?php echo $res['status'] == 'cancelled' ? 'selected' : ''; ?>>Canc</option>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="view-all-link">
                            <a href="reservations.php?type=walkin"><i class="fas fa-arrow-right"></i> View All Walk-in Reservations</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
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