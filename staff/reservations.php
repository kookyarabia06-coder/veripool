<?php
/**
 * Veripool Reservation System - Staff Reservations Page
 * View all reservations (Read-only for staff) with balance information and date adjustments
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

// Get current user
$user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Initialize EntryPassManager
$entryPassManager = new EntryPassManager($db);

// Handle date adjustment actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Approve date adjustment
    if ($_POST['action'] === 'approve_adjustment' && isset($_POST['request_id'])) {
        $request_id = (int)$_POST['request_id'];
        
        $result = $entryPassManager->approveDateAdjustment($request_id, $user['id']);
        
        if ($result['success']) {
            $message = "Date adjustment approved successfully. OTP remains: " . $result['new_otp'];
            $message_type = 'success';
        } else {
            $message = "Failed to approve adjustment: " . $result['message'];
            $message_type = 'error';
        }
    }
    
    // Reject date adjustment
    if ($_POST['action'] === 'reject_adjustment' && isset($_POST['request_id'])) {
        $request_id = (int)$_POST['request_id'];
        $rejection_reason = isset($_POST['rejection_reason']) ? sanitize($_POST['rejection_reason']) : '';
        
        // Get request details for email
        $request = $db->getRow("
            SELECT dar.*, u.email, u.full_name
            FROM date_adjustment_requests dar
            JOIN users u ON dar.user_id = u.id
            WHERE dar.id = ?
        ", [$request_id]);
        
        if ($request) {
            // Update request status
            $db->update('date_adjustment_requests', 
                [
                    'status' => 'rejected',
                    'reviewed_by' => $user['id'],
                    'reviewed_at' => date('Y-m-d H:i:s'),
                    'notes' => $rejection_reason
                ],
                'id = :id',
                ['id' => $request_id]
            );
            
            // Send rejection email
            $subject = "Date Adjustment Request Rejected";
            $html_message = '
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { padding: 30px; background: #f9f9f9; }
                    .reason-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>Date Adjustment Request Rejected</h2>
                    </div>
                    <div class="content">
                        <p>Dear ' . htmlspecialchars($request['full_name']) . ',</p>
                        <p>Your request to adjust your reservation dates has been rejected.</p>
                        
                        <div class="reason-box">
                            <strong>Reason:</strong><br>
                            ' . htmlspecialchars($rejection_reason) . '
                        </div>
                        
                        <p>Please contact the resort directly if you need assistance.</p>
                        <p>Thank you for understanding.</p>
                    </div>
                </div>
            </body>
            </html>';
            
            $entryPassManager->sendEmailPublic($request['email'], $subject, $html_message);
            
            $message = "Date adjustment rejected successfully.";
            $message_type = 'success';
        }
    }
    
    // Check-in with flexible date
    if ($_POST['action'] === 'flexible_checkin' && isset($_POST['otp_code'])) {
        $otp = $_POST['otp_code'];
        
        $result = $entryPassManager->staffCheckInWithFlexibility($otp, $user['id']);
        
        if ($result['success']) {
            $message = "Check-in successful! Guest: " . $result['guest_name'] . " (Type: " . $result['check_in_type'] . ")";
            $message_type = 'success';
        } else if (isset($result['requires_action']) && $result['requires_action']) {
            // Store in session for modal display
            $_SESSION['pending_action'] = $result;
            $message = "Action required: " . $result['message'];
            $message_type = 'warning';
        } else {
            $message = "Check-in failed: " . $result['message'];
            $message_type = 'error';
        }
    }
}

// Get filter from URL
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Build query based on filter
$where = "";
switch($filter) {
    case 'pending':
        $where = "WHERE r.status = 'pending'";
        break;
    case 'confirmed':
        $where = "WHERE r.status = 'confirmed'";
        break;
    case 'checked_in':
        $where = "WHERE r.status = 'checked_in'";
        break;
    case 'checked_out':
        $where = "WHERE r.status = 'checked_out'";
        break;
    case 'cancelled':
        $where = "WHERE r.status = 'cancelled'";
        break;
    default:
        $where = "";
}

// Get all reservations with OTP information
$reservations = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone, u.email, 
           rm.room_number, rt.name as room_type,
           ep.otp_code, ep.status as pass_status, ep.valid_from, ep.valid_until,
           ep.date_adjustments, ep.last_adjustment_date,
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id AND payment_status = 'completed') as amount_paid,
           (r.total_amount - (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id AND payment_status = 'completed')) as remaining_balance
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    $where
    ORDER BY r.created_at DESC
");

// Get pending date adjustment requests
$pending_adjustments = $db->getRows("
    SELECT dar.*, u.full_name, u.email, u.phone,
           r.reservation_number, r.check_in_date as original_check_in, r.check_out_date as original_check_out,
           ep.otp_code, ep.valid_from, ep.valid_until
    FROM date_adjustment_requests dar
    JOIN users u ON dar.user_id = u.id
    JOIN reservations r ON dar.reservation_id = r.id
    JOIN entry_passes ep ON dar.entry_pass_id = ep.id
    WHERE dar.status = 'pending'
    ORDER BY dar.created_at ASC
");

// Get statistics
$total_pending = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'pending'") ?: 0;
$total_confirmed = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'confirmed'") ?: 0;
$total_checked_in = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'checked_in'") ?: 0;
$total_checked_out = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'checked_out'") ?: 0;
$total_cancelled = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'cancelled'") ?: 0;
$total_all = $total_pending + $total_confirmed + $total_checked_in + $total_checked_out + $total_cancelled;
$pending_adjustments_count = count($pending_adjustments);

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    switch($status) {
        case 'pending':
            return 'status-pending';
        case 'confirmed':
            return 'status-confirmed';
        case 'checked_in':
            return 'status-checked_in';
        case 'checked_out':
            return 'status-checked_out';
        case 'cancelled':
            return 'status-cancelled';
        default:
            return 'status-pending';
    }
}

// Helper function to format status for display
function formatStatus($status) {
    return ucfirst(str_replace('_', ' ', $status));
}

// Helper function to get OTP badge class
function getOtpBadgeClass($status) {
    if ($status == 'active') {
        return 'otp-active';
    } elseif ($status == 'used') {
        return 'otp-used';
    } else {
        return '';
    }
}

// Helper function to get balance class
function getBalanceClass($balance) {
    if ($balance <= 0) {
        return 'balance-zero';
    } else {
        return 'balance-positive';
    }
}

// Helper function to check if date is within valid range
function isDateValid($date, $valid_from, $valid_until) {
    $check_date = strtotime($date);
    $from = strtotime($valid_from);
    $until = strtotime($valid_until);
    
    return ($check_date >= $from && $check_date <= $until);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservations - Staff Portal</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/staff.css">
    <link rel="stylesheet" href="/assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
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
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
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
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .alert i {
            font-size: 1.2rem;
        }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s;
            background: white;
            color: #102C57;
            border: 1px solid #dee2e6;
            position: relative;
        }
        
        .filter-btn:hover {
            background: #f0f0f0;
            transform: translateY(-2px);
        }
        
        .filter-btn.active {
            background: #1679AB;
            color: white;
            border-color: #1679AB;
        }
        
        .filter-btn i {
            margin-right: 5px;
        }
        
        .badge-notification {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.6rem;
            font-weight: bold;
        }
        
        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            text-align: center;
            border-left: 3px solid #1679AB;
        }
        
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #102C57;
            line-height: 1.2;
        }
        
        .stat-card .stat-label {
            font-size: 0.8rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Card */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 25px;
        }
        
        .card-header {
            padding: 15px 20px;
            background: #102C57;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            color: #FFCBCB;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        
        .card-header h3 i {
            color: #FFB1B1;
        }
        
        .card-header .badge {
            background: #FFB1B1;
            color: #102C57;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Table */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        
        th {
            text-align: left;
            padding: 12px 8px;
            background: #f8f9fa;
            color: #102C57;
            font-weight: 600;
            border-bottom: 2px solid #FFCBCB;
            white-space: nowrap;
        }
        
        td {
            padding: 12px 8px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        tr:hover td {
            background: #f8f9fa;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d4edda; color: #155724; }
        .status-checked_in { background: #cce5ff; color: #004085; }
        .status-checked_out { background: #e2e3e5; color: #383d41; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        /* Balance Display */
        .balance-positive {
            color: #dc3545;
            font-weight: bold;
        }
        
        .balance-zero {
            color: #28a745;
            font-weight: bold;
        }
        
        /* OTP Badge */
        .otp-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-family: monospace;
            font-size: 0.8rem;
            font-weight: bold;
            background: #102C57;
            color: #FFCBCB;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .otp-badge:hover {
            transform: scale(1.05);
        }
        
        .otp-active {
            background: #d4edda;
            color: #155724;
            border: 1px solid #28a745;
        }
        
        .otp-used {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #dc3545;
        }
        
        .otp-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
        }
        
        /* Date Warning */
        .date-warning {
            background: #fff3cd;
            color: #856404;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 0.7rem;
            margin-top: 3px;
            display: inline-block;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #666;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #FFCBCB;
            margin-bottom: 15px;
        }
        
        .empty-state p {
            font-size: 1rem;
            margin-bottom: 5px;
        }
        
        .empty-state small {
            color: #999;
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
        }
        
        /* Guest Info */
        .guest-info {
            font-weight: 600;
            color: #102C57;
        }
        
        .guest-phone {
            font-size: 0.7rem;
            color: #666;
            margin-top: 2px;
        }
        
        /* Room Info */
        .room-info {
            font-weight: 500;
        }
        
        .room-type {
            font-size: 0.7rem;
            color: #1679AB;
            margin-top: 2px;
        }
        
        /* Amount */
        .amount {
            font-weight: 600;
            color: #102C57;
        }
        
        /* Amount Paid */
        .amount-paid {
            font-weight: 600;
            color: #28a745;
        }
        
        /* Adjustment Card */
        .adjustment-card {
            background: #f8f9fa;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .adjustment-info {
            flex: 1;
        }
        
        .adjustment-info h4 {
            color: #102C57;
            margin-bottom: 5px;
        }
        
        .adjustment-dates {
            display: flex;
            gap: 20px;
            margin: 10px 0;
            flex-wrap: wrap;
        }
        
        .date-box {
            background: white;
            padding: 8px 12px;
            border-radius: 5px;
        }
        
        .date-box.original {
            border-left: 3px solid #dc3545;
        }
        
        .date-box.requested {
            border-left: 3px solid #28a745;
        }
        
        .date-label {
            font-size: 0.7rem;
            color: #666;
        }
        
        .date-value {
            font-weight: bold;
            color: #102C57;
        }
        
        .adjustment-reason {
            background: white;
            padding: 8px;
            border-radius: 5px;
            font-style: italic;
            color: #666;
        }
        
        .adjustment-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-approve {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-approve:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .btn-reject {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-reject:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        /* Check-in Bar */
        .checkin-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .checkin-bar h3 {
            color: #102C57;
            font-size: 1rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkin-input {
            flex: 1;
            min-width: 200px;
            display: flex;
            gap: 10px;
        }
        
        .checkin-input input {
            flex: 1;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-family: monospace;
            font-size: 1rem;
        }
        
        .checkin-input input:focus {
            border-color: #1679AB;
            outline: none;
        }
        
        .btn-checkin {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .btn-checkin:hover {
            background: #218838;
            transform: translateY(-2px);
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
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .stats-row {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-tabs {
                justify-content: center;
            }
            
            .adjustment-card {
                flex-direction: column;
                align-items: stretch;
            }
            
            .adjustment-actions {
                justify-content: flex-end;
            }
        }
        
        @media (max-width: 480px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .adjustment-dates {
                flex-direction: column;
                gap: 10px;
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
            <i class="fas fa-calendar-check"></i>
            Reservations
        </h1>
        <div class="date">
            <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
   
    
    
    <!-- Reservations Table -->
    <div class="card">
        <div class="card-header">
            <h3>
                <i class="fas fa-list"></i> 
                <?php 
                if ($filter == 'all') echo 'All Reservations';
                elseif ($filter == 'pending') echo 'Pending Reservations';
                elseif ($filter == 'confirmed') echo 'Confirmed Reservations';
                elseif ($filter == 'checked_in') echo 'Checked In Guests';
                elseif ($filter == 'checked_out') echo 'Checked Out Guests';
                elseif ($filter == 'cancelled') echo 'Cancelled Reservations';
                ?>
            </h3>
            <span class="badge"><?php echo count($reservations); ?> record(s)</span>
        </div>
        <div class="card-body">
            <?php if (empty($reservations)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <p>No reservations found</p>
                    <small>Try selecting a different filter</small>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Reservation #</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Guests</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>OTP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $res): 
                                $balance = $res['remaining_balance'] ?? 0;
                                $balance_class = getBalanceClass($balance);
                                $today = date('Y-m-d');
                                
                                // Check if current date is within valid range
                                $is_valid_date = true;
                                $date_status = '';
                                if (!empty($res['valid_from']) && !empty($res['valid_until'])) {
                                    $is_valid_date = isDateValid($today, $res['valid_from'], $res['valid_until']);
                                    if (!$is_valid_date && $res['status'] == 'confirmed') {
                                        $date_status = 'date-warning';
                                    }
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($res['reservation_number'] ?? 'N/A'); ?></strong>
                                </td>
                                <td>
                                    <div class="guest-info"><?php echo htmlspecialchars($res['guest_name'] ?? 'N/A'); ?></div>
                                    <div class="guest-phone"><?php echo $res['phone'] ?? ''; ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($res['room_number'])): ?>
                                        <div class="room-info">Room <?php echo $res['room_number']; ?></div>
                                        <div class="room-type"><?php echo $res['room_type'] ?? ''; ?></div>
                                    <?php else: ?>
                                        <span style="color: #999;">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo !empty($res['check_in_date']) ? date('M d, Y', strtotime($res['check_in_date'])) : 'N/A'; ?>
                                    <?php if ($date_status): ?>
                                        <div class="date-warning">
                                            <i class="fas fa-exclamation-triangle"></i> Date mismatch
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($res['check_out_date']) ? date('M d, Y', strtotime($res['check_out_date'])) : 'N/A'; ?></td>
                                <td><?php echo ($res['adults'] ?? 0) + ($res['children'] ?? 0); ?></td>
                                <td class="amount">₱<?php echo number_format($res['total_amount'] ?? 0, 2); ?></td>
                                <td class="amount-paid">₱<?php echo number_format($res['amount_paid'] ?? 0, 2); ?></td>
                                <td class="<?php echo $balance_class; ?>">
                                    ₱<?php echo number_format($balance, 2); ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo getStatusBadgeClass($res['status'] ?? ''); ?>">
                                        <?php echo formatStatus($res['status'] ?? 'pending'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($res['otp_code'])): ?>
                                        <span class="otp-badge <?php 
                                            echo getOtpBadgeClass($res['pass_status'] ?? '');
                                            echo !$is_valid_date && $res['status'] == 'confirmed' ? ' otp-warning' : '';
                                        ?>" onclick="copyOtp('<?php echo $res['otp_code']; ?>')" title="Click to copy">
                                            <?php echo $res['otp_code']; ?>
                                        </span>
                                        <?php if (!empty($res['pass_status'])): ?>
                                            <br><small style="font-size: 0.6rem; color: #666;"><?php echo ucfirst($res['pass_status']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($res['date_adjustments'] > 0): ?>
                                            <br><small style="font-size: 0.6rem; color: #28a745;">
                                                <i class="fas fa-sync-alt"></i> Adjusted (<?php echo $res['date_adjustments']; ?>x)
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
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

<!-- Reject Modal -->
<div class="modal" id="rejectModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Reject Date Adjustment</h3>
            <button class="modal-close" onclick="closeRejectModal()">&times;</button>
        </div>
        <form method="POST" id="rejectForm">
            <input type="hidden" name="action" value="reject_adjustment">
            <input type="hidden" name="request_id" id="reject_request_id">
            
            <div class="modal-body">
                <p>Please provide a reason for rejecting this date adjustment request:</p>
                <textarea name="rejection_reason" rows="4" style="width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 5px;" required></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn-reject">
                    <i class="fas fa-times"></i> Reject Request
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('show');
    }
    
    function copyOtp(otp) {
        navigator.clipboard.writeText(otp).then(function() {
            alert('OTP copied to clipboard: ' + otp);
        }, function() {
            alert('Failed to copy OTP');
        });
    }
    
    function validateOtp() {
        const otp = document.getElementById('otp_input').value;
        if (!/^\d{6}$/.test(otp)) {
            alert('Please enter a valid 6-digit OTP');
            return false;
        }
        return true;
    }
    
    function showRejectModal(requestId) {
        document.getElementById('reject_request_id').value = requestId;
        document.getElementById('rejectModal').classList.add('active');
    }
    
    function closeRejectModal() {
        document.getElementById('rejectModal').classList.remove('active');
        document.getElementById('reject_request_id').value = '';
        document.querySelector('[name="rejection_reason"]').value = '';
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(el => {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity = '0';
            setTimeout(() => el.style.display = 'none', 500);
        });
    }, 5000);
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('rejectModal');
        if (event.target == modal) {
            closeRejectModal();
        }
    }
</script>

<!-- Include flatpickr for date picking if needed -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</body>
</html>