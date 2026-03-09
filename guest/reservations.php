<?php
/**
 * Veripool Reservation System - Guest Reservations Page
 * View and manage all reservations with payment integration and calendar
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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get current user
$user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Check if user is guest (redirect if not)
if ($user['role'] !== 'guest') {
    if ($user['role'] == 'admin' || $user['role'] == 'super_admin') {
        header("Location: " . BASE_URL . "/admin/dashboard.php");
    } elseif ($user['role'] == 'staff') {
        header("Location: " . BASE_URL . "/staff/dashboard.php");
    }
    exit;
}

// Initialize EntryPassManager
$entryPassManager = new EntryPassManager($db);

// Create upload directory if it doesn't exist
$upload_dir = BASE_PATH . '/uploads/payments/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle date adjustment request
$adjustment_message = '';
$adjustment_message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_adjustment') {
    $reservation_id = (int)$_POST['reservation_id'];
    $new_check_in = $_POST['new_check_in'];
    $new_check_out = $_POST['new_check_out'];
    $reason = sanitize($_POST['reason']);
    
    // Get entry pass for this reservation
    $entry_pass = $db->getRow("
        SELECT ep.* FROM entry_passes ep
        JOIN reservations r ON ep.reservation_id = r.id
        WHERE r.id = ? AND r.user_id = ?
    ", [$reservation_id, $user['id']]);
    
    if (!$entry_pass) {
        $adjustment_message = "No active entry pass found for this reservation.";
        $adjustment_message_type = 'error';
    } else {
        // Check if already has pending adjustment request
        $pending = $db->getRow("
            SELECT * FROM date_adjustment_requests 
            WHERE entry_pass_id = ? AND status = 'pending'
        ", [$entry_pass['id']]);
        
        if ($pending) {
            $adjustment_message = "You already have a pending adjustment request for this reservation.";
            $adjustment_message_type = 'error';
        } else {
            // Check adjustment limit
            if ($entry_pass['date_adjustments'] >= 2) {
                $adjustment_message = "Maximum number of date adjustments (2) reached for this reservation.";
                $adjustment_message_type = 'error';
            } else {
                // Create adjustment request
                $result = $entryPassManager->requestDateAdjustment(
                    $entry_pass['id'],
                    $user['id'],
                    $new_check_in,
                    $new_check_out,
                    $reason
                );
                
                if ($result['success']) {
                    $adjustment_message = $result['message'];
                    $adjustment_message_type = 'success';
                } else {
                    $adjustment_message = $result['message'];
                    $adjustment_message_type = 'error';
                }
            }
        }
    }
}

// Get user's reservations with entry pass and adjustment info
$reservations = $db->getRows("
    SELECT r.*, rm.room_number, rt.name as room_type, rt.base_price,
           ep.id as entry_pass_id, ep.otp_code, ep.status as pass_status,
           ep.valid_from, ep.valid_until, ep.date_adjustments,
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id AND payment_status = 'completed') as total_paid,
           (SELECT COUNT(*) FROM date_adjustment_requests WHERE entry_pass_id = ep.id AND status = 'pending') as pending_adjustment
    FROM reservations r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
", [$user['id']]);

// Get user's cottage reservations
$cottage_reservations = $db->getRows("
    SELECT rc.*, r.reservation_number, c.cottage_name, c.cottage_type, c.price as cottage_price,
           r.check_in_date, r.check_out_date, r.status
    FROM reservation_cottages rc
    JOIN reservations r ON rc.reservation_id = r.id
    JOIN cottages c ON rc.cottage_id = c.id
    WHERE r.user_id = ?
    ORDER BY rc.created_at DESC
", [$user['id']]);

// Get all reservations for calendar (to show booked dates)
$all_reservations = $db->getRows("
    SELECT r.id, r.check_in_date, r.check_out_date, r.status, rm.room_number
    FROM reservations r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    WHERE r.status IN ('confirmed', 'checked_in', 'pending')
    ORDER BY r.check_in_date
");

// Get user's payments
$payments = $db->getRows("
    SELECT p.*, r.reservation_number
    FROM payments p
    LEFT JOIN reservations r ON p.reservation_id = r.id
    WHERE r.user_id = ? OR p.created_by = ?
    ORDER BY p.payment_date DESC
", [$user['id'], $user['id']]);

// Get user's pending adjustment requests
$pending_requests = $db->getRows("
    SELECT dar.*, r.reservation_number, r.check_in_date as original_check_in, 
           r.check_out_date as original_check_out
    FROM date_adjustment_requests dar
    JOIN reservations r ON dar.reservation_id = r.id
    WHERE dar.user_id = ? AND dar.status = 'pending'
    ORDER BY dar.created_at DESC
", [$user['id']]);

// Handle file upload
$upload_error = '';
$upload_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payment_screenshot'])) {
    $reservation_id = (int)$_POST['reservation_id'];
    $amount = (float)$_POST['amount'];
    $payment_method = sanitize($_POST['payment_method']);
    
    $file = $_FILES['payment_screenshot'];
    $file_name = $file['name'];
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $file_error = $file['error'];
    
    // Better file extension detection - case insensitive
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    
    // Debug - log file info
    error_log("File upload attempt - Name: $file_name, Ext: $file_ext, Size: $file_size, Error: $file_error");
    
    // Validate file with better error messages
    if (empty($file_name)) {
        $upload_error = "No file selected.";
    } elseif (!in_array($file_ext, $allowed_ext)) {
        $upload_error = "Invalid file type. Allowed: JPG, JPEG, PNG, GIF, PDF. Your file: .$file_ext";
    } elseif ($file_error !== 0) {
        $upload_error = "Error uploading file. Error code: " . $file_error;
    } elseif ($file_size > 5242880) { // 5MB max
        $upload_error = "File size too large. Maximum 5MB allowed. Your file: " . round($file_size / 1048576, 2) . "MB";
    } else {
        // Generate unique file name with timestamp
        $new_file_name = 'payment_' . $reservation_id . '_' . time() . '.' . $file_ext;
        $file_destination = $upload_dir . $new_file_name;
        
        if (move_uploaded_file($file_tmp, $file_destination)) {
            // Verify reservation belongs to user
            $reservation = $db->getRow(
                "SELECT * FROM reservations WHERE id = ? AND user_id = ?",
                [$reservation_id, $user['id']]
            );
            
            if ($reservation) {
                // Generate payment number
                $payment_number = 'PAY' . date('Ymd') . rand(1000, 9999);
                
                $payment_data = [
                    'payment_number' => $payment_number,
                    'reservation_id' => $reservation_id,
                    'amount' => $amount,
                    'payment_method' => $payment_method,
                    'payment_status' => 'pending', // Set to pending for e-wallet payments
                    'created_by' => $user['id'],
                    'payment_date' => date('Y-m-d H:i:s'),
                    'screenshot' => $new_file_name,
                    'notes' => 'Payment from guest portal via GCash'
                ];
                
                $payment_id = $db->insert('payments', $payment_data);
                
                if ($payment_id) {
                    $upload_success = "Payment proof uploaded successfully! Your payment is now pending verification.";
                    
                    // Refresh payments
                    $payments = $db->getRows("
                        SELECT p.*, r.reservation_number
                        FROM payments p
                        LEFT JOIN reservations r ON p.reservation_id = r.id
                        WHERE r.user_id = ? OR p.created_by = ?
                        ORDER BY p.payment_date DESC
                    ", [$user['id'], $user['id']]);
                } else {
                    $upload_error = "Database error: Could not save payment record.";
                    // Delete uploaded file if database insert fails
                    if (file_exists($file_destination)) {
                        unlink($file_destination);
                    }
                }
            } else {
                $upload_error = "Invalid reservation.";
                // Delete uploaded file if reservation doesn't belong to user
                unlink($file_destination);
            }
        } else {
            $upload_error = "Failed to upload file. Please check directory permissions.";
        }
    }
}

// Handle cancel reservation
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_reservation') {
    $reservation_id = (int)$_POST['reservation_id'];
    
    // Verify reservation belongs to user
    $reservation = $db->getRow(
        "SELECT * FROM reservations WHERE id = ? AND user_id = ?",
        [$reservation_id, $user['id']]
    );
    
    if ($reservation && in_array($reservation['status'], ['pending', 'confirmed'])) {
        $db->update('reservations', ['status' => 'cancelled'], 'id = :id', ['id' => $reservation_id]);
        $message = 'Reservation cancelled successfully';
        $message_type = 'success';
        
        // Refresh reservations
        $reservations = $db->getRows("
            SELECT r.*, rm.room_number, rt.name as room_type, rt.base_price,
                   ep.id as entry_pass_id, ep.otp_code, ep.status as pass_status,
                   ep.valid_from, ep.valid_until, ep.date_adjustments,
                   (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id AND payment_status = 'completed') as total_paid,
                   (SELECT COUNT(*) FROM date_adjustment_requests WHERE entry_pass_id = ep.id AND status = 'pending') as pending_adjustment
            FROM reservations r
            LEFT JOIN rooms rm ON r.room_id = rm.id
            LEFT JOIN room_types rt ON rm.room_type_id = rt.id
            LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
            WHERE r.user_id = ?
            ORDER BY r.created_at DESC
        ", [$user['id']]);
    } else {
        $message = 'Cannot cancel this reservation';
        $message_type = 'error';
    }
}

// Calculate payment statistics
$total_paid = 0;
$completed_payments = 0;
$pending_payments = 0;

foreach ($payments as $payment) {
    if ($payment['payment_status'] == 'completed') {
        $total_paid += $payment['amount'];
        $completed_payments++;
    } elseif ($payment['payment_status'] == 'pending') {
        $pending_payments++;
    }
}

// Get current month for calendar
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Adjust month/year
if ($current_month < 1) {
    $current_month = 12;
    $current_year--;
} elseif ($current_month > 12) {
    $current_month = 1;
    $current_year++;
}

$first_day = mktime(0, 0, 0, $current_month, 1, $current_year);
$days_in_month = date('t', $first_day);
$month_name = date('F Y', $first_day);
$day_of_week = date('w', $first_day);

// Helper function to check if date can be adjusted
function canAdjustDate($reservation) {
    return $reservation['status'] == 'confirmed' && 
           !empty($reservation['otp_code']) && 
           $reservation['date_adjustments'] < 2 &&
           $reservation['pending_adjustment'] == 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations - Veripool Resort</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">
    
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        /* Calendar Styles */
        .calendar-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .calendar-header h3 {
            color: #102C57;
            font-size: 1.5rem;
        }
        
        .calendar-nav {
            display: flex;
            gap: 10px;
        }
        
        .calendar-nav a {
            background: #f8f9fa;
            padding: 8px 15px;
            border-radius: 5px;
            color: #102C57;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .calendar-nav a:hover {
            background: #1679AB;
            color: white;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }
        
        .calendar-weekday {
            text-align: center;
            padding: 10px;
            font-weight: bold;
            color: #102C57;
            background: #FFCBCB;
            border-radius: 5px;
        }
        
        .calendar-day {
            background: #f8f9fa;
            padding: 10px;
            min-height: 80px;
            border-radius: 5px;
            position: relative;
            transition: all 0.3s;
        }
        
        .calendar-day:hover {
            background: #e9ecef;
        }
        
        .calendar-day.empty {
            background: transparent;
        }
        
        .calendar-day .day-number {
            font-weight: bold;
            color: #102C57;
            margin-bottom: 5px;
        }
        
        .calendar-day .booking-indicator {
            font-size: 0.7rem;
            padding: 2px 5px;
            border-radius: 3px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .booking-confirmed {
            background: #d4edda;
            color: #155724;
        }
        
        .booking-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .booking-checked_in {
            background: #cce5ff;
            color: #004085;
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
        
        /* Payment Modal Styles */
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
            max-width: 550px;
            width: 90%;
            max-height: 85vh;
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
        
        .payment-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .payment-info p {
            margin: 5px 0;
        }
        
        .payment-info .balance {
            font-size: 1.5rem;
            font-weight: bold;
            color: #dc3545;
        }
        
        /* Payment Methods */
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 20px 0;
        }
        
        .payment-method-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px 5px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
        }
        
        .payment-method-card:hover {
            border-color: #1679AB;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(22,121,171,0.1);
        }
        
        .payment-method-card.selected {
            border-color: #1679AB;
            background: #f0f8ff;
        }
        
        .payment-method-card i {
            font-size: 2rem;
            margin-bottom: 5px;
        }
        
        .payment-method-card .method-name {
            font-weight: bold;
            color: #102C57;
            font-size: 0.9rem;
        }
        
        .payment-method-card.cash i { color: #28a745; }
        .payment-method-card.gcash i { color: #0066ff; }
        
        /* Instructions Section */
        .instructions-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #1679AB;
        }
        
        .instructions-section h4 {
            color: #102C57;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .instruction-step {
            display: flex;
            gap: 15px;
            margin-bottom: 12px;
            align-items: flex-start;
        }
        
        .step-number {
            width: 25px;
            height: 25px;
            background: #1679AB;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        
        .step-text {
            color: #333;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        
        .account-details {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            border: 1px dashed #1679AB;
        }
        
        .account-details p {
            margin: 5px 0;
            font-size: 0.9rem;
        }
        
        /* Upload Section */
        .upload-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
            border: 2px dashed #1679AB;
            text-align: center;
        }
        
        .upload-section i {
            font-size: 3rem;
            color: #1679AB;
            margin-bottom: 10px;
        }
        
        .upload-section h5 {
            color: #102C57;
            margin-bottom: 5px;
        }
        
        .upload-section p {
            color: #666;
            font-size: 0.85rem;
            margin-bottom: 15px;
        }
        
        .file-input-wrapper {
            position: relative;
            margin-bottom: 10px;
        }
        
        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: -9999px;
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .file-input-label {
            display: inline-block;
            padding: 12px 25px;
            background: #1679AB;
            color: white;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .file-input-label:hover {
            background: #102C57;
            transform: translateY(-2px);
        }
        
        .file-input-label i {
            font-size: 1rem;
            margin-right: 5px;
        }
        
        .file-name {
            margin-top: 10px;
            padding: 8px;
            background: #e9ecef;
            border-radius: 5px;
            font-size: 0.85rem;
            color: #102C57;
            display: none;
        }
        
        .file-name i {
            font-size: 0.9rem;
            color: #28a745;
            margin-right: 5px;
        }
        
        .file-name.show {
            display: block;
        }
        
        .preview-image {
            max-width: 100%;
            max-height: 150px;
            margin-top: 10px;
            border-radius: 5px;
            border: 2px solid #e0e0e0;
            display: none;
        }
        
        .preview-image.show {
            display: inline-block;
        }
        
        .btn-payment {
            background: #28a745;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            width: 100%;
            font-size: 1rem;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .btn-payment:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .btn-payment:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Payment Summary */
        .payment-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .payment-summary-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .payment-summary-card .label {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .payment-summary-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: #102C57;
        }
        
        /* Payment Badges */
        .payment-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
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
        
        .note-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px;
            border-radius: 5px;
            margin: 15px 0;
            font-size: 0.85rem;
        }
        
        .note-box i {
            color: #856404;
            margin-right: 5px;
        }
        
        .success-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 12px;
            border-radius: 5px;
            margin: 15px 0;
            font-size: 0.85rem;
            color: #155724;
        }
        
        .error-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 12px;
            border-radius: 5px;
            margin: 15px 0;
            font-size: 0.85rem;
            color: #721c24;
        }
        
        /* Better file type hint */
        .file-type-hint {
            font-size: 0.75rem;
            color: #1679AB;
            margin-top: 5px;
        }
        
        /* OTP and Adjustment Styles */
        .otp-code {
            font-family: monospace;
            font-size: 1.1rem;
            font-weight: bold;
            background: #102C57;
            color: #FFCBCB;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
            letter-spacing: 2px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .otp-code:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .adjustment-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7rem;
            font-weight: bold;
            margin-left: 5px;
        }
        
        .adjustment-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .adjustment-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .adjustment-info {
            font-size: 0.75rem;
            color: #666;
            margin-top: 2px;
        }
        
        .adjustment-count {
            color: #28a745;
            font-weight: bold;
        }
        
        .btn-adjust {
            background: #ffc107;
            color: #102C57;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        
        .btn-adjust:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }
        
        .btn-adjust:disabled {
            background: #e9ecef;
            color: #999;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Pending Requests Card */
        .pending-card {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .pending-card h4 {
            color: #856404;
            margin-bottom: 10px;
        }
        
        .request-item {
            background: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .request-dates {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
        }
        
        .request-original {
            color: #dc3545;
            text-decoration: line-through;
        }
        
        .request-new {
            color: #28a745;
            font-weight: bold;
        }
        
        .request-status {
            background: #ffc107;
            color: #102C57;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        /* Date Input Styles */
        .date-input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .date-input-group input {
            flex: 1;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
        }
        
        .date-input-group input:focus {
            border-color: #1679AB;
            outline: none;
        }
        
        @media (max-width: 768px) {
            .payment-methods {
                grid-template-columns: 1fr;
            }
            
            .payment-summary {
                grid-template-columns: 1fr;
            }
            
            .instruction-step {
                flex-direction: column;
                gap: 5px;
            }
            
            .request-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .date-input-group {
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
                <i class="fas fa-calendar-check"></i>
                My Reservations
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
        
        <?php if ($adjustment_message): ?>
            <div class="alert alert-<?php echo $adjustment_message_type; ?>">
                <i class="fas fa-<?php echo $adjustment_message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($adjustment_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($upload_success): ?>
            <div class="success-box">
                <i class="fas fa-check-circle"></i> <?php echo $upload_success; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($upload_error): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i> <?php echo $upload_error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Pending Adjustment Requests -->
       
        
        <!-- Payment Summary -->
        <div class="payment-summary">
            <div class="payment-summary-card">
                <div class="label">Total Paid</div>
                <div class="value">₱<?php echo number_format($total_paid, 2); ?></div>
            </div>
            <div class="payment-summary-card">
                <div class="label">Completed Payments</div>
                <div class="value"><?php echo $completed_payments; ?></div>
            </div>
            <div class="payment-summary-card">
                <div class="label">Pending</div>
                <div class="value"><?php echo $pending_payments; ?></div>
            </div>
        </div>
        
        <!-- Calendar Section -->
        <div class="calendar-section">
            <div class="calendar-header">
                <h3><i class="fas fa-calendar-alt"></i> Reservation Calendar - <?php echo $month_name; ?></h3>
                <div class="calendar-nav">
                    <a href="?month=<?php echo $current_month - 1; ?>&year=<?php echo $current_year; ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                    <a href="?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>"><i class="fas fa-calendar-day"></i> Today</a>
                    <a href="?month=<?php echo $current_month + 1; ?>&year=<?php echo $current_year; ?>">Next <i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
            
            <div class="calendar-grid">
                <div class="calendar-weekday">Sun</div>
                <div class="calendar-weekday">Mon</div>
                <div class="calendar-weekday">Tue</div>
                <div class="calendar-weekday">Wed</div>
                <div class="calendar-weekday">Thu</div>
                <div class="calendar-weekday">Fri</div>
                <div class="calendar-weekday">Sat</div>
                
                <?php
                // Empty cells before first day
                for ($i = 0; $i < $day_of_week; $i++) {
                    echo '<div class="calendar-day empty"></div>';
                }
                
                // Days of the month
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $current_date = sprintf('%04d-%02d-%02d', $current_year, $current_month, $day);
                    
                    // Find reservations for this date
                    $day_reservations = [];
                    foreach ($all_reservations as $res) {
                        $check_in = $res['check_in_date'];
                        $check_out = $res['check_out_date'];
                        
                        if ($current_date >= $check_in && $current_date < $check_out) {
                            $day_reservations[] = $res;
                        }
                    }
                    
                    echo '<div class="calendar-day">';
                    echo '<div class="day-number">' . $day . '</div>';
                    
                    foreach ($day_reservations as $booking) {
                        $status_class = 'booking-' . $booking['status'];
                        $room_info = $booking['room_number'] ? 'Room ' . $booking['room_number'] : 'Cottage';
                        echo '<div class="booking-indicator ' . $status_class . '" title="' . $room_info . '">';
                        echo '<i class="fas fa-circle"></i> ' . $room_info;
                        echo '</div>';
                    }
                    
                    echo '</div>';
                }
                ?>
            </div>
            
            <div style="margin-top: 15px; display: flex; gap: 15px; flex-wrap: wrap;">
                <span><i class="fas fa-circle" style="color: #d4edda;"></i> Confirmed</span>
                <span><i class="fas fa-circle" style="color: #fff3cd;"></i> Pending</span>
                <span><i class="fas fa-circle" style="color: #cce5ff;"></i> Checked In</span>
            </div>
        </div>
        
        <!-- Room Reservations -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-bed"></i> Room Reservations</h3>
                <span class="badge"><?php echo count($reservations); ?> records</span>
            </div>
            <div class="card-body">
                <?php if (empty($reservations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Room Reservations</h3>
                        <p>You haven't made any room reservations yet.</p>
                        <a href="new-reservation.php" class="btn-primary">Make a Reservation</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reservation #</th>
                                    <th>Room</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Guests</th>
                                    <th>Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>OTP / Adjustments</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reservations as $res): 
                                    $balance = $res['total_amount'] - $res['total_paid'];
                                    $can_adjust = canAdjustDate($res);
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($res['reservation_number']); ?></strong></td>
                                    <td>
                                        <?php if ($res['room_number']): ?>
                                            Room <?php echo $res['room_number']; ?><br>
                                            <small><?php echo $res['room_type']; ?></small>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($res['check_in_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($res['check_out_date'])); ?></td>
                                    <td><?php echo ($res['adults'] ?? 0) + ($res['children'] ?? 0); ?></td>
                                    <td>₱<?php echo number_format($res['total_amount'] ?? 0, 2); ?></td>
                                    <td>₱<?php echo number_format($res['total_paid'] ?? 0, 2); ?></td>
                                    <td>
                                        <?php if ($balance > 0): ?>
                                            <span style="color: #dc3545; font-weight: bold;">₱<?php echo number_format($balance, 2); ?></span>
                                        <?php else: ?>
                                            <span style="color: #28a745;">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $res['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $res['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($res['otp_code'] && $res['status'] == 'confirmed'): ?>
                                            <span class="otp-code" onclick="copyOTP('<?php echo $res['otp_code']; ?>')" title="Click to copy OTP">
                                                <?php echo $res['otp_code']; ?>
                                            </span>
                                            <?php if ($res['date_adjustments'] > 0): ?>
                                                <br>
                                                <small class="adjustment-info">
                                                    Adjusted: <span class="adjustment-count"><?php echo $res['date_adjustments']; ?>/2</span>
                                                </small>
                                            <?php endif; ?>
                                            <?php if ($res['pending_adjustment'] > 0): ?>
                                                <br>
                                                <span class="adjustment-badge adjustment-pending">
                                                    <i class="fas fa-clock"></i> Pending
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-direction: column;">
                                            <?php if ($balance > 0 && in_array($res['status'], ['pending', 'confirmed'])): ?>
                                                <button onclick="openPaymentModal(<?php echo $res['id']; ?>, '<?php echo $res['reservation_number']; ?>', <?php echo $balance; ?>)" class="btn-sm btn-success">
                                                    <i class="fas fa-credit-card"></i> Pay
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_adjust): ?>
                                                <button onclick="openAdjustmentModal(<?php echo $res['id']; ?>, '<?php echo $res['reservation_number']; ?>', '<?php echo $res['check_in_date']; ?>', '<?php echo $res['check_out_date']; ?>', <?php echo $res['date_adjustments']; ?>)" class="btn-sm btn-adjust">
                                                    <i class="fas fa-calendar-alt"></i> Adjust Dates
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($res['status'], ['pending', 'confirmed'])): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel this reservation?');">
                                                    <input type="hidden" name="action" value="cancel_reservation">
                                                    <input type="hidden" name="reservation_id" value="<?php echo $res['id']; ?>">
                                                    <button type="submit" class="btn-sm btn-danger">
                                                        <i class="fas fa-times"></i> Cancel
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
        
        <!-- Cottage Bookings -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-home"></i> Cottage Bookings</h3>
                <span class="badge"><?php echo count($cottage_reservations); ?> bookings</span>
            </div>
            <div class="card-body">
                <?php if (empty($cottage_reservations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-home"></i>
                        <h3>No Cottage Bookings</h3>
                        <p>You haven't booked any cottages yet.</p>
                        <a href="new-reservation.php" class="btn-primary">Book a Cottage</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reservation #</th>
                                    <th>Cottage</th>
                                    <th>Type</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cottage_reservations as $cottage): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($cottage['reservation_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cottage['cottage_name']); ?></td>
                                    <td>
                                        <span style="background: #FFB1B1; color: #102C57; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem;">
                                            <?php echo ucfirst($cottage['cottage_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($cottage['check_in_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($cottage['check_out_date'])); ?></td>
                                    <td>₱<?php echo number_format($cottage['price_at_time'] ?? $cottage['cottage_price'], 2); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $cottage['status']; ?>">
                                            <?php echo ucfirst($cottage['status']); ?>
                                        </span>
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
    
    <!-- Payment Modal -->
    <div class="modal" id="paymentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-credit-card"></i> Make a Payment</h3>
                <button class="modal-close" onclick="closePaymentModal()">&times;</button>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="paymentForm" onsubmit="return validatePayment()">
                <input type="hidden" name="reservation_id" id="payment_reservation_id">
                <input type="hidden" name="amount" id="payment_amount_hidden">
                <input type="hidden" name="payment_method" id="selected_payment_method" value="cash">
                
                <div class="payment-info" id="paymentInfo"></div>
                
                <div class="form-group">
                    <label>Select Payment Method</label>
                    
                    <div class="payment-methods">
                        <div class="payment-method-card cash" onclick="selectPaymentMethod('cash', event)">
                            <i class="fas fa-money-bill-wave"></i>
                            <div class="method-name">Cash</div>
                        </div>
                        
                        <div class="payment-method-card gcash" onclick="selectPaymentMethod('gcash', event)">
                            <i class="fas fa-mobile-alt"></i>
                            <div class="method-name">GCash</div>
                        </div>
                    </div>
                </div>
                
                <!-- Cash Instructions -->
                <div class="instructions-section" id="cashInstructions">
                    <h4><i class="fas fa-money-bill-wave"></i> Cash Payment</h4>
                    <div class="instruction-step">
                        <div class="step-number">1</div>
                        <div class="step-text">Proceed to the front desk at Veripool Resort.</div>
                    </div>
                    <div class="instruction-step">
                        <div class="step-number">2</div>
                        <div class="step-text">Present your Reservation Number: <strong id="cashReservation"></strong></div>
                    </div>
                    <div class="instruction-step">
                        <div class="step-number">3</div>
                        <div class="step-text">Pay the amount of <strong>₱<span id="cashAmount"></span></strong> in cash.</div>
                    </div>
                    <div class="note-box">
                        <i class="fas fa-info-circle"></i> Cash payments are processed immediately at the front desk.
                    </div>
                </div>
                
                <!-- GCash Instructions with Upload -->
                <div class="instructions-section" id="gcashInstructions" style="display: none;">
                    <h4><i class="fas fa-mobile-alt"></i> GCash Payment</h4>
                    
                    <div class="account-details">
                        <p><strong>GCash Number:</strong> 0999-123-4567</p>
                        <p><strong>Account Name:</strong> Veripool Resort</p>
                        <p><strong>Amount:</strong> ₱<span id="gcashAmount"></span></p>
                        <p><strong>Reference:</strong> Use Reservation #<span id="gcashReservation"></span></p>
                    </div>
                    
                    <div class="upload-section">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <h5>Upload Payment Screenshot</h5>
                        <p>Please upload a screenshot of your GCash transaction as proof of payment.</p>
                        
                        <!-- Added accept attribute for better file type handling -->
                        <div class="file-input-wrapper">
                            <input type="file" name="payment_screenshot" id="payment_screenshot" accept=".jpg,.jpeg,.png,.gif,.pdf,image/*" onchange="previewFile()">
                            <label for="payment_screenshot" class="file-input-label">
                                <i class="fas fa-camera"></i> Choose File
                            </label>
                        </div>
                        
                        <!-- Show allowed file types -->
                        <div class="file-type-hint">
                            <i class="fas fa-info-circle"></i> Allowed: JPG, JPEG, PNG, GIF, PDF (Max 5MB)
                        </div>
                        
                        <div class="file-name" id="file-name">
                            <i class="fas fa-check-circle"></i> <span id="file-name-text"></span>
                        </div>
                        
                        <img class="preview-image" id="image-preview" alt="Preview">
                    </div>
                    
                    <div class="note-box">
                        <i class="fas fa-info-circle"></i> Your payment will be pending until verified by our staff.
                    </div>
                </div>
                
                <button type="submit" class="btn-payment" id="submitBtn">
                    <i class="fas fa-check-circle"></i> Submit Payment
                </button>
            </form>
        </div>
    </div>
    
    <!-- Date Adjustment Modal -->
    <div class="modal" id="adjustmentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-alt"></i> Request Date Adjustment</h3>
                <button class="modal-close" onclick="closeAdjustmentModal()">&times;</button>
            </div>
            
            <form method="POST" id="adjustmentForm" onsubmit="return validateAdjustment()">
                <input type="hidden" name="action" value="request_adjustment">
                <input type="hidden" name="reservation_id" id="adjustment_reservation_id">
                
                <div class="payment-info" id="adjustmentInfo"></div>
                
                <div class="form-group">
                    <label>Current Reservation Dates</label>
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                        <p><strong>Check-in:</strong> <span id="current_check_in"></span></p>
                        <p><strong>Check-out:</strong> <span id="current_check_out"></span></p>
                        <p><strong>Adjustments used:</strong> <span id="adjustments_used">0</span>/2</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Request New Dates</label>
                    <div class="date-input-group">
                        <input type="date" name="new_check_in" id="new_check_in" class="form-control" required>
                        <input type="date" name="new_check_out" id="new_check_out" class="form-control" required>
                    </div>
                    <small style="color: #666;">Minimum 1 night stay, maximum 14 nights</small>
                </div>
                
                <div class="form-group">
                    <label>Reason for Adjustment</label>
                    <textarea name="reason" rows="3" class="form-control" placeholder="Please explain why you need to change your reservation dates..." required></textarea>
                </div>
                
                <div class="note-box">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Important:</strong>
                    <ul style="margin-top: 5px; margin-left: 20px;">
                        <li>Date adjustments are subject to availability</li>
                        <li>You can only adjust dates up to 2 times per reservation</li>
                        <li>Your request will be reviewed by staff</li>
                        <li>You will receive an email once your request is processed</li>
                        <li>Your OTP code will remain the same if approved</li>
                    </ul>
                </div>
                
                <button type="submit" class="btn-payment" style="background: #ffc107; color: #102C57;">
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </form>
        </div>
    </div>
    
    <script>
        let selectedReservationId = null;
        let balanceAmount = 0;
        let currentReservationNumber = '';
        let selectedFile = null;
        
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }
        
        function copyOTP(otp) {
            navigator.clipboard.writeText(otp).then(function() {
                alert('OTP copied to clipboard: ' + otp);
            }, function() {
                alert('Failed to copy OTP');
            });
        }
        
        // Payment Modal Functions
        function openPaymentModal(reservationId, reservationNumber, balance) {
            selectedReservationId = reservationId;
            balanceAmount = balance;
            currentReservationNumber = reservationNumber;
            
            document.getElementById('payment_reservation_id').value = reservationId;
            document.getElementById('payment_amount_hidden').value = balance;
            
            document.getElementById('paymentInfo').innerHTML = `
                <p><strong>Reservation:</strong> ${reservationNumber}</p>
                <p><strong>Balance Due:</strong> <span class="balance">₱${balance.toFixed(2)}</span></p>
            `;
            
            // Update cash fields
            document.getElementById('cashReservation').textContent = reservationNumber;
            document.getElementById('cashAmount').textContent = balance.toFixed(2);
            
            // Update GCash fields
            document.getElementById('gcashAmount').textContent = balance.toFixed(2);
            document.getElementById('gcashReservation').textContent = reservationNumber;
            
            // Reset file inputs
            document.getElementById('payment_screenshot').value = '';
            document.getElementById('file-name').classList.remove('show');
            document.getElementById('image-preview').classList.remove('show');
            
            // Default to cash selected
            selectPaymentMethod('cash', {currentTarget: document.querySelector('.payment-method-card.cash')});
            
            document.getElementById('paymentModal').classList.add('active');
        }
        
        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
        }
        
        function selectPaymentMethod(method, event) {
            // Remove selected class from all methods
            document.querySelectorAll('.payment-method-card').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Add selected class to clicked method
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('selected');
            } else {
                // Fallback if called without event
                document.querySelector(`.payment-method-card.${method}`).classList.add('selected');
            }
            
            // Set hidden input value
            document.getElementById('selected_payment_method').value = method;
            
            // Show/hide relevant instruction sections
            document.getElementById('cashInstructions').style.display = 'none';
            document.getElementById('gcashInstructions').style.display = 'none';
            
            if (method === 'cash') {
                document.getElementById('cashInstructions').style.display = 'block';
                document.getElementById('payment_screenshot').removeAttribute('required');
            } else if (method === 'gcash') {
                document.getElementById('gcashInstructions').style.display = 'block';
                document.getElementById('payment_screenshot').setAttribute('required', 'required');
            }
        }
        
        function previewFile() {
            const file = document.getElementById('payment_screenshot').files[0];
            const fileNameSpan = document.getElementById('file-name-text');
            const fileNameDiv = document.getElementById('file-name');
            const preview = document.getElementById('image-preview');
            
            if (file) {
                fileNameSpan.textContent = file.name;
                fileNameDiv.classList.add('show');
                
                // Check if file is an image for preview
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.src = e.target.result;
                        preview.classList.add('show');
                    };
                    reader.readAsDataURL(file);
                } else {
                    // For non-image files (PDF), hide image preview
                    preview.classList.remove('show');
                }
            }
        }
        
        function validatePayment() {
            const method = document.getElementById('selected_payment_method').value;
            
            if (method === 'cash') {
                return confirm('Mark this as cash payment? Please proceed to the front desk to complete your payment.');
            } else if (method === 'gcash') {
                const file = document.getElementById('payment_screenshot').files[0];
                if (!file) {
                    alert('Please upload your GCash payment screenshot');
                    return false;
                }
                
                // Check file extension more thoroughly
                const fileName = file.name;
                const fileExt = fileName.split('.').pop().toLowerCase();
                const allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
                
                if (!allowedExt.includes(fileExt)) {
                    alert('Invalid file type. Allowed: JPG, JPEG, PNG, GIF, PDF. Your file: .' + fileExt);
                    return false;
                }
                
                if (file.size > 5242880) { // 5MB
                    alert('File size too large. Maximum 5MB allowed.');
                    return false;
                }
                
                return confirm('Submit GCash payment for verification?');
            }
            
            return false;
        }
        
        // Adjustment Modal Functions
        function openAdjustmentModal(reservationId, reservationNumber, checkIn, checkOut, adjustmentsUsed) {
            document.getElementById('adjustment_reservation_id').value = reservationId;
            document.getElementById('current_check_in').textContent = new Date(checkIn).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            document.getElementById('current_check_out').textContent = new Date(checkOut).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            document.getElementById('adjustments_used').textContent = adjustmentsUsed;
            
            document.getElementById('adjustmentInfo').innerHTML = `
                <p><strong>Reservation:</strong> ${reservationNumber}</p>
            `;
            
            // Set min dates for new check-in (today + 1 day)
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            const minDate = tomorrow.toISOString().split('T')[0];
            
            document.getElementById('new_check_in').min = minDate;
            document.getElementById('new_check_in').value = '';
            document.getElementById('new_check_out').value = '';
            
            // Add change event to calculate min check-out date
            document.getElementById('new_check_in').onchange = function() {
                const checkIn = new Date(this.value);
                const nextDay = new Date(checkIn);
                nextDay.setDate(nextDay.getDate() + 1);
                const minCheckOut = nextDay.toISOString().split('T')[0];
                
                document.getElementById('new_check_out').min = minCheckOut;
                document.getElementById('new_check_out').value = minCheckOut;
            };
            
            document.getElementById('adjustmentModal').classList.add('active');
        }
        
        function closeAdjustmentModal() {
            document.getElementById('adjustmentModal').classList.remove('active');
        }
        
        function validateAdjustment() {
            const checkIn = new Date(document.getElementById('new_check_in').value);
            const checkOut = new Date(document.getElementById('new_check_out').value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            // Check if dates are valid
            if (checkIn <= today) {
                alert('Check-in date must be in the future');
                return false;
            }
            
            if (checkOut <= checkIn) {
                alert('Check-out date must be after check-in date');
                return false;
            }
            
            // Check minimum stay (1 night)
            const diffTime = Math.abs(checkOut - checkIn);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            if (diffDays < 1) {
                alert('Minimum stay is 1 night');
                return false;
            }
            
            if (diffDays > 14) {
                alert('Maximum stay is 14 nights');
                return false;
            }
            
            return confirm('Submit date adjustment request for review?');
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert, .success-box, .error-box').forEach(el => {
                el.style.transition = 'opacity 0.5s';
                el.style.opacity = '0';
                setTimeout(() => el.style.display = 'none', 500);
            });
        }, 5000);
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const paymentModal = document.getElementById('paymentModal');
            const adjustmentModal = document.getElementById('adjustmentModal');
            
            if (event.target == paymentModal) {
                closePaymentModal();
            }
            if (event.target == adjustmentModal) {
                closeAdjustmentModal();
            }
        }
    </script>
    
    <!-- Include flatpickr for better date picking -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</body>
</html>