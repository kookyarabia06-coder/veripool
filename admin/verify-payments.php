<?php
/**
 * Veripool Reservation System - Admin Verify Payments Page
 * View and verify pending payment screenshots with OTP confirmation
 * Coastal Harmony Theme - Gray, Blue, Green
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
                        
                        // Use EntryPassManager to send OTP email if reservation is fully paid
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
 * Extract screenshot filename from payment record
 * Only checks the screenshot column in the database
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
    <title>Verify Payments - Veripool Admin</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Sidebar CSS -->
    <link rel="stylesheet" href="/assets/css/sidebar.css">
    
    <style>
        /* ===== COASTAL HARMONY THEME - VERIFY PAYMENTS PAGE ===== */
        :root {
            --gray-100: #F7FAFC;
            --gray-200: #EDF2F7;
            --gray-300: #E2E8F0;
            --gray-400: #CBD5E0;
            --gray-500: #A0AEC0;
            --gray-600: #718096;
            --gray-700: #4A5568;
            --gray-800: #2D3748;
            --gray-900: #1A202C;
            
            --blue-500: #2B6F8B;
            --blue-600: #1E5770;
            --blue-700: #143F52;
            
            --green-500: #2F855A;
            --green-600: #276749;
            --green-700: #1E4B38;
            
            --white: #FFFFFF;
            --shadow-sm: 0 4px 6px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 10px 25px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 20px 40px rgba(0, 0, 0, 0.08);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            overflow-x: hidden;
        }
        
        /* Main Content Layout */
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--white) 100%);
            position: relative;
        }
        
        /* Decorative background elements */
        .main-content::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(43, 111, 139, 0.03) 0%, transparent 70%);
            border-radius: 50%;
            z-index: 0;
            pointer-events: none;
        }
        
        .main-content::after {
            content: '';
            position: absolute;
            bottom: -100px;
            left: -100px;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(47, 133, 90, 0.03) 0%, transparent 70%);
            border-radius: 50%;
            z-index: 0;
            pointer-events: none;
        }
        
        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px 25px;
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            position: relative;
            z-index: 2;
        }
        
        .top-bar h1 {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.6rem;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .top-bar h1 i {
            color: var(--blue-500);
            background: var(--gray-100);
            padding: 10px;
            border-radius: 12px;
            font-size: 1.2rem;
        }
        
        .date-info {
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--gray-600);
            font-size: 0.95rem;
            background: var(--gray-100);
            padding: 8px 16px;
            border-radius: 40px;
            border: 1px solid var(--gray-200);
        }
        
        .date-info i {
            color: var(--blue-500);
            margin-right: 5px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid;
            position: relative;
            z-index: 2;
            background: var(--white);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }
        
        .alert i {
            font-size: 1.2rem;
        }
        
        .alert-success {
            border-left-color: var(--green-500);
            color: var(--green-700);
        }
        
        .alert-error {
            border-left-color: #C53030;
            color: #C53030;
            background: #FFF5F5;
            border-color: #FED7D7;
        }
        
        /* Tab Navigation */
        .tab-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            padding-bottom: 5px;
            flex-wrap: wrap;
            align-items: center;
            position: relative;
            z-index: 2;
        }
        
        .tab-btn {
            padding: 12px 25px;
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 40px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gray-700);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        
        .tab-btn:hover {
            background: var(--gray-100);
            border-color: var(--blue-500);
            color: var(--blue-500);
            transform: translateY(-2px);
        }
        
        .tab-btn.active {
            background: var(--blue-500);
            color: white;
            border-color: var(--blue-500);
            box-shadow: 0 4px 10px rgba(43, 111, 139, 0.2);
        }
        
        .tab-btn .badge {
            background: var(--gray-200);
            color: var(--gray-700);
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .tab-btn.active .badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            background: var(--white);
            padding: 15px 20px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            position: relative;
            z-index: 2;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--gray-700);
        }
        
        .stat-item i {
            color: var(--blue-500);
            font-size: 1rem;
        }
        
        .stat-item.warning i {
            color: #ED8936;
        }
        
        .stat-item.success i {
            color: var(--green-500);
        }
        
        .stat-item.danger i {
            color: #C53030;
        }
        
        .stat-item strong {
            color: var(--gray-900);
            margin-right: 3px;
            font-weight: 600;
        }
        
        /* Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            position: relative;
            z-index: 2;
        }
        
        .filter-section form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 120px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-input {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .filter-input:focus {
            outline: none;
            border-color: var(--blue-500);
            box-shadow: 0 0 0 3px rgba(43, 111, 139, 0.1);
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            font-family: 'Inter', sans-serif;
        }
        
        .btn-primary {
            background: var(--blue-500);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--blue-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--gray-700);
            border: 2px solid var(--gray-300);
        }
        
        .btn-outline:hover {
            border-color: var(--blue-500);
            color: var(--blue-500);
        }
        
        /* Card */
        .card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: 30px;
            overflow: hidden;
            position: relative;
            z-index: 2;
        }
        
        .card-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, var(--white), var(--gray-100));
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-900);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .card-header h3 i {
            color: var(--blue-500);
        }
        
        .card-header .badge {
            background: var(--gray-100);
            color: var(--gray-700);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid var(--gray-200);
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        
        table th {
            text-align: left;
            padding: 12px 8px;
            background: var(--gray-100);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--gray-200);
            white-space: nowrap;
        }
        
        table td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--gray-200);
            vertical-align: middle;
            color: var(--gray-700);
        }
        
        table tr:hover td {
            background: var(--gray-100);
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-pending { 
            background: #FEF3C7; 
            color: #92400E; 
        }
        .status-completed { 
            background: #DEF7EC; 
            color: var(--green-700); 
        }
        .status-failed { 
            background: #FEE2E2; 
            color: #B91C1C; 
        }
        .status-partial { 
            background: #E1EFFE; 
            color: var(--blue-700); 
        }
        
        /* Payment Badges */
        .payment-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
        }
        
        .payment-badge.completed {
            background: #DEF7EC;
            color: var(--green-700);
        }
        
        .payment-badge.pending {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .payment-badge.failed {
            background: #FEE2E2;
            color: #B91C1C;
        }
        
        /* Method Badge */
        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
        }
        
        .method-gcash {
            background: #E1EFFE;
            color: var(--blue-700);
        }
        
        .method-cash {
            background: #DEF7EC;
            color: var(--green-700);
        }
        
        /* OTP Badge */
        .otp-badge {
            background: var(--gray-900);
            color: var(--gray-100);
            padding: 4px 10px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 1px;
        }
        
        /* Balance Display */
        .balance-positive {
            color: #C53030;
            font-weight: 600;
        }
        
        .balance-zero {
            color: var(--green-600);
            font-weight: 600;
        }
        
        /* Screenshot Thumbnail */
        .screenshot-thumb {
            width: 32px;
            height: 32px;
            background: var(--gray-100);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .screenshot-thumb:hover {
            background: var(--gray-200);
            transform: scale(1.1);
        }
        
        .screenshot-thumb i {
            font-size: 1rem;
            color: var(--blue-500);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-icon {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 500;
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-view { 
            background: var(--blue-500); 
            color: white; 
        }
        
        .btn-view:hover {
            background: var(--blue-600);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--gray-300);
            margin-bottom: 15px;
        }
        
        .empty-state p {
            color: var(--gray-600);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            max-width: 700px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .modal-header h3 {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-900);
            font-size: 1.2rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-header h3 i {
            color: var(--blue-500);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-500);
            transition: color 0.3s ease;
        }
        
        .modal-close:hover {
            color: #C53030;
        }
        
        .screenshot-image {
            width: 100%;
            max-height: 400px;
            object-fit: contain;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            margin-bottom: 15px;
        }
        
        .payment-details {
            background: var(--gray-100);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 15px;
            border: 1px solid var(--gray-200);
        }
        
        .payment-details p {
            margin: 5px 0;
            color: var(--gray-700);
        }
        
        .balance-info {
            background: #E1EFFE;
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            border-left: 4px solid var(--blue-500);
        }
        
        .balance-info strong {
            color: var(--gray-900);
        }
        
        .otp-display-area {
            background: var(--gray-900);
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            border-left: 4px solid var(--blue-500);
        }
        
        .otp-display-area .label {
            color: var(--gray-400);
            font-size: 0.8rem;
            margin-bottom: 5px;
        }
        
        .otp-display-area .value {
            font-family: monospace;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-100);
            letter-spacing: 3px;
        }
        
        .email-info {
            background: var(--gray-100);
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 0.85rem;
            border: 1px solid var(--gray-200);
        }
        
        .email-info i {
            color: var(--blue-500);
            margin-right: 8px;
        }
        
        .verification-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .btn-approve-large {
            background: var(--green-500);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            flex: 2;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-approve-large:hover {
            background: var(--green-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-approve-otp-large {
            background: var(--blue-500);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            flex: 2;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-approve-otp-large:hover {
            background: var(--blue-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-reject-large {
            background: #ED8936;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            flex: 1;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-reject-large:hover {
            background: #DD6B20;
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-cancel-large {
            background: var(--gray-500);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-cancel-large:hover {
            background: var(--gray-600);
            transform: translateY(-2px);
        }
        
        .rejection-reason {
            margin-top: 15px;
            display: none;
        }
        
        .rejection-reason.show {
            display: block;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--blue-500);
            box-shadow: 0 0 0 3px rgba(43, 111, 139, 0.1);
        }
        
        /* OTP Success Modal */
        .otp-display-large {
            background: var(--gray-900);
            color: var(--gray-100);
            padding: 20px;
            border-radius: 12px;
            font-size: 2.5rem;
            font-weight: 700;
            letter-spacing: 5px;
            text-align: center;
            margin: 20px 0;
            font-family: monospace;
        }
        
        .balance-message {
            background: var(--gray-100);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-size: 1rem;
            border: 1px solid var(--gray-200);
        }
        
        .email-sent-badge {
            background: #DEF7EC;
            color: var(--green-700);
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            margin: 15px 0;
            border: 1px solid #B9F5D8;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .stats-bar {
                flex-direction: column;
                gap: 8px;
            }
        }
        
        @media (max-width: 992px) {
            .filter-section form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .verification-actions {
                flex-direction: column;
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
                <i class="fas fa-credit-card"></i>
                Verify Payments
            </h1>
            <div class="date-info">
                <span><i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?></span>
                <span><i class="far fa-clock"></i> <?php echo date('h:i A'); ?></span>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Tab Navigation -->
        <div class="tab-nav">
            <a href="reservations.php" class="tab-btn">
                <i class="fas fa-calendar-check"></i> Reservations
            </a>
            <a href="verify-payments.php" class="tab-btn active">
                <i class="fas fa-credit-card"></i> Verify Payments
                <?php if ($pending_count > 0): ?>
                    <span class="badge"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <!-- Stats Bar -->
        <div class="stats-bar">
            <div class="stat-item">
                <i class="fas fa-credit-card"></i>
                <span><strong><?php echo $total_payments; ?></strong> Total Payments</span>
            </div>
            <div class="stat-item warning">
                <i class="fas fa-clock"></i>
                <span><strong><?php echo $pending_count; ?></strong> Pending</span>
            </div>
            <div class="stat-item success">
                <i class="fas fa-check-circle"></i>
                <span><strong><?php echo $completed_count; ?></strong> Completed</span>
            </div>
            <div class="stat-item danger">
                <i class="fas fa-times-circle"></i>
                <span><strong><?php echo $failed_count; ?></strong> Failed</span>
            </div>
            <div class="stat-item">
                <i class="fas fa-coins"></i>
                <span><strong>₱<?php echo number_format($total_amount, 2); ?></strong> Total Amount</span>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET">
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
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a href="verify-payments.php" class="btn btn-outline">Reset</a>
                </div>
            </form>
        </div>
        
        <!-- Payments Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-credit-card"></i> Payment Verifications</h3>
                <span class="badge"><?php echo count($payments); ?> payments</span>
            </div>
            <div class="card-body">
                <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-credit-card"></i>
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
                                    $screenshot = extractScreenshotFilename($payment);
                                    $balance_class = ($payment['remaining_balance'] <= 0) ? 'balance-zero' : 'balance-positive';
                                ?>
                                <tr>
                                    <td><?php echo date('m/d', strtotime($payment['payment_date'])); ?></td>
                                    <td><strong><?php echo substr($payment['payment_number'], -8); ?></strong></td>
                                    <td>
                                        <?php echo $payment['reservation_number']; ?>
                                        <br><small style="color: var(--gray-600);"><?php echo ucfirst($payment['reservation_status']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($payment['guest_name']); ?>
                                        <br><small style="color: var(--gray-600);"><?php echo $payment['guest_phone']; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($payment['room_number']): ?>
                                            Rm <?php echo $payment['room_number']; ?>
                                            <br><small style="color: var(--gray-600);"><?php echo $payment['room_type']; ?></small>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong>₱<?php echo number_format($payment['amount'], 0); ?></strong></td>
                                    <td>
                                        <span class="method-badge method-<?php echo $payment['payment_method']; ?>">
                                            <?php if ($payment['payment_method'] == 'gcash'): ?>
                                                <i class="fab fa-google-pay"></i> GCash
                                            <?php elseif ($payment['payment_method'] == 'cash'): ?>
                                                <i class="fas fa-money-bill"></i> Cash
                                            <?php else: ?>
                                                <?php echo ucfirst($payment['payment_method']); ?>
                                            <?php endif; ?>
                                        </span>
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
                                                    <span style="color: var(--gray-400);" title="Only Cash and GCash are accepted">Invalid</span>
                                                <?php endif; ?>
                                            <?php elseif ($payment['payment_status'] == 'pending'): ?>
                                                <span style="color: var(--gray-400);">No screenshot</span>
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
            
            <div id="screenshotContainer" style="text-align: center;">
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
            <div id="otpDisplayArea" style="display: none;" class="otp-display-area">
                <div class="label">Current OTP</div>
                <div class="value" id="currentOtp"></div>
            </div>
            
            <!-- Email Info -->
            <div id="emailInfo" class="email-info" style="display: none;">
                <i class="fas fa-envelope"></i> 
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
    
    <!-- OTP Success Modal -->
    <div class="modal" id="otpSuccessModal">
        <div class="modal-content" style="max-width: 450px; text-align: center;">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle" style="color: var(--green-500);"></i> Payment Approved</h3>
                <button class="modal-close" onclick="closeOtpModal()">&times;</button>
            </div>
            <div style="padding: 10px;">
                <div style="font-size: 3rem; color: var(--green-500); margin-bottom: 15px;">
                    <i class="fas fa-key"></i>
                </div>
                <h4 style="color: var(--gray-900); margin-bottom: 10px; font-family: 'Montserrat', sans-serif;">OTP Generated Successfully</h4>
                <div class="otp-display-large" id="generatedOtpDisplay"></div>
                <div class="balance-message" id="balanceMessage"></div>
                <div id="emailSentDisplay" class="email-sent-badge" style="display: none;">
                    <i class="fas fa-envelope"></i> OTP sent to guest email
                </div>
                <button class="btn btn-primary" onclick="closeOtpModal()" style="margin-top: 15px; width: 100%; padding: 12px;">
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