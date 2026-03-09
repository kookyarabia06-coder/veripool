<?php
/**
 * Veripool Reservation System - Staff Dashboard
 * Staff interface for entry verification - OTP validity based on reservation dates
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

// Get current user (for sidebar)
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Get today's statistics
$today = date('Y-m-d');

// Today's expected check-ins - get all reservations for today
$today_checkins_result = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone, u.email
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    WHERE r.check_in_date = ? AND r.status IN ('confirmed', 'pending', 'checked_in')
    ORDER BY r.created_at ASC
", [$today]);

// Ensure we have an array
$today_checkins = is_array($today_checkins_result) ? $today_checkins_result : [];

// Today's expected check-outs
$today_checkouts_result = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    WHERE r.check_out_date = ? AND r.status = 'checked_in'
    ORDER BY r.created_at ASC
", [$today]);

$today_checkouts = is_array($today_checkouts_result) ? $today_checkouts_result : [];

// Current guests (checked-in)
$current_guests_result = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone, u.email,
           DATEDIFF(r.check_out_date, CURDATE()) as days_remaining,
           ep.otp_code, ep.valid_from, ep.valid_until, ep.status as pass_status
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.status = 'checked_in'
    ORDER BY r.check_out_date ASC
");

$current_guests = is_array($current_guests_result) ? $current_guests_result : [];

// Upcoming reservations (next 7 days)
$upcoming_result = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone,
           ep.otp_code, ep.status as pass_status
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.check_in_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND r.status IN ('confirmed', 'pending')
    ORDER BY r.check_in_date ASC
");

$upcoming = is_array($upcoming_result) ? $upcoming_result : [];

// Facility status counts - safely check if tables exist
$total_rooms = 0;
$total_cottages = 0;
$total_pools = 0;

// Try to get room count (will return 0 if table doesn't exist or query fails)
try {
    $rooms_result = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'available'");
    $total_rooms = is_numeric($rooms_result) ? (int)$rooms_result : 0;
} catch (Exception $e) {
    $total_rooms = 0;
}

try {
    $cottages_result = $db->getValue("SELECT COUNT(*) FROM cottages WHERE status = 'available'");
    $total_cottages = is_numeric($cottages_result) ? (int)$cottages_result : 0;
} catch (Exception $e) {
    $total_cottages = 0;
}

try {
    $pools_result = $db->getValue("SELECT COUNT(*) FROM pools WHERE status = 'available'");
    $total_pools = is_numeric($pools_result) ? (int)$pools_result : 0;
} catch (Exception $e) {
    $total_pools = 0;
}

// Function to get facility name from reservation
function getFacilityName($reservation) {
    if (!is_array($reservation)) {
        return 'Not specified';
    }
    
    if (!empty($reservation['room_id'])) {
        global $db;
        try {
            $room = $db->getRow("SELECT room_number FROM rooms WHERE id = ?", [$reservation['room_id']]);
            return $room && is_array($room) ? $room['room_number'] : 'Room #' . $reservation['room_id'];
        } catch (Exception $e) {
            return 'Room #' . $reservation['room_id'];
        }
    } elseif (!empty($reservation['cottage_id'])) {
        global $db;
        try {
            $cottage = $db->getRow("SELECT name FROM cottages WHERE id = ?", [$reservation['cottage_id']]);
            return $cottage && is_array($cottage) ? $cottage['name'] : 'Cottage #' . $reservation['cottage_id'];
        } catch (Exception $e) {
            return 'Cottage #' . $reservation['cottage_id'];
        }
    } elseif (!empty($reservation['pool_id'])) {
        global $db;
        try {
            $pool = $db->getRow("SELECT name FROM pools WHERE id = ?", [$reservation['pool_id']]);
            return $pool && is_array($pool) ? $pool['name'] : 'Pool #' . $reservation['pool_id'];
        } catch (Exception $e) {
            return 'Pool #' . $reservation['pool_id'];
        }
    }
    return 'Not specified';
}

function getFacilityType($reservation) {
    if (!is_array($reservation)) {
        return '';
    }
    
    if (!empty($reservation['room_id'])) {
        return 'room';
    } elseif (!empty($reservation['cottage_id'])) {
        return 'cottage';
    } elseif (!empty($reservation['pool_id'])) {
        return 'pool';
    }
    return '';
}

// Handle OTP verification
$searched_reservation = null;
$search_error = '';
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Search by OTP for entry verification
        if ($_POST['action'] === 'verify_otp') {
            $otp = sanitize($_POST['otp']);
            
            // Search in entry_passes table first - MODIFIED: Check by reservation dates only, not time
            $entry_pass_query = "
                SELECT ep.*, r.*, u.full_name as guest_name, u.phone, u.email
                FROM entry_passes ep
                JOIN reservations r ON ep.reservation_id = r.id
                JOIN users u ON ep.user_id = u.id
                WHERE ep.otp_code = ? AND ep.status = 'active'";
            
            $entry_pass = $db->getRow($entry_pass_query, [$otp]);
            
            if ($entry_pass && is_array($entry_pass)) {
                // MODIFIED: Check validity by DATE only (not time)
                $today_date = date('Y-m-d');
                $check_in_date = date('Y-m-d', strtotime($entry_pass['check_in_date']));
                $check_out_date = date('Y-m-d', strtotime($entry_pass['check_out_date']));
                
                if ($today_date < $check_in_date) {
                    $search_error = "This pass is not yet valid. Valid from: " . date('M d, Y', strtotime($check_in_date));
                } elseif ($today_date > $check_out_date) {
                    $search_error = "This pass has expired. Valid until: " . date('M d, Y', strtotime($check_out_date));
                } elseif ($entry_pass['status'] != 'active') {
                    $search_error = "This pass is no longer valid (Status: " . ucfirst($entry_pass['status']) . ")";
                } elseif ($entry_pass['status'] == 'checked_in') {
                    $search_error = "Guest is already checked in.";
                } else {
                    $searched_reservation = $entry_pass;
                }
            } else {
                // Check if OTP exists but might be used or expired
                $used_pass_query = "
                    SELECT ep.*, r.*, u.full_name as guest_name
                    FROM entry_passes ep
                    JOIN reservations r ON ep.reservation_id = r.id
                    JOIN users u ON ep.user_id = u.id
                    WHERE ep.otp_code = ?";
                
                $used_pass = $db->getRow($used_pass_query, [$otp]);
                
                if ($used_pass && is_array($used_pass)) {
                    if ($used_pass['status'] == 'used') {
                        $search_error = "This OTP was already used for check-in.";
                    } elseif ($used_pass['status'] == 'expired') {
                        $search_error = "This OTP has expired.";
                    } else {
                        $search_error = "This OTP is not valid for entry.";
                    }
                } else {
                    $search_error = "No valid entry pass found with this OTP code.";
                }
            }
        }
        
        // MODIFIED: Confirm entry - mark as checked in and update pass status
        if ($_POST['action'] === 'confirm_entry' && isset($_POST['pass_id'])) {
            $pass_id = (int)$_POST['pass_id'];
            $reservation_id = (int)$_POST['reservation_id'];
            
            // Begin transaction
            $db->query("START TRANSACTION");
            
            try {
                // Update entry pass status to used
                $db->query("
                    UPDATE entry_passes 
                    SET status = 'used', used_at = NOW() 
                    WHERE id = ?",
                    [$pass_id]
                );
                
                // Update reservation status to checked_in
                $db->query("
                    UPDATE reservations 
                    SET status = 'checked_in', 
                        updated_at = NOW()
                    WHERE id = ?",
                    [$reservation_id]
                );
                
                // Update room status if room exists
                $reservation = $db->getRow("SELECT room_id FROM reservations WHERE id = ?", [$reservation_id]);
                if ($reservation && !empty($reservation['room_id'])) {
                    $db->query("
                        UPDATE rooms 
                        SET status = 'occupied' 
                        WHERE id = ?",
                        [$reservation['room_id']]
                    );
                }
                
                // Log the entry
                $db->query("
                    INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) 
                    VALUES (?, 'entry_verified', ?, ?, NOW())",
                    [$_SESSION['user_id'], "Verified entry for reservation #$reservation_id with OTP", $_SERVER['REMOTE_ADDR']]
                );
                
                $db->query("COMMIT");
                
                $message = "Entry verified successfully! Guest has been checked in.";
                $message_type = 'success';
                $searched_reservation = null;
                
            } catch (Exception $e) {
                $db->query("ROLLBACK");
                $message = "Error verifying entry: " . $e->getMessage();
                $message_type = 'error';
            }
        }
        
        // Quick check-in (alternative method)
        if ($_POST['action'] === 'quick_checkin' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            
            // Begin transaction
            $db->query("START TRANSACTION");
            
            try {
                // Get reservation details
                $reservation = $db->getRow("SELECT * FROM reservations WHERE id = ?", [$reservation_id]);
                
                if ($reservation && is_array($reservation)) {
                    // Check if entry pass exists
                    $pass = $db->getRow("SELECT * FROM entry_passes WHERE reservation_id = ?", [$reservation_id]);
                    
                    if ($pass && is_array($pass)) {
                        // Mark pass as used
                        $db->query("UPDATE entry_passes SET status = 'used', used_at = NOW() WHERE id = ?", [$pass['id']]);
                    }
                    
                    // Update reservation status
                    $db->query("
                        UPDATE reservations 
                        SET status = 'checked_in', 
                            updated_at = NOW()
                        WHERE id = ?",
                        [$reservation_id]
                    );
                    
                    // Update room status if room exists
                    if (!empty($reservation['room_id'])) {
                        $db->query("
                            UPDATE rooms 
                            SET status = 'occupied' 
                            WHERE id = ?",
                            [$reservation['room_id']]
                        );
                    }
                    
                    $db->query("COMMIT");
                    
                    $message = "Guest checked in successfully!";
                    $message_type = 'success';
                }
            } catch (Exception $e) {
                $db->query("ROLLBACK");
                $message = "Error checking in: " . $e->getMessage();
                $message_type = 'error';
            }
        }
        
        // Clear search
        if ($_POST['action'] === 'clear_search') {
            $searched_reservation = null;
            $search_error = '';
        }
    }
}

// Calculate counts safely
$today_checkins_count = is_array($today_checkins) ? count($today_checkins) : 0;
$today_checkouts_count = is_array($today_checkouts) ? count($today_checkouts) : 0;
$current_guests_count = is_array($current_guests) ? count($current_guests) : 0;
$upcoming_count = is_array($upcoming) ? count($upcoming) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Veripool Resort</title>
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
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(22,121,171,0.1);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            background: #FFCBCB;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #102C57;
            font-size: 1.8rem;
        }
        
        .stat-info h3 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .stat-info p {
            font-size: 1.8rem;
            font-weight: bold;
            color: #102C57;
            line-height: 1.2;
        }
        
        .stat-info small {
            color: #1679AB;
            font-size: 0.8rem;
        }
        
        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .quick-stat {
            background: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border-left: 4px solid #1679AB;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .quick-stat .label {
            color: #666;
            font-size: 0.85rem;
            margin-bottom: 5px;
        }
        
        .quick-stat .value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #102C57;
        }
        
        /* OTP Card */
        .otp-card {
            background: linear-gradient(135deg, #102C57, #1679AB);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            box-shadow: 0 5px 20px rgba(22,121,171,0.3);
        }
        
        .otp-card h2 {
            color: #FFCBCB;
            margin-bottom: 20px;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .otp-form {
            display: flex;
            gap: 15px;
            max-width: 500px;
        }
        
        .otp-input {
            flex: 1;
            padding: 15px;
            font-size: 1.5rem;
            font-family: monospace;
            text-align: center;
            letter-spacing: 5px;
            border: 3px solid #FFB1B1;
            border-radius: 10px;
            background: white;
            color: #102C57;
        }
        
        .otp-btn {
            background: #FFB1B1;
            color: #102C57;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .otp-btn:hover {
            background: #FFCBCB;
            transform: translateY(-2px);
        }
        
        /* Search Result */
        .search-result-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border-left: 6px solid #28a745;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .search-result-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .search-result-header i {
            font-size: 2rem;
            color: #28a745;
        }
        
        .search-result-header h3 {
            color: #102C57;
            font-size: 1.5rem;
        }
        
        .guest-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
        }
        
        .info-item .label {
            font-size: 0.85rem;
            color: #1679AB;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .info-item .value {
            font-size: 1.1rem;
            color: #102C57;
        }
        
        .otp-display {
            background: #102C57;
            color: #FFCBCB;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 25px;
        }
        
        .otp-display .code {
            font-family: monospace;
            font-size: 2.5rem;
            font-weight: bold;
            color: #FFB1B1;
            letter-spacing: 10px;
        }
        
        /* MODIFIED: Validity info - date only */
        .validity-info {
            display: flex;
            justify-content: space-between;
            background: #e8f4fd;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .validity-info span {
            font-size: 0.9rem;
        }
        
        .validity-info i {
            color: #1679AB;
            margin-right: 5px;
        }
        
        .validity-date {
            font-weight: bold;
            color: #102C57;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .btn-confirm {
            background: #28a745;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            flex: 2;
            transition: all 0.3s;
        }
        
        .btn-confirm:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            flex: 1;
            transition: all 0.3s;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        .search-error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 10px;
            margin-top: 15px;
            border-left: 4px solid #dc3545;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d4edda; color: #155724; }
        .status-checked_in { background: #cce5ff; color: #004085; }
        
        /* Tables */
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .table-container h3 {
            color: #102C57;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            text-align: left;
            padding: 12px;
            background: #f8f9fa;
            color: #102C57;
            font-weight: 600;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        /* Pass Status */
        .pass-active {
            color: #28a745;
            font-weight: bold;
        }
        
        .pass-used {
            color: #dc3545;
        }
        
        .no-data {
            text-align: center;
            padding: 30px;
            color: #666;
            font-style: italic;
        }
        
        /* Facility badges */
        .facility-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: 5px;
        }
        
        .facility-room { background: #cce5ff; color: #004085; }
        .facility-cottage { background: #d4edda; color: #155724; }
        .facility-pool { background: #fff3cd; color: #856404; }
        
        @media (max-width: 1024px) {
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .guest-info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .otp-form {
                flex-direction: column;
            }
            
            .quick-stats {
                grid-template-columns: 1fr;
            }
            
            .table {
                display: block;
                overflow-x: auto;
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
        <div class="top-bar">
            <h1>
                <i class="fas fa-user-check"></i>
                Staff Dashboard - Entry Verification
            </h1>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
                <span style="margin-left: 15px;"><i class="far fa-clock"></i> <?php echo date('h:i A'); ?></span>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="quick-stat">
                <div class="label">Today's Check-ins</div>
                <div class="value"><?php echo $today_checkins_count; ?></div>
            </div>
            <div class="quick-stat">
                <div class="label">Today's Check-outs</div>
                <div class="value"><?php echo $today_checkouts_count; ?></div>
            </div>
            <div class="quick-stat">
                <div class="label">Current Guests</div>
                <div class="value"><?php echo $current_guests_count; ?></div>
            </div>
            <div class="quick-stat">
                <div class="label">Upcoming</div>
                <div class="value"><?php echo $upcoming_count; ?></div>
            </div>
        </div>
        
        <!-- OTP Verification Card -->
        <div class="otp-card">
            <h2><i class="fas fa-shield-alt"></i> Entry Pass Verification</h2>
            <p style="margin-bottom: 15px;">Enter the OTP code provided by the guest to verify entry</p>
            <form method="POST" class="otp-form">
                <input type="hidden" name="action" value="verify_otp">
                <input type="text" 
                       name="otp" 
                       class="otp-input" 
                       placeholder="000000" 
                       maxlength="6" 
                       pattern="[0-9]{6}" 
                       title="Please enter a 6-digit OTP code"
                       autocomplete="off"
                       required>
                <button type="submit" class="otp-btn">
                    <i class="fas fa-search"></i> Verify Entry
                </button>
            </form>
            
            <?php if ($search_error): ?>
                <div class="search-error">
                    <i class="fas fa-exclamation-circle"></i> 
                    <?php echo $search_error; ?>
                    <form method="POST" style="margin-left: auto;">
                        <input type="hidden" name="action" value="clear_search">
                        <button type="submit" style="background: none; border: none; color: #721c24; cursor: pointer;">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
            <?php if ($searched_reservation && is_array($searched_reservation)): 
                $facility_name = getFacilityName($searched_reservation);
                $facility_type = getFacilityType($searched_reservation);
                $check_in_date = date('M d, Y', strtotime($searched_reservation['check_in_date']));
                $check_out_date = date('M d, Y', strtotime($searched_reservation['check_out_date']));
                $today_date = date('Y-m-d');
                $is_valid_date = ($today_date >= date('Y-m-d', strtotime($searched_reservation['check_in_date'])) && 
                                  $today_date <= date('Y-m-d', strtotime($searched_reservation['check_out_date'])));
            ?>
                <div class="search-result-card">
                    <div class="search-result-header">
                        <i class="fas fa-check-circle"></i>
                        <h3>Valid Entry Pass Found</h3>
                    </div>
                    
                    <div class="guest-info-grid">
                        <div class="info-item">
                            <div class="label">Guest Name</div>
                            <div class="value"><?php echo htmlspecialchars($searched_reservation['guest_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="label">Contact</div>
                            <div class="value"><?php echo htmlspecialchars($searched_reservation['phone'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="label">Facility</div>
                            <div class="value">
                                <?php echo htmlspecialchars($facility_name); ?>
                                <?php if (!empty($facility_type)): ?>
                                    <span class="facility-badge facility-<?php echo $facility_type; ?>">
                                        <?php echo ucfirst($facility_type); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="label">Reservation Period</div>
                            <div class="value">
                                <?php echo $check_in_date; ?> - <?php echo $check_out_date; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="label">Number of Guests</div>
                            <div class="value"><?php echo $searched_reservation['guests'] ?? 'N/A'; ?> persons</div>
                        </div>
                        <div class="info-item">
                            <div class="label">Current Status</div>
                            <div class="value">
                                <span class="status-badge status-<?php echo $searched_reservation['status'] ?? 'pending'; ?>">
                                    <?php echo ucfirst($searched_reservation['status'] ?? 'N/A'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="otp-display">
                        <div class="label" style="color: #FFCBCB; margin-bottom: 5px;">VERIFICATION CODE</div>
                        <div class="code"><?php echo $searched_reservation['otp_code'] ?? 'N/A'; ?></div>
                    </div>
                    
                    <!-- MODIFIED: Validity info - date only -->
                    <div class="validity-info">
                        <span>
                            <i class="far fa-calendar-alt"></i> Valid From: 
                            <span class="validity-date"><?php echo $check_in_date; ?></span>
                        </span>
                        <span>
                            <i class="far fa-calendar-times"></i> Valid Until: 
                            <span class="validity-date"><?php echo $check_out_date; ?></span>
                        </span>
                    </div>
                    
                    <?php if ($searched_reservation['status'] == 'checked_in'): ?>
                        <div style="background: #cce5ff; color: #004085; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                            <i class="fas fa-info-circle"></i> Guest is already checked in.
                        </div>
                    <?php elseif (!$is_valid_date): ?>
                        <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                            <i class="fas fa-exclamation-triangle"></i> This pass is not valid for today's date.
                        </div>
                    <?php else: ?>
                        <div class="action-buttons">
                            <form method="POST" style="flex: 2;">
                                <input type="hidden" name="action" value="confirm_entry">
                                <input type="hidden" name="pass_id" value="<?php echo $searched_reservation['id'] ?? 0; ?>">
                                <input type="hidden" name="reservation_id" value="<?php echo $searched_reservation['reservation_id'] ?? 0; ?>">
                                <button type="submit" class="btn-confirm" onclick="return confirm('Confirm entry for this guest? This will check them in.')">
                                    <i class="fas fa-check-circle"></i> CHECK IN GUEST
                                </button>
                            </form>
                            <form method="POST" style="flex: 1;">
                                <input type="hidden" name="action" value="clear_search">
                                <button type="submit" class="btn-cancel">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Today's Expected Check-ins -->
        <div class="table-container">
            <h3><i class="fas fa-sign-in-alt" style="color: #28a745;"></i> Today's Expected Check-ins (<?php echo $today_checkins_count; ?>)</h3>
            <?php if (empty($today_checkins)): ?>
                <div class="no-data">No check-ins scheduled for today</div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Guest</th>
                            <th>Contact</th>
                            <th>Facility</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($today_checkins as $checkin): ?>
                        <tr>
                            <td><?php echo date('h:i A', strtotime($checkin['check_in_date'])); ?></td>
                            <td><?php echo htmlspecialchars($checkin['guest_name']); ?></td>
                            <td><?php echo $checkin['phone']; ?></td>
                            <td><?php echo getFacilityName($checkin); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $checkin['status']; ?>">
                                    <?php echo ucfirst($checkin['status']); ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="quick_checkin">
                                    <input type="hidden" name="reservation_id" value="<?php echo $checkin['id']; ?>">
                                    <button type="submit" class="btn-confirm" style="padding: 5px 10px; font-size: 0.8rem;" onclick="return confirm('Check in this guest?')">
                                        <i class="fas fa-check"></i> Check In
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
      
    
    <script>
        // Format OTP input - only numbers
        document.querySelector('.otp-input')?.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 6);
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
        
        // Focus on OTP input on page load
        document.querySelector('.otp-input')?.focus();
    </script>
</body>
</html>