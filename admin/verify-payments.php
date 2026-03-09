<?php
/**
 * Veripool Reservation System - Admin Verify Payments Page
 * View and verify pending payment screenshots with OTP confirmation
 * Matches the style of reservations.php
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
require_once BASE_PATH . '/includes/EntryPassManager.php'; // ADD THIS LINE

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Initialize Entry Pass Manager
$entryPassManager = new EntryPassManager($db);

// Get current user
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Build query for payments
$where = "WHERE 1=1";
$params = [];

if ($status_filter != 'all') {
    $where .= " AND p.payment_status = :status";
    $params['status'] = $status_filter;
}

if (isset($_GET['date_from']) && isset($_GET['date_to'])) {
    $where .= " AND DATE(p.payment_date) BETWEEN :date_from AND :date_to";
    $params['date_from'] = $date_from;
    $params['date_to'] = $date_to;
}

// Get all payments with screenshot info and calculate balance
$payments = $db->getRows("
    SELECT p.*, 
           r.reservation_number, 
           r.status as reservation_status,
           r.total_amount as reservation_total,
           r.amount_paid as reservation_paid,
           r.otp_code as reservation_otp,
           u.full_name as guest_name,
           u.email as guest_email,
           u.phone as guest_phone,
           rm.room_number,
           rt.name as room_type,
           (r.total_amount - r.amount_paid) as remaining_balance,
           p.screenshot as screenshot_filename
    FROM payments p
    JOIN reservations r ON p.reservation_id = r.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    $where
    ORDER BY 
        CASE 
            WHEN p.payment_status = 'pending' THEN 1
            WHEN p.payment_status = 'completed' THEN 2
            WHEN p.payment_status = 'failed' THEN 3
            ELSE 4
        END,
        p.payment_date DESC
", $params);

// Get statistics
$total_payments = $db->getValue("SELECT COUNT(*) FROM payments") ?: 0;
$pending_count = $db->getValue("SELECT COUNT(*) FROM payments WHERE payment_status = 'pending'") ?: 0;
$completed_count = $db->getValue("SELECT COUNT(*) FROM payments WHERE payment_status = 'completed'") ?: 0;
$failed_count = $db->getValue("SELECT COUNT(*) FROM payments WHERE payment_status = 'failed'") ?: 0;
$total_amount = $db->getValue("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'completed'") ?: 0;
$pending_amount = $db->getValue("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'pending'") ?: 0;

// Get reservation with most pending payments for quick link
$reservation_with_pending = $db->getRow("
    SELECT r.id, r.reservation_number, COUNT(p.id) as pending_count
    FROM reservations r
    JOIN payments p ON r.id = p.reservation_id
    WHERE p.payment_status = 'pending'
    GROUP BY r.id
    ORDER BY pending_count DESC
    LIMIT 1
");

// REMOVED: sendOTPEmail function - now using EntryPassManager

// Handle actions
$message = '';
$message_type = '';
$email_status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Verify payment
        if ($_POST['action'] === 'verify_payment' && isset($_POST['payment_id'])) {
            $payment_id = (int)$_POST['payment_id'];
            $reservation_id = (int)$_POST['reservation_id'];
            $verification_action = sanitize($_POST['verification_action']);
            
            // Get payment details
            $payment = $db->getRow("SELECT * FROM payments WHERE id = ?", [$payment_id]);
            
            if ($payment) {
                // Check if payment method is valid (only Cash and GCash are allowed)
                $allowed_methods = ['cash', 'gcash'];
                if (!in_array($payment['payment_method'], $allowed_methods)) {
                    $message = "Invalid payment method. Only Cash and GCash are accepted.";
                    $message_type = 'error';
                } else {
                    if ($verification_action === 'approve' || $verification_action === 'approve_with_otp') {
                        // Approve payment
                        $db->update('payments', 
                            ['payment_status' => 'completed'], 
                            'id = :id', 
                            ['id' => $payment_id]
                        );
                        
                        // Update reservation amount_paid
                        $res = $db->getRow("SELECT amount_paid, total_amount, otp_code FROM reservations WHERE id = ?", [$reservation_id]);
                        $new_amount_paid = ($res['amount_paid'] ?? 0) + $payment['amount'];
                        $remaining_balance = $res['total_amount'] - $new_amount_paid;
                        
                        // Generate OTP if needed
                        $otp_code = $res['otp_code'] ?? '';
                        if ($verification_action === 'approve_with_otp' || empty($otp_code)) {
                            $otp_code = generateOTP();
                        }
                        
                        // Determine status based on remaining balance
                        $new_status = 'pending';
                        if ($remaining_balance <= 0) {
                            $new_status = 'confirmed';
                        } elseif ($new_amount_paid > 0) {
                            $new_status = 'partial';
                        }
                        
                        $db->update('reservations', 
                            [
                                'amount_paid' => $new_amount_paid,
                                'status' => $new_status,
                                'otp_code' => $otp_code
                            ], 
                            'id = :id', 
                            ['id' => $reservation_id]
                        );
                        
                        // FIXED: Use EntryPassManager to send OTP email if reservation is fully paid
                        if ($remaining_balance <= 0 && !empty($otp_code)) {
                            // Get reservation details for email
                            $reservation_details = $db->getRow("
                                SELECT r.*, u.email, u.full_name 
                                FROM reservations r
                                JOIN users u ON r.user_id = u.id
                                WHERE r.id = ?
                            ", [$reservation_id]);
                            
                            if ($reservation_details) {
                                // Prepare reservation data for EntryPassManager
                                $reservation_data = [
                                    'id' => $reservation_id,
                                    'full_name' => $reservation_details['full_name'],
                                    'email' => $reservation_details['email'],
                                    'check_in_date' => $reservation_details['check_in_date'],
                                    'check_out_date' => $reservation_details['check_out_date'],
                                    'facility_name' => $payment['room_number'] ?? 'Room',
                                    'facility_type' => 'room'
                                ];
                                
                                // Use EntryPassManager to send email
                                $email_sent = $entryPassManager->sendEntryPassEmail(
                                    $reservation_data,
                                    $otp_code,
                                    0 // entry_pass_id (not needed for email only)
                                );
                                
                                $email_status = $email_sent ? " OTP sent to " . $reservation_details['email'] : " Failed to send email.";
                            }
                        }
                        
                        $balance_text = $remaining_balance > 0 ? " Remaining balance: ₱" . number_format($remaining_balance, 2) : "";
                        $message = "Payment approved successfully. " . 
                                   ($remaining_balance <= 0 ? "Reservation confirmed. " : "Partial payment recorded. ") .
                                   "OTP: " . $otp_code . $balance_text . $email_status;
                        
                    } elseif ($verification_action === 'reject') {
                        // Reject payment
                        $rejection_reason = sanitize($_POST['rejection_reason'] ?? 'Payment verification failed');
                        
                        // Get current notes
                        $current_notes = $payment['notes'] ?? '';
                        $new_notes = $current_notes ? $current_notes . ' | ' : '';
                        $new_notes .= 'Rejected: ' . $rejection_reason . ' on ' . date('Y-m-d H:i:s');
                        
                        $db->update('payments', 
                            [
                                'payment_status' => 'failed',
                                'notes' => $new_notes
                            ], 
                            'id = :id', 
                            ['id' => $payment_id]
                        );
                        
                        $message = "Payment rejected: " . $rejection_reason;
                    }
                    
                    $message_type = 'success';
                    
                    // Log action
                    logAudit($_SESSION['user_id'], 'VERIFY_PAYMENT', 'payments', $payment_id, ['action' => $verification_action]);
                    
                    // Refresh data
                    $payments = $db->getRows("
                        SELECT p.*, 
                               r.reservation_number, 
                               r.status as reservation_status,
                               r.total_amount as reservation_total,
                               r.amount_paid as reservation_paid,
                               r.otp_code as reservation_otp,
                               u.full_name as guest_name,
                               u.email as guest_email,
                               u.phone as guest_phone,
                               rm.room_number,
                               rt.name as room_type,
                               (r.total_amount - r.amount_paid) as remaining_balance,
                               p.screenshot as screenshot_filename
                        FROM payments p
                        JOIN reservations r ON p.reservation_id = r.id
                        JOIN users u ON r.user_id = u.id
                        LEFT JOIN rooms rm ON r.room_id = rm.id
                        LEFT JOIN room_types rt ON rm.room_type_id = rt.id
                        $where
                        ORDER BY p.payment_date DESC
                    ", $params);
                }
            }
        }
    }
}

/**
 * FIXED: Extract screenshot filename from payment record
 * Now only checks the screenshot column in the database
 */
