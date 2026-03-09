<?php
/**
 * Veripool Reservation System - Staff Guest Management Page
 * Manage all guest check-ins and check-outs with OTP verification
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

// Check if user is logged in and is staff
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'staff') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Initialize Entry Pass Manager
$entryPassManager = new EntryPassManager($db);

// Get current user
$user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Initialize ALL variables as empty arrays FIRST
$today_checkins = [];
$today_checkouts = [];
$current_guests = [];
$upcoming_reservations = [];
$recent_history = [];
$otp_search_result = null;
$otp_search_error = '';

// Helper function to safely get data
function safeGetData($db, $query, $params = []) {
    try {
        // Check if getRows method exists
        if (!method_exists($db, 'getRows')) {
            error_log("safeGetData ERROR: getRows method doesn't exist");
            return [];
        }
        
        $result = $db->getRows($query, $params);
        
        // If it's an array, return it
        if (is_array($result)) {
            return $result;
        }
        
        // If it's null, false, or any other non-array, return empty array
        error_log("Query did not return array, got: " . gettype($result));
        return [];
        
    } catch (Exception $e) {
        error_log("Query error: " . $e->getMessage());
        return [];
    }
}

// Get today's expected check-ins
$today_checkins = safeGetData($db, "
    SELECT r.*, u.full_name as guest_name, u.phone, u.email, 
           rm.room_number, rt.name as room_type, c.cottage_name,
           c.price as cottage_price, rt.base_price as room_price,
           ep.otp_code, ep.status as pass_status
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
    LEFT JOIN cottages c ON rc.cottage_id = c.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.check_in_date = CURDATE() 
    AND r.status IN ('confirmed', 'pending')
    ORDER BY r.created_at ASC
");

// Get today's expected check-outs
$today_checkouts = safeGetData($db, "
    SELECT r.*, u.full_name as guest_name, u.phone, 
           rm.room_number, rt.name as room_type, c.cottage_name,
           c.price as cottage_price, rt.base_price as room_price,
           ep.otp_code, ep.status as pass_status
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
    LEFT JOIN cottages c ON rc.cottage_id = c.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.check_out_date = CURDATE() 
    AND r.status IN ('checked_in', 'confirmed')
    ORDER BY r.check_out_date ASC
");

// Get currently checked-in guests
$current_guests = safeGetData($db, "
    SELECT r.*, u.full_name as guest_name, u.phone, 
           rm.room_number, rt.name as room_type, c.cottage_name,
           c.price as cottage_price, rt.base_price as room_price,
           DATEDIFF(CURDATE(), r.check_in_date) as nights_stayed,
           DATEDIFF(r.check_out_date, CURDATE()) as nights_remaining,
           ep.otp_code, ep.status as pass_status,
           ep.valid_from, ep.valid_until
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
    LEFT JOIN cottages c ON rc.cottage_id = c.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.status = 'checked_in'
    ORDER BY r.check_out_date ASC
");

// Get upcoming reservations (next 7 days)
$upcoming_reservations = safeGetData($db, "
    SELECT r.*, u.full_name as guest_name, u.phone, 
           rm.room_number, rt.name as room_type, c.cottage_name,
           DATEDIFF(r.check_in_date, CURDATE()) as days_until,
           ep.otp_code, ep.status as pass_status
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
    LEFT JOIN cottages c ON rc.cottage_id = c.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.check_in_date > CURDATE() 
    AND r.check_in_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND r.status IN ('confirmed', 'pending')
    ORDER BY r.check_in_date ASC
");

// Get recent history
$recent_history = safeGetData($db, "
    SELECT r.id, r.status, r.created_at, r.check_in_date, r.check_out_date,
           u.full_name as guest_name,
           rm.room_number, c.cottage_name,
           ep.otp_code
    FROM reservations r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
    LEFT JOIN cottages c ON rc.cottage_id = c.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY r.created_at DESC
    LIMIT 20
");

// FINAL SAFETY CHECK - Force everything to be arrays
$today_checkins = (is_array($today_checkins)) ? $today_checkins : [];
$today_checkouts = (is_array($today_checkouts)) ? $today_checkouts : [];
$current_guests = (is_array($current_guests)) ? $current_guests : [];
$upcoming_reservations = (is_array($upcoming_reservations)) ? $upcoming_reservations : [];
$recent_history = (is_array($recent_history)) ? $recent_history : [];

// Calculate safe counts for use in HTML
$safe_checkins_count = count($today_checkins);
$safe_checkouts_count = count($today_checkouts);
$safe_current_count = count($current_guests);
$safe_upcoming_count = count($upcoming_reservations);
$safe_recent_count = count($recent_history);

// CREATE A BACKUP COPY THAT CAN'T BE OVERWRITTEN
$CURRENT_GUESTS_BACKUP = $current_guests;
$DISPLAY_CURRENT_GUESTS = $current_guests;

// Force it to be an array one more time
if (!is_array($DISPLAY_CURRENT_GUESTS)) {
    $DISPLAY_CURRENT_GUESTS = [];
    error_log("CRITICAL: DISPLAY_CURRENT_GUESTS was not an array, reset to empty array");
}

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // ===== OTP SEARCH =====
        if ($_POST['action'] === 'search_otp') {
            $otp = sanitize($_POST['otp_code']);
            
            // Use EntryPassManager to verify OTP
            $verification = $entryPassManager->verifyEntryPass($otp);
            
            if ($verification['success']) {
                $otp_search_result = $verification['data'];
            } else {
                $otp_search_error = $verification['message'];
            }
        }
        
        // ===== OTP CHECK-IN =====
        if ($_POST['action'] === 'otp_checkin' && isset($_POST['pass_id'])) {
            $pass_id = (int)$_POST['pass_id'];
            $reservation_id = (int)$_POST['reservation_id'];
            $otp_code = sanitize($_POST['otp_code']);
            
            // Use EntryPassManager to verify and use the pass
            $result = $entryPassManager->verifyOTP($otp_code, $reservation_id);
            
            if ($result['success']) {
                $message = "Guest checked in successfully via OTP!";
                $message_type = 'success';
                $otp_search_result = null;
            } else {
                $message = "Error: " . $result['message'];
                $message_type = 'error';
            }
        }
        
        // ===== REGULAR CHECK-IN =====
        if ($_POST['action'] === 'checkin' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            
            // Get reservation details
            $reservation = $db->getRow("
                SELECT r.*, rm.id as room_id, ep.id as pass_id, ep.otp_code
                FROM reservations r
                LEFT JOIN rooms rm ON r.room_id = rm.id
                LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
                WHERE r.id = ?
            ", [$reservation_id]);
            
            if ($reservation && is_array($reservation)) {
                $db->beginTransaction();
                
                try {
                    // If there's an entry pass, use it
                    if (!empty($reservation['pass_id']) && !empty($reservation['otp_code'])) {
                        $result = $entryPassManager->verifyOTP($reservation['otp_code'], $reservation_id);
                        if (!$result['success']) {
                            throw new Exception($result['message']);
                        }
                    } else {
                        // No OTP, just update status
                        $db->update('reservations', [
                            'status' => 'checked_in',
                            'verified_by' => $_SESSION['user_id'],
                            'verified_at' => date('Y-m-d H:i:s')
                        ], 'id = :id', ['id' => $reservation_id]);
                        
                        // Update room status if room exists
                        if (!empty($reservation['room_id'])) {
                            $db->update('rooms', 
                                ['status' => 'occupied'], 
                                'id = :id', 
                                ['id' => $reservation['room_id']]
                            );
                        }
                    }
                    
                    $db->commit();
                    
                    $message = "Guest checked in successfully";
                    $message_type = 'success';
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $message = "Error during check-in: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
        
        // ===== CHECK-OUT =====
        if ($_POST['action'] === 'checkout' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            
            // Get reservation details
            $reservation = $db->getRow("
                SELECT r.*, rm.id as room_id
                FROM reservations r
                LEFT JOIN rooms rm ON r.room_id = rm.id
                WHERE r.id = ?
            ", [$reservation_id]);
            
            if ($reservation && is_array($reservation)) {
                $db->beginTransaction();
                
                try {
                    // Deactivate any active entry passes
                    $db->update('entry_passes', 
                        ['status' => 'used', 'used_at' => date('Y-m-d H:i:s')], 
                        'reservation_id = :id AND status = :status', 
                        ['id' => $reservation_id, 'status' => 'active']
                    );
                    
                    // Update reservation status
                    $db->update('reservations', [
                        'status' => 'checked_out',
                        'checked_out_by' => $_SESSION['user_id'],
                        'checked_out_at' => date('Y-m-d H:i:s')
                    ], 'id = :id', ['id' => $reservation_id]);
                    
                    // Update room status if room exists
                    if (!empty($reservation['room_id'])) {
                        $db->update('rooms', 
                            ['status' => 'available', 'cleaning_status' => 'pending'], 
                            'id = :id', 
                            ['id' => $reservation['room_id']]
                        );
                    }
                    
                    $db->commit();
                    
                    $message = "Guest checked out successfully";
                    $message_type = 'success';
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $message = "Error during check-out: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
        
        // ===== EXTEND STAY =====
        if ($_POST['action'] === 'extend_stay' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            $additional_days = (int)$_POST['additional_days'];
            $payment_method = sanitize($_POST['payment_method']);
            
            // Get reservation details with pricing
            $reservation = $db->getRow("
                SELECT r.*, rm.id as room_id, rt.base_price as room_price,
                       c.id as cottage_id, c.price as cottage_price,
                       ep.id as pass_id
                FROM reservations r
                LEFT JOIN rooms rm ON r.room_id = rm.id
                LEFT JOIN room_types rt ON rm.room_type_id = rt.id
                LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
                LEFT JOIN cottages c ON rc.cottage_id = c.id
                LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
                WHERE r.id = ?
            ", [$reservation_id]);
            
            if ($reservation && is_array($reservation) && $additional_days > 0) {
                $db->beginTransaction();
                
                try {
                    // Calculate additional cost
                    $daily_rate = 0;
                    if (!empty($reservation['room_price'])) {
                        $daily_rate += $reservation['room_price'];
                    }
                    if (!empty($reservation['cottage_price'])) {
                        $daily_rate += $reservation['cottage_price'];
                    }
                    
                    $additional_cost = $daily_rate * $additional_days;
                    
                    // Calculate new check-out date
                    $new_checkout = date('Y-m-d', strtotime($reservation['check_out_date'] . ' + ' . $additional_days . ' days'));
                    
                    // Update reservation
                    $db->update('reservations', [
                        'check_out_date' => $new_checkout,
                        'total_amount' => $reservation['total_amount'] + $additional_cost,
                        'amount_paid' => $reservation['amount_paid'] + $additional_cost,
                        'status' => 'checked_in',
                        'extended_at' => date('Y-m-d H:i:s'),
                        'extended_by' => $_SESSION['user_id']
                    ], 'id = :id', ['id' => $reservation_id]);
                    
                    // Update entry pass validity if exists
                    if (!empty($reservation['pass_id'])) {
                        $db->update('entry_passes', [
                            'valid_until' => $new_checkout . ' 12:00:00'
                        ], 'id = :id', ['id' => $reservation['pass_id']]);
                    }
                    
                    // Record additional payment
                    $payment_number = 'EXT' . date('Ymd') . rand(1000, 9999);
                    $payment_data = [
                        'payment_number' => $payment_number,
                        'reservation_id' => $reservation_id,
                        'amount' => $additional_cost,
                        'payment_method' => $payment_method,
                        'payment_status' => 'completed',
                        'created_by' => $_SESSION['user_id'],
                        'payment_date' => date('Y-m-d H:i:s'),
                        'notes' => 'Extension payment for ' . $additional_days . ' additional day(s)'
                    ];
                    
                    $db->insert('payments', $payment_data);
                    
                    $db->commit();
                    
                    $message = "Stay extended by $additional_days day(s). Additional payment: ₱" . number_format($additional_cost, 2);
                    $message_type = 'success';
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $message = "Error extending stay: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
        
        // ===== CANCEL RESERVATION =====
        if ($_POST['action'] === 'cancel' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            $cancel_reason = sanitize($_POST['cancel_reason'] ?? 'No reason provided');
            
            $db->update('reservations', [
                'status' => 'cancelled',
                'cancelled_by' => $_SESSION['user_id'],
                'cancelled_at' => date('Y-m-d H:i:s'),
                'cancellation_reason' => $cancel_reason
            ], 'id = :id', ['id' => $reservation_id]);
            
            // Deactivate any entry passes
            $db->update('entry_passes', 
                ['status' => 'cancelled'], 
                'reservation_id = :id', 
                ['id' => $reservation_id]
            );
            
            $message = "Reservation cancelled successfully";
            $message_type = 'success';
        }
        
        // ===== CLEAR OTP SEARCH =====
        if ($_POST['action'] === 'clear_otp_search') {
            $otp_search_result = null;
            $otp_search_error = '';
        }
        
        // Refresh the page to show updated data (without resubmitting form)
        if ($message) {
            // Don't redirect if we have a message to show
            // We'll refresh data manually
            $today_checkins = safeGetData($db, "
                SELECT r.*, u.full_name as guest_name, u.phone, u.email, 
                       rm.room_number, rt.name as room_type, c.cottage_name,
                       c.price as cottage_price, rt.base_price as room_price,
                       ep.otp_code, ep.status as pass_status
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN rooms rm ON r.room_id = rm.id
                LEFT JOIN room_types rt ON rm.room_type_id = rt.id
                LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
                LEFT JOIN cottages c ON rc.cottage_id = c.id
                LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
                WHERE r.check_in_date = CURDATE() 
                AND r.status IN ('confirmed', 'pending')
                ORDER BY r.created_at ASC
            ");
            
            $today_checkouts = safeGetData($db, "
                SELECT r.*, u.full_name as guest_name, u.phone, 
                       rm.room_number, rt.name as room_type, c.cottage_name,
                       c.price as cottage_price, rt.base_price as room_price,
                       ep.otp_code, ep.status as pass_status
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN rooms rm ON r.room_id = rm.id
                LEFT JOIN room_types rt ON rm.room_type_id = rt.id
                LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
                LEFT JOIN cottages c ON rc.cottage_id = c.id
                LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
                WHERE r.check_out_date = CURDATE() 
                AND r.status IN ('checked_in', 'confirmed')
                ORDER BY r.check_out_date ASC
            ");
            
            $current_guests = safeGetData($db, "
                SELECT r.*, u.full_name as guest_name, u.phone, 
                       rm.room_number, rt.name as room_type, c.cottage_name,
                       c.price as cottage_price, rt.base_price as room_price,
                       DATEDIFF(CURDATE(), r.check_in_date) as nights_stayed,
                       DATEDIFF(r.check_out_date, CURDATE()) as nights_remaining,
                       ep.otp_code, ep.status as pass_status,
                       ep.valid_from, ep.valid_until
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN rooms rm ON r.room_id = rm.id
                LEFT JOIN room_types rt ON rm.room_type_id = rt.id
                LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
                LEFT JOIN cottages c ON rc.cottage_id = c.id
                LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
                WHERE r.status = 'checked_in'
                ORDER BY r.check_out_date ASC
            ");
            
            $CURRENT_GUESTS_BACKUP = $current_guests;
            $DISPLAY_CURRENT_GUESTS = $current_guests;
            $safe_current_count = count($current_guests);
        } else {
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    switch($status) {
        case 'checked_in':
            return 'status-checked_in';
        case 'checked_out':
            return 'status-checked_out';
        case 'confirmed':
            return 'status-confirmed';
        case 'pending':
            return 'status-pending';
        case 'cancelled':
            return 'status-cancelled';
        default:
            return 'status-pending';
    }
}

// Helper function to get status icon
function getStatusIcon($status) {
    switch($status) {
        case 'checked_in':
            return 'fa-sign-in-alt';
        case 'checked_out':
            return 'fa-sign-out-alt';
        case 'confirmed':
            return 'fa-check-circle';
        case 'pending':
            return 'fa-clock';
        case 'cancelled':
            return 'fa-times-circle';
        default:
            return 'fa-question-circle';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Management - Staff Portal</title>
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
        /* Global Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f7fc;
            color: #333;
            line-height: 1.6;
        }
        
        /* Main Content Area */
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
            min-height: 100vh;
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
        
        /* OTP Search Card */
        .otp-search-card {
            background: linear-gradient(135deg, #102C57, #1679AB);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            color: white;
            box-shadow: 0 5px 20px rgba(22,121,171,0.3);
        }
        
        .otp-search-card h2 {
            color: #FFCBCB;
            margin-bottom: 15px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .otp-search-form {
            display: flex;
            gap: 15px;
            max-width: 500px;
        }
        
        .otp-search-input {
            flex: 1;
            padding: 12px 15px;
            font-size: 1.2rem;
            font-family: monospace;
            text-align: center;
            letter-spacing: 3px;
            border: 2px solid #FFB1B1;
            border-radius: 8px;
            background: white;
            color: #102C57;
        }
        
        .otp-search-btn {
            background: #FFB1B1;
            color: #102C57;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .otp-search-btn:hover {
            background: #FFCBCB;
            transform: translateY(-2px);
        }
        
        /* OTP Search Result */
        .otp-result-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            border-left: 6px solid #28a745;
            animation: slideDown 0.3s ease;
        }
        
        .otp-result-error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            border-left: 4px solid #dc3545;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .otp-result-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .otp-result-header i {
            font-size: 2rem;
            color: #28a745;
        }
        
        .otp-result-header h3 {
            color: #102C57;
            font-size: 1.3rem;
        }
        
        .otp-result-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .otp-info-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
        }
        
        .otp-info-item .label {
            font-size: 0.8rem;
            color: #1679AB;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .otp-info-item .value {
            font-size: 1rem;
            color: #102C57;
            font-weight: 500;
        }
        
        .otp-code-display {
            background: #102C57;
            color: #FFCBCB;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }
        
        .otp-code-display .code {
            font-family: monospace;
            font-size: 2rem;
            font-weight: bold;
            letter-spacing: 5px;
        }
        
        .otp-validity {
            display: flex;
            justify-content: space-between;
            background: #e8f4fd;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .otp-validity span {
            font-size: 0.9rem;
        }
        
        .otp-validity i {
            color: #1679AB;
            margin-right: 5px;
        }
        
        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
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
        
        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .stat-icon.checkin { background: #d4edda; color: #155724; }
        .stat-icon.checkout { background: #fff3cd; color: #856404; }
        .stat-icon.current { background: #cce5ff; color: #004085; }
        .stat-icon.upcoming { background: #e2d5f1; color: #4a2c7a; }
        
        .stat-details h3 {
            font-size: 2rem;
            margin: 0;
            color: #102C57;
            font-weight: 600;
        }
        
        .stat-details p {
            margin: 5px 0 0;
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .section-header h2 {
            color: #102C57;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-header h2 i {
            font-size: 1.2rem;
        }
        
        .section-header .badge {
            background: #1679AB;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Grid Layout */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
        
        /* Guest Cards */
        .guest-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #1679AB;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .guest-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1679AB, #102C57);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .guest-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .guest-card:hover::before {
            opacity: 1;
        }
        
        .guest-card.checkin { border-left-color: #28a745; }
        .guest-card.checkout { border-left-color: #ffc107; }
        .guest-card.current { border-left-color: #17a2b8; }
        
        .guest-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .guest-name {
            font-weight: 600;
            color: #102C57;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .guest-room {
            background: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: #102C57;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .guest-room i {
            margin-right: 5px;
            color: #1679AB;
        }
        
        .guest-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin: 15px 0;
            font-size: 0.9rem;
            color: #666;
        }
        
        .guest-details div {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .guest-details i {
            width: 18px;
            color: #1679AB;
        }
        
        .guest-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
            justify-content: flex-end;
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
        }
        
        /* Buttons */
        .btn-action {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-action i {
            font-size: 0.9rem;
        }
        
        .btn-checkin {
            background: #28a745;
            color: white;
        }
        
        .btn-checkin:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }
        
        .btn-checkout {
            background: #ffc107;
            color: #102C57;
        }
        
        .btn-checkout:hover {
            background: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
        }
        
        .btn-extend {
            background: #17a2b8;
            color: white;
        }
        
        .btn-extend:hover {
            background: #138496;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(23, 162, 184, 0.3);
        }
        
        .btn-cancel {
            background: #dc3545;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        
        .btn-view {
            background: #6c757d;
            color: white;
        }
        
        .btn-view:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
        }
        
        .btn-sm {
            padding: 5px 12px;
            font-size: 0.85rem;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #102C57;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .status-checked_in {
            background: #d4edda;
            color: #155724;
        }
        
        .status-checked_out {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-confirmed {
            background: #cce5ff;
            color: #004085;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* OTP Badge */
        .otp-badge {
            background: #102C57;
            color: #FFCBCB;
            padding: 3px 8px;
            border-radius: 12px;
            font-family: monospace;
            font-size: 0.85rem;
            font-weight: bold;
        }
        
        .pass-active {
            background: #d4edda;
            color: #155724;
            border: 1px solid #28a745;
        }
        
        .pass-used {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #dc3545;
        }
        
        /* Add to your existing CSS */
.btn-group {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.btn-action.btn-sm {
    padding: 5px 10px;
    font-size: 0.8rem;
}

.btn-action.btn-sm i {
    font-size: 0.8rem;
    margin-right: 3px;
}

/* Tooltip styles */
.btn-action[title] {
    position: relative;
}

.btn-action[title]:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #333;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    white-space: nowrap;
    z-index: 1000;
    margin-bottom: 5px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
    }
    
    .btn-action.btn-sm {
        width: 100%;
        justify-content: center;
    }
}
        /* Nights Badge */
        .nights-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            background: #e9ecef;
            font-size: 0.8rem;
            color: #102C57;
            font-weight: 500;
        }
        
        /* OTP Code */
        .otp-code {
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            font-weight: bold;
            color: #1679AB;
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 4px;
            letter-spacing: 1px;
        }
        
        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            text-align: left;
            padding: 12px;
            background: #f8f9fa;
            color: #102C57;
            font-weight: 600;
            border-bottom: 2px solid #FFCBCB;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        tr:hover td {
            background: #f8f9fa;
        }
        
        /* Timeline */
        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
            transition: transform 0.3s;
        }
        
        .timeline-item:hover {
            transform: translateX(5px);
            background: #e9ecef;
        }
        
        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .timeline-icon.checkin { background: #d4edda; color: #155724; }
        .timeline-icon.checkout { background: #fff3cd; color: #856404; }
        .timeline-icon.cancelled { background: #f8d7da; color: #721c24; }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-time {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .timeline-time i {
            margin-right: 5px;
            color: #1679AB;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-content h3 {
            color: #102C57;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .modal-content .form-group {
            margin-bottom: 20px;
        }
        
        .modal-content label {
            display: block;
            margin-bottom: 8px;
            color: #102C57;
            font-weight: 500;
        }
        
        .modal-content .form-control {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .modal-content .form-control:focus {
            border-color: #1679AB;
            outline: none;
        }
        
        .modal-content .price-display {
            background: #e8f4f8;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            text-align: center;
        }
        
        .modal-content .price-display .label {
            font-size: 0.9rem;
            color: #102C57;
        }
        
        .modal-content .price-display .amount {
            font-size: 1.8rem;
            font-weight: bold;
            color: #1679AB;
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }
        
        .modal-actions button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        /* Menu Toggle */
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
            
            .otp-search-form {
                flex-direction: column;
            }
            
            .otp-result-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Utility Classes */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-success { color: #28a745; }
        .text-warning { color: #ffc107; }
        .text-danger { color: #dc3545; }
        .text-info { color: #17a2b8; }
        
        .mb-0 { margin-bottom: 0; }
        .mb-2 { margin-bottom: 10px; }
        .mb-3 { margin-bottom: 15px; }
        .mb-4 { margin-bottom: 20px; }
        
        .mt-2 { margin-top: 10px; }
        .mt-3 { margin-top: 15px; }
        .mt-4 { margin-top: 20px; }
        
        .font-weight-bold { font-weight: 600; }
        .font-weight-normal { font-weight: 400; }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .guest-details {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .guest-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .guest-actions {
                justify-content: flex-start;
            }
            
            .modal-actions {
                flex-direction: column;
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
            <i class="fas fa-users"></i>
            Guest Management
        </h1>
        <div class="date">
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
    
   
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon checkin">
                <i class="fas fa-sign-in-alt"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $safe_checkins_count; ?></h3>
                <p>Expected Check-ins Today</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon checkout">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $safe_checkouts_count; ?></h3>
                <p>Expected Check-outs Today</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon current">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $safe_current_count; ?></h3>
                <p>Currently Checked In</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon upcoming">
                <i class="fas fa-calendar-week"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $safe_upcoming_count; ?></h3>
                <p>Upcoming (Next 7 Days)</p>
            </div>
        </div>
    </div>
    
    <!-- Today's Check-ins and Check-outs Grid -->
    <div class="grid-2">
        <!-- Today's Check-ins -->
        <div class="card">
            <div class="section-header">
                <h2><i class="fas fa-sign-in-alt" style="color: #28a745;"></i> Today's Expected Check-ins</h2>
                <span class="badge"><?php echo $safe_checkins_count; ?></span>
            </div>
            
            <?php if (empty($today_checkins)): ?>
                <p class="text-center" style="color: #666; padding: 30px;">No check-ins scheduled for today.</p>
            <?php else: ?>
                <?php foreach ($today_checkins as $guest): ?>
                <div class="guest-card checkin">
                    <div class="guest-header">
                        <span class="guest-name">
                            <i class="fas fa-user-circle"></i>
                            <?php echo htmlspecialchars($guest['guest_name'] ?? ''); ?>
                        </span>
                        <span class="guest-room">
                            <i class="fas fa-bed"></i> 
                            <?php 
                            if (!empty($guest['room_number'])) {
                                echo 'Room ' . $guest['room_number'];
                            } elseif (!empty($guest['cottage_name'])) {
                                echo $guest['cottage_name'];
                            } else {
                                echo 'Day Tour';
                            }
                            ?>
                        </span>
                    </div>
                    
                    <div class="guest-details">
                        <div>
                            <i class="fas fa-phone"></i> <?php echo $guest['phone'] ?? 'N/A'; ?>
                        </div>
                        <div>
                            <i class="fas fa-calendar-check"></i> 
                            Check-in: <?php echo !empty($guest['check_in_date']) ? date('g:i A', strtotime($guest['check_in_date'])) : 'N/A'; ?>
                        </div>
                        <?php if (!empty($guest['total_guests'])): ?>
                        <div>
                            <i class="fas fa-users"></i> <?php echo $guest['total_guests']; ?> guests
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($guest['otp_code'])): ?>
                        <div>
                            <i class="fas fa-key"></i> 
                            <span class="otp-badge <?php echo $guest['pass_status'] == 'active' ? 'pass-active' : ''; ?>">
                                <?php echo $guest['otp_code']; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="guest-actions">
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Check in this guest?')">
                            <input type="hidden" name="action" value="checkin">
                            <input type="hidden" name="reservation_id" value="<?php echo $guest['id'] ?? 0; ?>">
                            <button type="submit" class="btn-action btn-checkin">
                                <i class="fas fa-check"></i> Check In
                            </button>
                        </form>
                        
                        <button type="button" class="btn-action btn-cancel" onclick="showCancelModal(<?php echo $guest['id'] ?? 0; ?>)">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Today's Check-outs -->
        <div class="card">
            <div class="section-header">
                <h2><i class="fas fa-sign-out-alt" style="color: #ffc107;"></i> Today's Expected Check-outs</h2>
                <span class="badge"><?php echo $safe_checkouts_count; ?></span>
            </div>
            
            <?php if (empty($today_checkouts)): ?>
                <p class="text-center" style="color: #666; padding: 30px;">No check-outs scheduled for today.</p>
            <?php else: ?>
                <?php foreach ($today_checkouts as $guest): ?>
                <div class="guest-card checkout">
                    <div class="guest-header">
                        <span class="guest-name">
                            <i class="fas fa-user-circle"></i>
                            <?php echo htmlspecialchars($guest['guest_name'] ?? ''); ?>
                        </span>
                        <span class="guest-room">
                            <i class="fas fa-bed"></i> 
                            <?php 
                            if (!empty($guest['room_number'])) {
                                echo 'Room ' . $guest['room_number'];
                            } elseif (!empty($guest['cottage_name'])) {
                                echo $guest['cottage_name'];
                            } else {
                                echo 'Day Tour';
                            }
                            ?>
                        </span>
                    </div>
                    
                    <div class="guest-details">
                        <div>
                            <i class="fas fa-phone"></i> <?php echo $guest['phone'] ?? 'N/A'; ?>
                        </div>
                        <div>
                            <i class="fas fa-calendar-times"></i> 
                            Check-out: <?php echo !empty($guest['check_out_date']) ? date('g:i A', strtotime($guest['check_out_date'])) : 'N/A'; ?>
                        </div>
                        <?php if (!empty($guest['status']) && $guest['status'] == 'checked_in'): ?>
                        <div>
                            <span class="status-badge status-checked_in">
                                <i class="fas fa-check-circle"></i> Currently Checked In
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($guest['otp_code'])): ?>
                        <div>
                            <i class="fas fa-key"></i> 
                            <span class="otp-badge"><?php echo $guest['otp_code']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="guest-actions">
                        <?php if (!empty($guest['status']) && $guest['status'] == 'checked_in'): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Check out this guest?')">
                            <input type="hidden" name="action" value="checkout">
                            <input type="hidden" name="reservation_id" value="<?php echo $guest['id'] ?? 0; ?>">
                            <button type="submit" class="btn-action btn-checkout">
                                <i class="fas fa-sign-out-alt"></i> Check Out
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="status-badge status-confirmed">
                            <i class="fas fa-clock"></i> Expected Check-in
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
   <!-- Currently Checked In Guests -->
<div class="card">
    <div class="section-header">
        <h2><i class="fas fa-user-check" style="color: #17a2b8;"></i> Currently Checked In Guests</h2>
        <span class="badge"><?php echo $safe_current_count; ?> active</span>
    </div>
    
    <?php 
    // USE THE BACKUP VARIABLE
    $guests_to_display = $DISPLAY_CURRENT_GUESTS;
    
    if (empty($guests_to_display) || !is_array($guests_to_display)): 
    ?>
        <p class="text-center" style="color: #666; padding: 30px;">No guests currently checked in.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Room/Cottage</th>
                        <th>Check-in Date</th>
                        <th>Check-out Date</th>
                        <th>Stay Duration</th>
                        <th>OTP</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guests_to_display as $guest): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($guest['guest_name'] ?? 'Unknown'); ?></strong>
                            <br><small><?php echo $guest['phone'] ?? 'N/A'; ?></small>
                        </td>
                        <td>
                            <?php if (!empty($guest['room_number'])): ?>
                                Room <?php echo $guest['room_number']; ?>
                                <br><small><?php echo $guest['room_type'] ?? ''; ?></small>
                            <?php elseif (!empty($guest['cottage_name'])): ?>
                                <?php echo $guest['cottage_name']; ?>
                            <?php else: ?>
                                Day Tour Only
                            <?php endif; ?>
                        </td>
                        <td><?php echo !empty($guest['check_in_date']) ? date('M d, Y', strtotime($guest['check_in_date'])) : 'N/A'; ?></td>
                        <td><?php echo !empty($guest['check_out_date']) ? date('M d, Y', strtotime($guest['check_out_date'])) : 'N/A'; ?></td>
                        <td>
                            <span class="nights-badge"><?php echo $guest['nights_stayed'] ?? 0; ?> night(s) stayed</span>
                            <br>
                            <span class="nights-badge" style="background: #cce5ff; color: #004085;"><?php echo $guest['nights_remaining'] ?? 0; ?> night(s) left</span>
                        </td>
                        <td>
                            <?php if (!empty($guest['otp_code'])): ?>
                                <span class="otp-badge <?php echo $guest['pass_status'] == 'active' ? 'pass-active' : 'pass-used'; ?>">
                                    <?php echo $guest['otp_code']; ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-checked_in">
                                <i class="fas fa-check-circle"></i> Checked In
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                <!-- Checkout Button - Always Visible -->
                                <form method="POST" style="display: inline;" onsubmit="return confirmCheckout('<?php echo htmlspecialchars($guest['guest_name'] ?? ''); ?>')">
                                    <input type="hidden" name="action" value="checkout">
                                    <input type="hidden" name="reservation_id" value="<?php echo $guest['id'] ?? 0; ?>">
                                    <button type="submit" class="btn-action btn-checkout btn-sm" title="Check out this guest">
                                        <i class="fas fa-sign-out-alt"></i> Check Out
                                    </button>
                                </form>
                                
                                <!-- Extend Button -->
                                <button type="button" class="btn-action btn-extend btn-sm" onclick="showExtendModal(<?php echo $guest['id'] ?? 0; ?>, <?php echo $guest['room_price'] ?? 0; ?>, <?php echo $guest['cottage_price'] ?? 0; ?>)" title="Extend stay">
                                    <i class="fas fa-calendar-plus"></i> Extend
                                </button>
                                
                                
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
    <!-- Upcoming Reservations -->
    <div class="card">
        <div class="section-header">
            <h2><i class="fas fa-calendar-alt" style="color: #4a2c7a;"></i> Upcoming Reservations (Next 7 Days)</h2>
            <span class="badge"><?php echo $safe_upcoming_count; ?> upcoming</span>
        </div>
        
        <?php if (empty($upcoming_reservations)): ?>
            <p class="text-center" style="color: #666; padding: 30px;">No upcoming reservations in the next 7 days.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reservation #</th>
                            <th>Guest</th>
                            <th>Room/Cottage</th>
                            <th>Check-in Date</th>
                            <th>Days Until</th>
                            <th>Guests</th>
                            <th>OTP</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming_reservations as $res): ?>
                        <tr>
                            <td><strong><?php echo $res['reservation_number'] ?? ''; ?></strong></td>
                            <td>
                                <?php echo htmlspecialchars($res['guest_name'] ?? ''); ?>
                                <br><small><?php echo $res['phone'] ?? ''; ?></small>
                            </td>
                            <td>
                                <?php if (!empty($res['room_number'])): ?>
                                    Room <?php echo $res['room_number']; ?>
                                    <br><small><?php echo $res['room_type'] ?? ''; ?></small>
                                <?php elseif (!empty($res['cottage_name'])): ?>
                                    <?php echo $res['cottage_name']; ?>
                                <?php else: ?>
                                    Day Tour Only
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($res['check_in_date']) ? date('M d, Y', strtotime($res['check_in_date'])) : 'N/A'; ?></td>
                            <td>
                                <?php 
                                $days = $res['days_until'] ?? 0;
                                if ($days == 0) {
                                    echo "<span class='text-success font-weight-bold'>Today</span>";
                                } elseif ($days == 1) {
                                    echo "<span class='text-info'>Tomorrow</span>";
                                } else {
                                    echo "<span>{$days} days</span>";
                                }
                                ?>
                            </td>
                            <td><?php echo $res['total_guests'] ?? 0; ?></td>
                            <td>
                                <?php if (!empty($res['otp_code'])): ?>
                                    <span class="otp-badge <?php echo $res['pass_status'] == 'active' ? 'pass-active' : ''; ?>">
                                        <?php echo $res['otp_code']; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo getStatusBadgeClass($res['status'] ?? ''); ?>">
                                    <i class="fas <?php echo getStatusIcon($res['status'] ?? ''); ?>"></i>
                                    <?php echo ucfirst($res['status'] ?? 'pending'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    

<!-- Cancel Modal -->
<div id="cancelModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-times-circle" style="color: #dc3545;"></i> Cancel Reservation</h3>
        <form method="POST" id="cancelForm">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="reservation_id" id="cancel_reservation_id">
            
            <div class="form-group">
                <label for="cancel_reason">Reason for cancellation</label>
                <textarea name="cancel_reason" id="cancel_reason" class="form-control" rows="4" placeholder="Please provide reason for cancellation..." required></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="submit" class="btn-action btn-cancel" style="flex: 1;">
                    <i class="fas fa-check"></i> Confirm Cancellation
                </button>
                <button type="button" class="btn-action btn-view" style="flex: 1;" onclick="hideCancelModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Extend Stay Modal -->
<div id="extendModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-calendar-plus" style="color: #17a2b8;"></i> Extend Stay</h3>
        <form method="POST" id="extendForm" onsubmit="return validateExtendForm()">
            <input type="hidden" name="action" value="extend_stay">
            <input type="hidden" name="reservation_id" id="extend_reservation_id">
            
            <div class="form-group">
                <label for="additional_days">Additional Days</label>
                <input type="number" name="additional_days" id="additional_days" class="form-control" min="1" max="30" value="1" required onchange="calculateExtensionCost()">
            </div>
            
            <div class="form-group">
                <label for="extend_payment_method">Payment Method</label>
                <select name="payment_method" id="extend_payment_method" class="form-control" required>
                    <option value="">-- Select Payment Method --</option>
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                </select>
            </div>
            
            <div class="price-display" id="priceDisplay">
                <div class="label">Additional Cost</div>
                <div class="amount" id="extension_cost">₱0.00</div>
            </div>
            
            <div class="modal-actions">
                <button type="submit" class="btn-action btn-extend" style="flex: 1;">
                    <i class="fas fa-check"></i> Process Extension
                </button>
                <button type="button" class="btn-action btn-view" style="flex: 1;" onclick="hideExtendModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('show');
    }
    
    // Cancel Modal Functions
    function showCancelModal(reservationId) {
        document.getElementById('cancel_reservation_id').value = reservationId;
        document.getElementById('cancelModal').style.display = 'flex';
    }
    
    function hideCancelModal() {
        document.getElementById('cancelModal').style.display = 'none';
        document.getElementById('cancel_reason').value = '';
    }
    
    // Extend Modal Functions
    let roomPrice = 0;
    let cottagePrice = 0;
    
    function showExtendModal(reservationId, roomRate, cottageRate) {
        roomPrice = roomRate || 0;
        cottagePrice = cottageRate || 0;
        
        document.getElementById('extend_reservation_id').value = reservationId;
        document.getElementById('additional_days').value = 1;
        document.getElementById('extend_payment_method').value = '';
        document.getElementById('extendModal').style.display = 'flex';
        
        calculateExtensionCost();
    }
    
    function hideExtendModal() {
        document.getElementById('extendModal').style.display = 'none';
    }
    
    function calculateExtensionCost() {
        const days = parseInt(document.getElementById('additional_days').value) || 0;
        const dailyRate = roomPrice + cottagePrice;
        const totalCost = days * dailyRate;
        
        document.getElementById('extension_cost').innerHTML = '₱' + totalCost.toFixed(2);
    }
    
    function validateExtendForm() {
        const days = parseInt(document.getElementById('additional_days').value);
        const paymentMethod = document.getElementById('extend_payment_method').value;
        
        if (!days || days < 1) {
            alert('Please enter valid number of days');
            return false;
        }
        
        if (!paymentMethod) {
            alert('Please select payment method');
            return false;
        }
        
        const totalCost = days * (roomPrice + cottagePrice);
        return confirm(`Process extension for ${days} day(s) with additional payment of ₱${totalCost.toFixed(2)}?`);
    }
    // Confirm checkout with guest name
function confirmCheckout(guestName) {
    return confirm(`Check out ${guestName}? This will mark their stay as completed.`);
}

// Show guest details in a modal or alert
function showGuestDetails(guest) {
    // You can customize this to show more details in a modal
    // For now, we'll show an alert with basic info
    let details = `Guest: ${guest.guest_name || 'N/A'}\n`;
    details += `Phone: ${guest.phone || 'N/A'}\n`;
    details += `Check-in: ${guest.check_in_date || 'N/A'}\n`;
    details += `Check-out: ${guest.check_out_date || 'N/A'}\n`;
    details += `Room: ${guest.room_number || guest.cottage_name || 'Day Tour'}\n`;
    details += `Nights stayed: ${guest.nights_stayed || 0}\n`;
    details += `Nights remaining: ${guest.nights_remaining || 0}\n`;
    details += `Total guests: ${guest.total_guests || 0}\n`;
    if (guest.otp_code) {
        details += `OTP: ${guest.otp_code}\n`;
    }
    
    alert(details);
}

// You might also want to add a direct checkout function
function quickCheckout(reservationId, guestName) {
    if (confirm(`Quick checkout ${guestName}?`)) {
        // Create a form dynamically and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.name = 'action';
        actionInput.value = 'checkout';
        
        const idInput = document.createElement('input');
        idInput.name = 'reservation_id';
        idInput.value = reservationId;
        
        form.appendChild(actionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
    }
}
    
    // Format OTP input - only numbers
    document.querySelector('.otp-search-input')?.addEventListener('input', function(e) {
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
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const cancelModal = document.getElementById('cancelModal');
        const extendModal = document.getElementById('extendModal');
        
        if (event.target == cancelModal) {
            cancelModal.style.display = 'none';
        }
        if (event.target == extendModal) {
            extendModal.style.display = 'none';
        }
    }
    
    // Auto-refresh page every 5 minutes (300000 ms)
    setTimeout(function() {
        location.reload();
    }, 300000);
</script>
</body>
</html>