function extractScreenshotFilename($payment) {
    // Only check the screenshot column
    if (!empty($payment['screenshot_filename'])) {
        // Verify the file exists
        $file_path = BASE_PATH . '/uploads/payments/' . $payment['screenshot_filename'];
        if (file_exists($file_path)) {
            return $payment['screenshot_filename'];
        }
    }
    
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Payments - Admin Dashboard</title>
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
        /* Stats Grid - Matching reservations.php */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border-left: 3px solid #1679AB;
        }
        
        .stat-card .number {
            font-size: 1.3rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .stat-card .label {
            font-size: 0.75rem;
            color: #666;
        }
        
        .stat-card .detail {
            font-size: 0.7rem;
            color: #1679AB;
            margin-top: 3px;
        }
        
        /* Tab Navigation - Matching reservations.php */
        .tab-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #FFCBCB;
            padding-bottom: 10px;
        }
        
        .tab-btn {
            padding: 8px 20px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            color: #102C57;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .tab-btn:hover {
            background: #FFCBCB;
        }
        
        .tab-btn.active {
            background: #1679AB;
            color: white;
            border-color: #1679AB;
        }
        
        .tab-btn i {
            font-size: 0.9rem;
        }
        
        /* Mini Stats - Matching reservations.php */
        .payment-stats-mini {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-mini {
            background: white;
            padding: 8px 15px;
            border-radius: 20px;
            border: 1px solid #e0e0e0;
            font-size: 0.85rem;
            color: #102C57;
        }
        
        .stat-mini strong {
            color: #1679AB;
            margin-right: 3px;
        }
        
        .stat-mini.warning {
            background: #fff3cd;
            border-color: #ffc107;
        }
        
        .stat-mini.warning strong {
            color: #856404;
        }
        
        /* Quick Actions - Matching reservations.php */
        .quick-actions {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .quick-action {
            background: white;
            padding: 6px 12px;
            border-radius: 20px;
            text-align: center;
            text-decoration: none;
            color: #102C57;
            border: 1px solid #e0e0e0;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .quick-action:hover {
            border-color: #1679AB;
            background: #f0f8ff;
        }
        
        .quick-action i {
            color: #1679AB;
        }
        
        .quick-action.primary {
            background: #1679AB;
            border-color: #1679AB;
            color: white;
        }
        
        .quick-action.primary i {
            color: white;
        }
        
        .quick-action.warning {
            background: #ffc107;
            border-color: #ffc107;
            color: #102C57;
        }
        
        .quick-action.warning i {
            color: #102C57;
        }
        
        /* Filter Section - Matching reservations.php */
        .filter-section {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 120px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 4px;
            color: #102C57;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .filter-input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        
        /* Cards - Matching reservations.php */
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            padding: 10px 15px;
            background: #102C57;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            color: #FFCBCB;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .card-header .badge {
            background: #FFB1B1;
            color: #102C57;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
        }
        
        .card-body {
            padding: 15px;
        }
        
        /* Tables - Matching reservations.php */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }
        
        th {
            text-align: left;
            padding: 8px 5px;
            background: #f8f9fa;
            color: #102C57;
            font-weight: 600;
            border-bottom: 2px solid #FFCBCB;
            white-space: nowrap;
        }
        
        td {
            padding: 8px 5px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        tr:hover td {
            background: #f8f9fa;
        }
        
        /* Status Badges - Matching reservations.php */
        .status-badge {
            display: inline-block;
            padding: 3px 6px;
            border-radius: 10px;
            font-size: 0.65rem;
            font-weight: 500;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-cancelled { background: #e2e3e5; color: #383d41; }
        .status-partial { background: #cce5ff; color: #004085; }
        
        /* Payment Badges - Matching reservations.php */
        .payment-badge {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 8px;
            font-size: 0.6rem;
            font-weight: 600;
        }
        
        .payment-badge.completed {
            background: #d4edda;
            color: #155724;
        }
        
        .payment-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .payment-badge.failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        .payment-badge.cancelled {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .payment-badge.partial {
            background: #cce5ff;
            color: #004085;
        }
        
        /* OTP Badge */
        .otp-badge {
            background: #102C57;
            color: #FFCBCB;
            padding: 3px 6px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: bold;
            letter-spacing: 1px;
            display: inline-block;
        }
        
        /* Screenshot Thumbnail - Matching reservations.php */
        .screenshot-thumb {
            width: 30px;
            height: 30px;
            background: #f0f0f0;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 1px solid #ddd;
        }
        
        .screenshot-thumb i {
            font-size: 1rem;
            color: #1679AB;
        }
        
        .screenshot-thumb:hover {
            background: #e0e0e0;
        }
        
        /* Action Buttons - Matching reservations.php */
        .action-buttons {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
        }
        
        .btn-icon {
            padding: 4px 6px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.65rem;
            transition: all 0.2s;
        }
        
        .btn-icon:hover {
            transform: translateY(-1px);
            filter: brightness(0.95);
        }
        
        .btn-view { background: #17a2b8; color: white; }
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #ffc107; color: #102C57; }
        .btn-otp { background: #102C57; color: #FFCBCB; }
        
        /* Balance Display */
        .balance-positive {
            color: #dc3545;
            font-weight: bold;
        }
        
        .balance-zero {
            color: #28a745;
            font-weight: bold;
        }
        
        /* OTP Display */
        .otp-display {
            background: #102C57;
            color: #FFCBCB;
            padding: 8px 15px;
            border-radius: 5px;
            font-size: 1.5rem;
            font-weight: bold;
            letter-spacing: 3px;
            text-align: center;
            margin: 10px 0;
        }
        
        /* Modal - Matching reservations.php */
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
            padding: 20px;
            border-radius: 10px;
            max-width: 700px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .modal-header h3 {
            color: #102C57;
            font-size: 1.1rem;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: #666;
        }
        
        .screenshot-image {
            width: 100%;
            max-height: 400px;
            object-fit: contain;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .payment-details {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 0.85rem;
        }
        
        .balance-info {
            background: #e8f4fd;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #1679AB;
        }
        
        .verification-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .btn-approve-large {
            background: #28a745;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            flex: 2;
            font-weight: bold;
        }
        
        .btn-approve-otp-large {
            background: #102C57;
            color: #FFCBCB;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            flex: 2;
            font-weight: bold;
        }
        
        .btn-reject-large {
            background: #ffc107;
            color: #102C57;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            flex: 1;
            font-weight: bold;
        }
        
        .btn-cancel-large {
            background: #6c757d;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .rejection-reason {
            margin-top: 10px;
            display: none;
        }
        
        .rejection-reason.show {
            display: block;
        }
        
        .form-group {
            margin-bottom: 10px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 3px;
            color: #102C57;
            font-weight: 500;
            font-size: 0.8rem;
        }
        
        .form-control {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        
        /* Alert - Matching reservations.php */
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
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        /* Email Status */
        .email-sent {
            background: #d4edda;
            color: #155724;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 0.6rem;
            margin-left: 5px;
        }
        
        .email-failed {
            background: #f8d7da;
            color: #721c24;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 0.6rem;
            margin-left: 5px;
        }
        
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .filter-section {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .verification-actions {
                flex-direction: column;
            }
            
            .quick-actions {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include BASE_PATH . '/includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar - Matching reservations.php -->
        <div class="top-bar" style="padding: 12px 20px; margin-bottom: 20px;">
            <h1 style="font-size: 1.4rem;">
                  <i class="fas fa-credit-card"></i> Verify Payments
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
        
        <!-- Tab Navigation - Matching reservations.php -->
        <div class="tab-nav">
            <a href="reservations.php" class="tab-btn">
                <i class="fas fa-calendar-check"></i> Reservations
            </a>
            <a href="verify-payments.php" class="tab-btn active">
                <i class="fas fa-credit-card"></i> Verify Payments
                <?php if ($pending_count > 0): ?>
                    <span style="background: #ffc107; color: #102C57; padding: 2px 6px; border-radius: 10px; font-size: 0.6rem; margin-left: 5px;"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <!-- Quick Stats -->
        <div class="payment-stats-mini">
            <span class="stat-mini"><strong><?php echo $total_payments; ?></strong> Total Payments</span>
            <span class="stat-mini warning"><strong><?php echo $pending_count; ?></strong> Pending</span>
            <span class="stat-mini"><strong><?php echo $completed_count; ?></strong> Completed</span>
            <span class="stat-mini"><strong><?php echo $failed_count; ?></strong> Failed</span>
            <span class="stat-mini"><strong>₱<?php echo number_format($total_amount, 2); ?></strong> Total Amount</span>
        </div>
        
        <!-- Filter Section - Matching reservations.php -->
        <div class="filter-section">
            <form method="GET" style="display: flex; gap: 8px; flex-wrap: wrap; width: 100%;">
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" class="filter-input">
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="failed" <?php echo $status_filter == 'failed' ? 'selected' : ''; ?>>Failed</option>
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group" style="display: flex; gap: 5px;">
                    <button type="submit" class="btn btn-primary" style="padding: 5px 10px; font-size: 0.75rem;">Apply</button>
                    <a href="verify-payments.php" class="btn btn-outline" style="padding: 5px 10px; font-size: 0.75rem;">Reset</a>
                </div>
            </form>
        </div>
        
        <!-- Payments Table - Matching reservations.php style -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-credit-card"></i> Payment Verifications</h3>
                <span class="badge"><?php echo count($payments); ?> payments</span>
            </div>
            <div class="card-body">
                <?php if (empty($payments)): ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-credit-card" style="font-size: 2rem; color: #FFCBCB; margin-bottom: 10px;"></i>
                        <p>No payments found matching your criteria.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Payment #</th>
                                    <th>Reservation</th>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): 
                                    // FIXED: Use the simplified extractScreenshotFilename function
                                    $screenshot = extractScreenshotFilename($payment);
                                    $balance_class = ($payment['remaining_balance'] <= 0) ? 'balance-zero' : 'balance-positive';
                                ?>
                                <tr>
                                    <td><?php echo date('m/d', strtotime($payment['payment_date'])); ?></td>
                                    <td><strong><?php echo substr($payment['payment_number'], -8); ?></strong></td>
                                    <td>
                                        <?php echo $payment['reservation_number']; ?>
                                        <br><small style="color: #666;"><?php echo ucfirst($payment['reservation_status']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($payment['guest_name']); ?>
                                        <br><small style="color: #666;"><?php echo $payment['guest_phone']; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($payment['room_number']): ?>
                                            Rm <?php echo $payment['room_number']; ?>
                                            <br><small><?php echo $payment['room_type']; ?></small>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><strong>₱<?php echo number_format($payment['amount'], 0); ?></strong></td>
                                    <td>
                                        <?php 
                                        $method = $payment['payment_method'];
                                        if ($method == 'gcash') {
                                            echo '<span style="color: #004085;"><i class="fab fa-google-pay"></i> GCash</span>';
                                        } elseif ($method == 'cash') {
                                            echo '<span style="color: #28a745;"><i class="fas fa-money-bill"></i> Cash</span>';
                                        } else {
                                            echo ucfirst($method);
                                        }
                                        ?>
                                    </td>
                                    <td>₱<?php echo number_format($payment['reservation_total'], 0); ?></td>
                                    <td>₱<?php echo number_format($payment['reservation_paid'], 0); ?></td>
                                    <td class="<?php echo $balance_class; ?>">
                                        ₱<?php echo number_format($payment['remaining_balance'], 0); ?>
                                    </td>
                                    <td>
                                        <span class="payment-badge <?php echo $payment['payment_status']; ?>">
                                            <?php echo ucfirst($payment['payment_status']); ?>
                                        </span>
                                    </td>

                                   
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($payment['payment_status'] == 'pending' && $screenshot): ?>
                                                <?php if (in_array($payment['payment_method'], ['gcash', 'cash'])): ?>
                                                    <button class="btn-icon btn-view" onclick="viewScreenshot(<?php echo $payment['id']; ?>, <?php echo $payment['reservation_id']; ?>, '<?php echo $screenshot; ?>', '<?php echo $payment['payment_number']; ?>', <?php echo $payment['amount']; ?>, '<?php echo $payment['payment_method']; ?>', <?php echo $payment['reservation_otp'] ? "'" . $payment['reservation_otp'] . "'" : 'null'; ?>, <?php echo $payment['remaining_balance']; ?>, <?php echo $payment['reservation_total']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                <?php else: ?>
                                                    <span style="color: #999;" title="Only Cash and GCash are accepted">Invalid method</span>
                                                <?php endif; ?>
                                            <?php elseif ($payment['payment_status'] == 'pending'): ?>
                                                <span style="color: #999;">No screenshot</span>
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
    
    <!-- Screenshot Modal with OTP Confirmation -->
    <div class="modal" id="screenshotModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-image"></i> Payment Screenshot</h3>
                <button class="modal-close" onclick="closeScreenshotModal()">&times;</button>
            </div>
            
            <div id="screenshotContainer" style="text-align: center; margin-bottom: 15px;">
                <img id="screenshotImage" class="screenshot-image" src="" alt="Payment Screenshot">
            </div>
            
            <div id="paymentDetails" class="payment-details"></div>
            
            <!-- Balance Info -->
            <div id="balanceInfo" class="balance-info" style="display: none;">
                <div style="display: flex; justify-content: space-between;">
                    <span><strong>Total Amount:</strong> ₱<span id="totalAmount"></span></span>
                    <span><strong>Remaining Balance:</strong> <span id="remainingBalance" class="balance-positive"></span></span>
                </div>
            </div>
            
            <!-- OTP Display Area (shown if OTP exists) -->
            <div id="otpDisplayArea" style="display: none; margin-bottom: 15px;">
                <div style="background: #f0f8ff; padding: 10px; border-radius: 5px; border-left: 4px solid #102C57;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <span style="color: #102C57; font-weight: bold;">Current OTP:</span>
                            <span id="currentOtp" class="otp-badge" style="font-size: 1.2rem; margin-left: 10px;"></span>
                        </div>
                        <div>
                            <span style="color: #666; font-size: 0.8rem;">Valid for check-in</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Email Info -->
            <div id="emailInfo" style="background: #e8f4fd; padding: 8px; border-radius: 4px; margin-bottom: 10px; font-size: 0.8rem; display: none;">
                <i class="fas fa-envelope" style="color: #1679AB;"></i> 
                <span id="guestEmailDisplay"></span>
            </div>
            
            <!-- Verification Actions with OTP Options -->
            <div id="verificationActions" class="verification-actions">
                <!-- Approve with existing OTP -->
                <form method="POST" id="approveForm" style="flex: 2;">
                    <input type="hidden" name="action" value="verify_payment">
                    <input type="hidden" name="payment_id" id="verify_payment_id">
                    <input type="hidden" name="reservation_id" id="verify_reservation_id">
                    <input type="hidden" name="verification_action" value="approve">
                    <button type="submit" class="btn-approve-large" onclick="return confirmApprove()">
                        <i class="fas fa-check-circle"></i> Approve (Keep OTP)
                    </button>
                </form>
                
                <!-- Approve with new OTP -->
                <form method="POST" id="approveWithOtpForm" style="flex: 2;">
                    <input type="hidden" name="action" value="verify_payment">
                    <input type="hidden" name="payment_id" id="verify_payment_id_otp">
                    <input type="hidden" name="reservation_id" id="verify_reservation_id_otp">
                    <input type="hidden" name="verification_action" value="approve_with_otp">
                    <button type="submit" class="btn-approve-otp-large" onclick="return confirmApproveWithOtp()">
                        <i class="fas fa-key"></i> Approve & Generate New OTP
                    </button>
                </form>
                
                <!-- Reject button -->
                <button class="btn-reject-large" onclick="showRejectReason()">
                    <i class="fas fa-times-circle"></i> Reject
                </button>
            </div>
            
            <!-- Rejection Reason Form -->
            <div id="rejectReasonDiv" class="rejection-reason">
                <form method="POST">
                    <input type="hidden" name="action" value="verify_payment">
                    <input type="hidden" name="payment_id" id="reject_payment_id">
                    <input type="hidden" name="reservation_id" id="reject_reservation_id">
                    <input type="hidden" name="verification_action" value="reject">
                    
                    <div class="form-group">
                        <label for="rejection_reason">Reason for Rejection</label>
                        <select name="rejection_reason" id="rejection_reason" class="form-control" required>
                            <option value="">Select reason</option>
                            <option value="Invalid screenshot">Invalid screenshot</option>
                            <option value="Amount mismatch">Amount mismatch</option>
                            <option value="Unclear image">Unclear image</option>
                            <option value="Duplicate payment">Duplicate payment</option>
                            <option value="Invalid payment method">Invalid payment method</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-reject-large" style="width: 100%;" onclick="return confirm('Reject this payment?')">
                        <i class="fas fa-times-circle"></i> Confirm Rejection
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- OTP Success Modal (for showing generated OTP) -->
    <div class="modal" id="otpSuccessModal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle" style="color: #28a745;"></i> Payment Approved</h3>
                <button class="modal-close" onclick="closeOtpModal()">&times;</button>
            </div>
            <div style="padding: 20px;">
                <div style="font-size: 3rem; color: #28a745; margin-bottom: 15px;">
                    <i class="fas fa-key"></i>
                </div>
                <h4 style="color: #102C57; margin-bottom: 10px;">OTP Generated Successfully</h4>
                <div class="otp-display" id="generatedOtpDisplay"></div>
                <div id="balanceDisplay" style="margin-top: 10px; padding: 8px; background: #e8f4fd; border-radius: 4px; font-size: 0.9rem;">
                    <span id="balanceMessage"></span>
                </div>
                <div id="emailSentDisplay" style="margin-top: 10px; padding: 8px; background: #d4edda; color: #155724; border-radius: 4px; display: none;">
                    <i class="fas fa-envelope"></i> OTP sent to guest email
                </div>
                <p style="color: #666; font-size: 0.9rem; margin-top: 15px;">
                    Share this OTP with the guest for check-in verification
                </p>
                <button class="btn btn-primary" onclick="closeOtpModal()" style="margin-top: 15px; width: 100%;">
                    <i class="fas fa-check"></i> OK
                </button>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }
        
        function viewScreenshot(paymentId, reservationId, filename, paymentNumber, amount, method, currentOtp, remainingBalance, totalAmount) {
            const img = document.getElementById('screenshotImage');
            img.src = '<?php echo BASE_URL; ?>/uploads/payments/' + filename;
            
            // Set form values
            document.getElementById('verify_payment_id').value = paymentId;
            document.getElementById('verify_reservation_id').value = reservationId;
            document.getElementById('verify_payment_id_otp').value = paymentId;
            document.getElementById('verify_reservation_id_otp').value = reservationId;
            document.getElementById('reject_payment_id').value = paymentId;
            document.getElementById('reject_reservation_id').value = reservationId;
            
            // Show payment details
            let detailsHtml = `
                <p><strong>Payment #:</strong> ${paymentNumber}</p>
                <p><strong>Amount:</strong> ₱${parseFloat(amount).toFixed(2)}</p>
                <p><strong>Method:</strong> ${method}</p>
            `;
            document.getElementById('paymentDetails').innerHTML = detailsHtml;
            
            // Show balance info
            if (totalAmount) {
                document.getElementById('totalAmount').textContent = parseFloat(totalAmount).toFixed(2);
                document.getElementById('remainingBalance').textContent = '₱' + parseFloat(remainingBalance).toFixed(2);
                document.getElementById('balanceInfo').style.display = 'block';
            }
            
            // Show OTP if exists
            if (currentOtp && currentOtp !== 'null') {
                document.getElementById('currentOtp').textContent = currentOtp;
                document.getElementById('otpDisplayArea').style.display = 'block';
            } else {
                document.getElementById('otpDisplayArea').style.display = 'none';
            }
            
            // Reset UI
            document.getElementById('rejectReasonDiv').classList.remove('show');
            document.getElementById('screenshotModal').classList.add('active');
        }
        
        function closeScreenshotModal() {
            document.getElementById('screenshotModal').classList.remove('active');
        }
        
        function closeOtpModal() {
            document.getElementById('otpSuccessModal').classList.remove('active');
        }
        
        function showRejectReason() {
            document.getElementById('rejectReasonDiv').classList.add('show');
        }
        
        function confirmApprove() {
            return confirm('Approve this payment? The guest will use their existing OTP for check-in. OTP will be sent to their email if fully paid.');
        }
        
        function confirmApproveWithOtp() {
            return confirm('Approve this payment and generate a NEW OTP? The guest must use this new OTP for check-in. OTP will be sent to their email if fully paid.');
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
            const modal = document.getElementById('screenshotModal');
            const otpModal = document.getElementById('otpSuccessModal');
            if (event.target == modal) {
                closeScreenshotModal();
            }
            if (event.target == otpModal) {
                closeOtpModal();
            }
        }
        
        // Check for OTP in URL or message to show OTP modal
        <?php if ($message_type === 'success' && strpos($message, 'OTP:') !== false): ?>
        window.addEventListener('DOMContentLoaded', function() {
            // Extract OTP from message
            const message = <?php echo json_encode($message); ?>;
            const otpMatch = message.match(/OTP: (\d+)/);
            if (otpMatch) {
                document.getElementById('generatedOtpDisplay').textContent = otpMatch[1];
                
                // Extract balance info
                const balanceMatch = message.match(/Remaining balance: ₱([\d,]+\.\d+)/);
                if (balanceMatch) {
                    document.getElementById('balanceMessage').textContent = 'Remaining balance: ₱' + balanceMatch[1];
                } else {
                    document.getElementById('balanceMessage').textContent = 'Reservation fully paid and confirmed';
                }
                
                // Check if email was sent
                if (message.includes('sent to')) {
                    document.getElementById('emailSentDisplay').style.display = 'block';
                }
                
                document.getElementById('otpSuccessModal').classList.add('active');
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>