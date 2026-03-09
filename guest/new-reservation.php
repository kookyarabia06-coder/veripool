<?php
/**
 * Veripool Reservation System - Guest New Reservation Page
 * Create a new reservation with payment options requiring admin verification for e-wallets
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

// Check if screenshot column exists in payments table
$screenshot_column_exists = false;
try {
    $columns = $db->getRows("SHOW COLUMNS FROM payments LIKE 'screenshot'");
    $screenshot_column_exists = !empty($columns);
} catch (Exception $e) {
    $screenshot_column_exists = false;
}

// Check if reservation_pools table exists
$pools_table_exists = false;
try {
    $result = $db->getRows("SHOW TABLES LIKE 'reservation_pools'");
    $pools_table_exists = !empty($result);
} catch (Exception $e) {
    $pools_table_exists = false;
}

// Create upload directory if it doesn't exist
$upload_dir = BASE_PATH . '/uploads/payments/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Get entrance fee settings from database
$entrance_fees = $db->getRows("
    SELECT * FROM entrance_fees 
    WHERE status = 'active'
    ORDER BY 
        CASE fee_type 
            WHEN 'adult' THEN 1 
            WHEN 'senior' THEN 2 
            WHEN 'child' THEN 3 
            WHEN 'group' THEN 4 
        END
");

// Get available rooms
$available_rooms = $db->getRows("
    SELECT r.*, rt.name as room_type_name, rt.base_price, rt.max_occupancy, rt.description
    FROM rooms r
    JOIN room_types rt ON r.room_type_id = rt.id
    WHERE r.status = 'available'
    ORDER BY r.room_number
");

// Get available cottages
$available_cottages = $db->getRows("
    SELECT * FROM cottages 
    WHERE status = 'available'
    ORDER BY cottage_name
");

// Get both pools (Ernesto and Pavilion)
$pools = $db->getRows("SELECT * FROM pools ORDER BY FIELD(name, 'Ernesto', 'Pavilion')");

// Get all reservations for calendar
$all_reservations = $db->getRows("
    SELECT r.id, r.check_in_date, r.check_out_date, r.status, rm.room_number, c.cottage_name
    FROM reservations r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
    LEFT JOIN cottages c ON rc.cottage_id = c.id
    WHERE r.status IN ('confirmed', 'checked_in', 'pending')
    ORDER BY r.check_in_date
");

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

// Handle form submission with payment
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_reservation') {
    $check_in_date = sanitize($_POST['check_in_date']);
    $check_out_date = sanitize($_POST['check_out_date']);
    $adults = (int)$_POST['adults'];
    $children = (int)$_POST['children'];
    $seniors = isset($_POST['seniors']) ? (int)$_POST['seniors'] : 0;
    $room_id = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
    $cottage_id = !empty($_POST['cottage_id']) ? (int)$_POST['cottage_id'] : null;
    
    // Get selected pools
    $selected_pools = isset($_POST['pools']) ? $_POST['pools'] : [];
    
    // Payment options - only full and downpayment available
    $payment_type = sanitize($_POST['payment_type']); // 'full' or 'downpayment'
    $payment_method = isset($_POST['payment_method']) ? sanitize($_POST['payment_method']) : 'cash';
    $downpayment_amount = isset($_POST['downpayment_amount']) ? (float)$_POST['downpayment_amount'] : 0;
    
    // Calculate nights
    $nights = ceil((strtotime($check_out_date) - strtotime($check_in_date)) / (60 * 60 * 24));
    
    // Calculate accommodation total
    $accommodation_total = 0;
    
    if ($room_id) {
        $room = $db->getRow("
            SELECT rt.base_price 
            FROM rooms r
            JOIN room_types rt ON r.room_type_id = rt.id
            WHERE r.id = ?
        ", [$room_id]);
        if ($room) {
            $accommodation_total += $room['base_price'] * $nights;
        }
    }
    
    if ($cottage_id) {
        $cottage = $db->getRow("SELECT price FROM cottages WHERE id = ?", [$cottage_id]);
        if ($cottage) {
            $accommodation_total += $cottage['price'] * $nights;
        }
    }
    
    // Calculate entrance fee from database
    $entrance_fee_total = 0;
    $total_guests = $adults + $children + $seniors;
    
    // Get fee amounts
    $adult_fee = 0;
    $child_fee = 0;
    $senior_fee = 0;
    
    foreach ($entrance_fees as $fee) {
        if ($fee['fee_type'] == 'adult') $adult_fee = $fee['amount'];
        if ($fee['fee_type'] == 'child') $child_fee = $fee['amount'];
        if ($fee['fee_type'] == 'senior') $senior_fee = $fee['amount'];
    }
    
    $entrance_fee_total = ($adults * $adult_fee) + 
                          ($children * $child_fee) + 
                          ($seniors * $senior_fee);
    
    // Calculate total amount (accommodation + entrance fee)
    $total_amount = $accommodation_total + $entrance_fee_total;
    
    // Determine initial payment and status based on payment method
    $initial_payment = 0;
    $payment_status = 'pending';
    $reservation_status = 'pending';
    
    if ($payment_type === 'full') {
        $initial_payment = $total_amount;
    } else if ($payment_type === 'downpayment') {
        $initial_payment = $downpayment_amount;
    }
    
    if ($payment_method === 'cash') {
        $payment_status = 'completed';
        $reservation_status = 'confirmed';
    } else {
        $payment_status = 'pending';
        $reservation_status = 'pending';
    }
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Generate reservation number and OTP
        $reservation_number = 'RES' . date('Ymd') . rand(1000, 9999);
        $otp_code = generateOTP();
        
        // Determine reservation type
        $reservation_type = 'overnight';
        if ($nights == 0 || ($check_in_date == $check_out_date)) {
            $reservation_type = 'daytour';
        } elseif ($cottage_id && !$room_id && $nights == 1) {
            $reservation_type = 'daytour_with_cottage';
        }
        
        // Create reservation with entrance fee details
        $reservation_data = [
            'reservation_number' => $reservation_number,
            'user_id' => $user['id'],
            'room_id' => $room_id,
            'check_in_date' => $check_in_date,
            'check_out_date' => $check_out_date,
            'adults' => $adults,
            'children' => $children,
            'seniors' => $seniors,
            'total_guests' => $total_guests,
            'accommodation_total' => $accommodation_total,
            'entrance_fee_total' => $entrance_fee_total,
            'total_amount' => $total_amount,
            'amount_paid' => $initial_payment,
            'status' => $reservation_status,
            'reservation_type' => $reservation_type,
            'has_entrance_fee' => 1,
            'entrance_fee_amount' => $entrance_fee_total,
            'entrance_fee_paid' => ($payment_method === 'cash' && $payment_type !== 'later') ? $entrance_fee_total : 0,
            'entrance_fee_guests' => $total_guests,
            'otp_code' => $otp_code,
            'created_by' => 'online',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $reservation_id = $db->insert('reservations', $reservation_data);
        
        if (!$reservation_id) {
            throw new Exception("Failed to create reservation");
        }
        
        // If cottage was selected, add cottage booking
        if ($cottage_id) {
            $cottage = $db->getRow("SELECT price FROM cottages WHERE id = ?", [$cottage_id]);
            
            $cottage_data = [
                'reservation_id' => $reservation_id,
                'cottage_id' => $cottage_id,
                'quantity' => 1,
                'price_at_time' => $cottage['price'],
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $cottage_booking_id = $db->insert('reservation_cottages', $cottage_data);
            
            if (!$cottage_booking_id) {
                throw new Exception("Failed to add cottage booking");
            }
        }
        
        // Add pool selections if the table exists
        if ($pools_table_exists && !empty($selected_pools)) {
            foreach ($selected_pools as $pool_id) {
                $pool_data = [
                    'reservation_id' => $reservation_id,
                    'pool_id' => (int)$pool_id,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $db->insert('reservation_pools', $pool_data);
            }
        }
        
        // Record entrance fee payment if paid
        if ($entrance_fee_total > 0 && $payment_method === 'cash' && $payment_type !== 'later') {
            $fee_breakdown = json_encode([
                'adults' => $adults,
                'children' => $children,
                'seniors' => $seniors,
                'adult_fee' => $adult_fee,
                'child_fee' => $child_fee,
                'senior_fee' => $senior_fee
            ]);
            
            $fee_payment_data = [
                'reservation_id' => $reservation_id,
                'fee_id' => 1, // You might need to get the actual fee ID
                'guest_type' => 'all',
                'number_of_guests' => $total_guests,
                'nights' => $nights,
                'amount_per_night' => $adult_fee, // Average fee per person
                'total_amount' => $entrance_fee_total,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $db->insert('entrance_fee_payments', $fee_payment_data);
        }
        
        // Handle payment if any
        if ($initial_payment > 0) {
            $payment_number = 'PAY' . date('Ymd') . rand(1000, 9999);
            
            $payment_data = [
                'payment_number' => $payment_number,
                'reservation_id' => $reservation_id,
                'amount' => $initial_payment,
                'payment_method' => $payment_method,
                'payment_status' => $payment_status,
                'created_by' => $user['id'],
                'payment_date' => date('Y-m-d H:i:s'),
                'notes' => $payment_type === 'downpayment' ? 'Downpayment at booking' : 'Full payment at booking'
            ];
            
            // Handle screenshot upload for GCash payments only - save in screenshot column
            if ($payment_method === 'gcash' && isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['payment_screenshot'];
                $file_name = $file['name'];
                $file_tmp = $file['tmp_name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                // Generate unique filename
                $new_file_name = 'payment_' . $reservation_id . '_' . time() . '.' . $file_ext;
                $file_destination = $upload_dir . $new_file_name;
                
                if (move_uploaded_file($file_tmp, $file_destination)) {
                    // Store only the filename in the screenshot column (not full path for security)
                    $payment_data['screenshot'] = $new_file_name;
                }
            }
            
            $payment_id = $db->insert('payments', $payment_data);
            
            if (!$payment_id) {
                throw new Exception("Failed to record payment");
            }
        }
        
        // Update room status if room was assigned and payment is cash (confirmed)
        if ($room_id && $payment_method === 'cash' && $payment_type !== 'later') {
            $db->update('rooms', 
                ['status' => 'occupied'], 
                'id = :id', 
                ['id' => $room_id]
            );
        }
        
        // Update cottage status if cottage was assigned and payment is cash (confirmed)
        if ($cottage_id && $payment_method === 'cash' && $payment_type !== 'later') {
            $db->update('cottages', 
                ['status' => 'occupied'], 
                'id = :id', 
                ['id' => $cottage_id]
            );
        }
        
        $db->commit();
        
        // Set appropriate message based on payment method
        if ($payment_method === 'cash') {
            $_SESSION['message'] = "Reservation created successfully! Your reservation is confirmed. Please proceed to the front desk for check-in.";
            $_SESSION['message_type'] = 'success';
        } else if ($payment_method === 'gcash') {
            $_SESSION['message'] = "Reservation created successfully! Your GCash payment is pending verification. We will confirm your reservation once the payment is verified.";
            $_SESSION['message_type'] = 'success';
        }
        
        header("Location: new-reservation.php?success=1");
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        $message = "Failed to create reservation: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == 1 && isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Reservation - Veripool Resort</title>
    
    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>/assets/favicon/site.webmanifest">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
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
            cursor: pointer;
        }
        
        .calendar-day:hover {
            background: #e9ecef;
        }
        
        .calendar-day.empty {
            background: transparent;
            cursor: default;
        }
        
        .calendar-day.today {
            border: 2px solid #1679AB;
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
        
        .calendar-legend {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }
        
        .form-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .form-section h3 {
            color: #102C57;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-section h3 i {
            color: #1679AB;
        }
        
        .form-section.pool-section {
            background: #FFF0F0;
            border-left: 4px solid #FFB1B1;
        }
        
        .form-section.pool-section h3 {
            color: #102C57;
        }
        
        .form-section.pool-section h3 i {
            color: #FFB1B1;
        }
        
        .form-section.entrance-section {
            background: #FFF0F0;
            border-left: 4px solid #FFB1B1;
        }
        
        .form-section.entrance-section h3 i {
            color: #FFB1B1;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row.three-col {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #102C57;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #1679AB;
            outline: none;
            box-shadow: 0 0 0 3px rgba(22,121,171,0.1);
        }
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
        }
        
        .radio-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            gap: 5px;
            background: white;
            padding: 8px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .radio-option:hover {
            border-color: #1679AB;
        }
        
        .radio-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .radio-option.selected {
            background: #e8f4fd;
            border-color: #1679AB;
        }
        
        /* Pool Selection */
        .pool-checkbox-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 10px;
        }
        
        .pool-checkbox {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .pool-checkbox:hover {
            border-color: #1679AB;
        }
        
        .pool-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .pool-checkbox.ernesto {
            border-left: 4px solid #9b59b6;
        }
        
        .pool-checkbox.pavilion {
            border-left: 4px solid #3498db;
        }
        
        .pool-checkbox.selected {
            background: #e8f4fd;
            border-color: #1679AB;
        }
        
        .pool-checkbox .pool-name {
            font-weight: bold;
            color: #102C57;
        }
        
        .pool-checkbox .pool-type {
            font-size: 0.8rem;
            color: #666;
        }
        
        /* Payment Options */
        .payment-options {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .payment-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .payment-tab {
            flex: 1;
            min-width: 120px;
            padding: 12px;
            text-align: center;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .payment-tab:hover {
            border-color: #1679AB;
        }
        
        .payment-tab.active {
            background: #1679AB;
            border-color: #1679AB;
            color: white;
        }
        
        .payment-tab i {
            margin-right: 8px;
        }
        
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        
        .payment-method-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
        }
        
        .payment-method-card:hover {
            border-color: #1679AB;
            transform: translateY(-2px);
        }
        
        .payment-method-card.selected {
            border-color: #1679AB;
            background: #f0f8ff;
        }
        
        .payment-method-card i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .payment-method-card.cash i { color: #28a745; }
        .payment-method-card.gcash i { color: #0066ff; }
        
        .payment-method-card .method-name {
            font-weight: bold;
            color: #102C57;
        }
        
        .downpayment-section {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 10px;
        }
        
        .downpayment-section.show {
            display: block;
        }
        
        .downpayment-slider {
            margin: 20px 0;
        }
        
        .downpayment-slider input[type="range"] {
            width: 100%;
            height: 8px;
            border-radius: 4px;
            background: #e0e0e0;
            outline: none;
            -webkit-appearance: none;
        }
        
        .downpayment-slider input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: #1679AB;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .downpayment-values {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .selected-amount {
            background: #102C57;
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin: 15px 0;
        }
        
        .selected-amount .label {
            color: #FFCBCB;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .selected-amount .value {
            font-size: 2rem;
            font-weight: bold;
            color: #FFB1B1;
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
        
        .file-input-wrapper {
            margin: 15px 0;
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
        
        /* Entrance Fee Section */
        .entrance-fee-info {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }
        
        .entrance-fee-info h4 {
            margin: 0 0 10px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .entrance-fee-info h4 i {
            color: #FFD700;
        }
        
        .fee-breakdown {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }
        
        .fee-item {
            flex: 1;
            min-width: 120px;
        }
        
        .fee-item .label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .fee-item .amount {
            font-size: 1.3rem;
            font-weight: bold;
            display: block;
        }
        
        .fee-item .note {
            font-size: 0.8rem;
            opacity: 0.8;
        }
        
        .fee-summary {
            background: #e8f4f8;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .fee-summary table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .fee-summary td {
            padding: 5px;
        }
        
        .fee-summary td:last-child {
            text-align: right;
            font-weight: bold;
        }
        
        /* Total Display */
        .total-display {
            background: linear-gradient(135deg, #102C57, #1679AB);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .total-breakdown {
            border-bottom: 1px solid rgba(255,255,255,0.2);
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        
        .breakdown-row.total {
            font-size: 1.5rem;
            font-weight: bold;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid rgba(255,255,255,0.3);
        }
        
        .breakdown-row .label {
            color: #FFCBCB;
        }
        
        .breakdown-row .value {
            color: #FFB1B1;
            font-weight: 600;
        }
        
        .breakdown-row.total .label,
        .breakdown-row.total .value {
            color: white;
        }
        
        .total-display .breakdown-detail {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: #FFCBCB;
            margin-top: 10px;
        }
        
        .btn-submit {
            background: #28a745;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1.2rem;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s;
        }
        
        .btn-submit:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40,167,69,0.3);
        }
        
        .btn-submit i {
            margin-right: 10px;
        }
        
        .account-details {
            background: #e7f3ff;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            font-size: 0.95rem;
        }
        
        .account-details p {
            margin: 5px 0;
        }
        
        .note-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px;
            border-radius: 5px;
            margin: 15px 0;
            font-size: 0.9rem;
        }
        
        .full-payment-badge {
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .payment-method-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #e9ecef;
            color: #102C57;
        }
        
        .payment-full {
            background: #28a745;
            color: white;
        }
        
        .payment-pending {
            background: #ffc107;
            color: #102C57;
        }
        
        .db-warning {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        
        .db-warning.info {
            background: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-row.three-col {
                grid-template-columns: 1fr;
            }
            
            .payment-methods {
                grid-template-columns: 1fr;
            }
            
            .payment-tabs {
                flex-direction: column;
            }
            
            .pool-checkbox-group {
                grid-template-columns: 1fr;
            }
            
            .calendar-grid {
                font-size: 0.8rem;
            }
            
            .calendar-day {
                min-height: 60px;
                padding: 5px;
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
    
   =
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <h1>
                <i class="fas fa-plus-circle"></i>
                New Reservation
            </h1>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
            </div>
        </div>
        
        <?php if (!$screenshot_column_exists): ?>
        <div class="db-warning info">
            <i class="fas fa-info-circle"></i>
            <strong>Note:</strong> Please run the following SQL to add screenshot column to payments table:
            <pre>ALTER TABLE payments ADD COLUMN screenshot VARCHAR(255) DEFAULT NULL AFTER payment_status;</pre>
        </div>
        <?php endif; ?>
        
        <?php if (!$pools_table_exists): ?>
        <div class="db-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Note:</strong> Pool selection is currently unavailable.
        </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Availability Calendar -->
        <div class="calendar-section">
            <div class="calendar-header">
                <h3><i class="fas fa-calendar-alt"></i> Availability Calendar - <?php echo $month_name; ?></h3>
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
                    $is_today = ($current_date == date('Y-m-d')) ? 'today' : '';
                    
                    // Find reservations for this date
                    $day_reservations = [];
                    foreach ($all_reservations as $res) {
                        $check_in = $res['check_in_date'];
                        $check_out = $res['check_out_date'];
                        
                        if ($current_date >= $check_in && $current_date < $check_out) {
                            $day_reservations[] = $res;
                        }
                    }
                    
                    echo '<div class="calendar-day ' . $is_today . '" onclick="selectDate(' . $day . ')">';
                    echo '<div class="day-number">' . $day . '</div>';
                    
                    foreach ($day_reservations as $booking) {
                        $status_class = 'booking-' . $booking['status'];
                        $info = $booking['room_number'] ? 'Room ' . $booking['room_number'] : ($booking['cottage_name'] ?? 'Booked');
                        echo '<div class="booking-indicator ' . $status_class . '" title="' . $info . '">';
                        echo '<i class="fas fa-circle"></i> ' . $info;
                        echo '</div>';
                    }
                    
                    echo '</div>';
                }
                ?>
            </div>
            
            <div class="calendar-legend">
                <div class="legend-item">
                    <div class="legend-color" style="background: #d4edda;"></div>
                    <span>Confirmed</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #fff3cd;"></div>
                    <span>Pending</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #cce5ff;"></div>
                    <span>Checked In</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #f8f9fa; border: 2px solid #1679AB;"></div>
                    <span>Today</span>
                </div>
            </div>
        </div>
        
        <!-- Reservation Form -->
        <form method="POST" enctype="multipart/form-data" id="reservationForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="create_reservation">
            
            <!-- Stay Details -->
            <div class="form-section">
                <h3><i class="fas fa-calendar-alt"></i> Stay Details</h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="check_in_date">Check-in Date *</label>
                        <input type="date" name="check_in_date" id="check_in_date" class="form-control" 
                               min="<?php echo date('Y-m-d'); ?>" required onchange="updateDates(); calculateTotal()">
                    </div>
                    
                    <div class="form-group">
                        <label for="check_out_date">Check-out Date *</label>
                        <input type="date" name="check_out_date" id="check_out_date" class="form-control" 
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required onchange="calculateTotal()">
                    </div>
                </div>
                
                <div class="form-row three-col">
                    <div class="form-group">
                        <label for="adults">Adults *</label>
                        <input type="number" name="adults" id="adults" class="form-control" min="1" value="1" required onchange="calculateTotal()">
                    </div>
                    
                    <div class="form-group">
                        <label for="children">Children</label>
                        <input type="number" name="children" id="children" class="form-control" min="0" value="0" onchange="calculateTotal()">
                    </div>
                    
                    <div class="form-group">
                        <label for="seniors">Seniors</label>
                        <input type="number" name="seniors" id="seniors" class="form-control" min="0" value="0" onchange="calculateTotal()">
                    </div>
                </div>
            </div>
            
            <!-- Accommodation Selection -->
            <div class="form-section">
                <h3><i class="fas fa-bed"></i> Select Accommodation</h3>
                
                <div class="radio-group">
                    <label class="radio-option">
                        <input type="radio" name="booking_type" value="room" checked onchange="toggleBookingType()"> Room Only
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="booking_type" value="cottage" onchange="toggleBookingType()"> Cottage Only
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="booking_type" value="both" onchange="toggleBookingType()"> Room + Cottage
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="booking_type" value="daytour_cottage" onchange="toggleBookingType()"> Day Tour + Cottage
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="booking_type" value="daytour" onchange="toggleBookingType()"> Day Tour Only
                    </label>
                </div>
                
                <div id="room_selection" class="form-group">
                    <label for="room_id">Select Room</label>
                    <select name="room_id" id="room_id" class="form-control" onchange="calculateTotal()">
                        <option value="">-- Select Room --</option>
                        <?php foreach ($available_rooms as $room): ?>
                        <option value="<?php echo $room['id']; ?>" 
                                data-price="<?php echo $room['base_price']; ?>"
                                data-capacity="<?php echo $room['max_occupancy']; ?>">
                            Room <?php echo $room['room_number']; ?> - 
                            <?php echo $room['room_type_name']; ?> - 
                            ₱<?php echo number_format($room['base_price'], 2); ?>/night
                            (Max <?php echo $room['max_occupancy']; ?> guests)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="cottage_selection" class="form-group" style="display: none;">
                    <label for="cottage_id">Select Cottage</label>
                    <select name="cottage_id" id="cottage_id" class="form-control" onchange="calculateTotal()">
                        <option value="">-- Select Cottage --</option>
                        <?php foreach ($available_cottages as $cottage): ?>
                        <option value="<?php echo $cottage['id']; ?>" 
                                data-price="<?php echo $cottage['price']; ?>"
                                data-capacity="<?php echo $cottage['capacity']; ?>">
                            <?php echo htmlspecialchars($cottage['cottage_name']); ?> - 
                            <?php echo ucfirst($cottage['cottage_type']); ?> - 
                            ₱<?php echo number_format($cottage['price'], 2); ?>/day
                            (Max <?php echo $cottage['capacity']; ?> guests)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Pool Selection Section -->
            <div class="form-section pool-section">
                <h3><i class="fas fa-swimmer"></i> Select Pool(s) to Use</h3>
                <p style="font-size: 0.85rem; color: #666; margin-bottom: 10px;">You can use both pools during your stay</p>
                
                <div class="pool-checkbox-group">
                    <?php foreach ($pools as $pool): ?>
                    <label class="pool-checkbox <?php echo strtolower($pool['name']); ?>">
                        <input type="checkbox" name="pools[]" value="<?php echo $pool['id']; ?>" onchange="togglePoolSelection(this)">
                        <div>
                            <div class="pool-name">
                                <?php echo htmlspecialchars($pool['name']); ?> Pool
                                <?php if ($pool['name'] == 'Ernesto'): ?>
                                    <i class="fas fa-star" style="color: #ffc107; font-size: 0.8rem;"></i>
                                <?php endif; ?>
                            </div>
                            <div class="pool-type"><?php echo ucfirst($pool['type']); ?> Pool</div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Entrance Fee Section -->
            <div class="form-section entrance-section">
                <h3><i class="fas fa-ticket-alt"></i> Entrance Fee</h3>
                <p style="font-size: 0.85rem; color: #666; margin-bottom: 10px;">Entrance fee is required for all guests</p>
                
                <div class="fee-summary">
                    <table>
                        <?php 
                        $adult_fee = 0;
                        $child_fee = 0;
                        $senior_fee = 0;
                        foreach ($entrance_fees as $fee): 
                            if ($fee['fee_type'] == 'adult') $adult_fee = $fee['amount'];
                            if ($fee['fee_type'] == 'child') $child_fee = $fee['amount'];
                            if ($fee['fee_type'] == 'senior') $senior_fee = $fee['amount'];
                        ?>
                        <tr>
                            <td><?php echo ucfirst($fee['fee_type']); ?> (₱<?php echo number_format($fee['amount'], 2); ?> each)</td>
                            <td id="<?php echo $fee['fee_type']; ?>_count_display">0</td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="border-top: 1px solid #ccc;">
                            <td><strong>Total Entrance Fee</strong></td>
                            <td><strong id="entrance_fee_total">₱0.00</strong></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Payment Options -->
            <div class="form-section">
                <h3><i class="fas fa-credit-card"></i> Payment Options</h3>
                
                <div class="payment-options">
                    <div class="payment-tabs">
                        <div class="payment-tab active" onclick="selectPaymentType('full')">
                            <i class="fas fa-check-circle"></i> Pay in Full
                        </div>
                        <div class="payment-tab" onclick="selectPaymentType('downpayment')">
                            <i class="fas fa-percent"></i> Pay Downpayment
                        </div>
                    </div>
                    
                    <input type="hidden" name="payment_type" id="payment_type" value="full">
                    
                    <!-- Payment Methods -->
                    <div id="paymentMethodsSection">
                        <h4 style="color: #102C57; margin: 20px 0 15px;">Select Payment Method</h4>
                        
                        <div class="payment-methods">
                            <div class="payment-method-card cash selected" onclick="selectPaymentMethod('cash')">
                                <i class="fas fa-money-bill-wave"></i>
                                <div class="method-name">Cash</div>
                            </div>
                            
                            <div class="payment-method-card gcash" onclick="selectPaymentMethod('gcash')">
                                <i class="fas fa-mobile-alt"></i>
                                <div class="method-name">GCash</div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="payment_method" id="selected_payment_method" value="cash">
                    </div>
                    
                    <!-- Cash Instructions -->
                    <div class="account-details" id="cashInstructions">
                        <p><i class="fas fa-info-circle"></i> <strong>Cash Payment:</strong> Proceed to the front desk to complete your payment. Your reservation will be confirmed upon payment.</p>
                    </div>
                    
                    <!-- GCash Instructions with Upload -->
                    <div class="account-details" id="gcashInstructions" style="display: none;">
                        <p><i class="fas fa-mobile-alt"></i> <strong>GCash Number:</strong> 0999-123-4567</p>
                        <p><i class="fas fa-user"></i> <strong>Account Name:</strong> Veripool Resort</p>
                        
                        <div class="upload-section">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <h5>Upload Payment Screenshot</h5>
                            <p>Please upload a screenshot of your GCash transaction as proof of payment.</p>
                            
                            <div class="file-input-wrapper">
                                <input type="file" name="payment_screenshot" id="payment_screenshot" accept="image/*,.pdf" onchange="previewFile()">
                                <label for="payment_screenshot" class="file-input-label">
                                    <i class="fas fa-camera"></i> Choose File
                                </label>
                            </div>
                            
                            <div class="file-name" id="file-name">
                                <i class="fas fa-check-circle"></i> <span id="file-name-text"></span>
                            </div>
                            
                            <img class="preview-image" id="image-preview" alt="Preview">
                        </div>
                    </div>
                    
                    <!-- Downpayment Section -->
                    <div class="downpayment-section" id="downpaymentSection">
                        <h4 style="color: #102C57; margin-bottom: 15px;">Downpayment Amount</h4>
                        
                        <div class="selected-amount">
                            <div class="label">Downpayment Amount</div>
                            <div class="value" id="downpaymentAmount">₱0.00</div>
                        </div>
                        
                        <div class="downpayment-slider">
                            <input type="range" id="downpaymentRange" min="10" max="100" value="50" step="5" onchange="updateDownpayment()">
                        </div>
                        
                        <div class="downpayment-values">
                            <span>10%</span>
                            <span>25%</span>
                            <span>50%</span>
                            <span>75%</span>
                            <span>100%</span>
                        </div>
                        
                        <p style="font-size: 0.9rem; color: #666; margin-top: 15px;">
                            <i class="fas fa-info-circle"></i> Choose your downpayment percentage. The remaining balance can be paid later.
                        </p>
                        
                        <input type="hidden" name="downpayment_amount" id="downpayment_amount" value="0">
                    </div>
                </div>
                
                <!-- Total Amount Display with Breakdown -->
                <div class="total-display">
                    <div class="total-breakdown">
                        <div class="breakdown-row">
                            <span class="label">Accommodation:</span>
                            <span class="value" id="accommodation_total_display">₱0.00</span>
                        </div>
                        <div class="breakdown-row">
                            <span class="label">Entrance Fee:</span>
                            <span class="value" id="entrance_fee_display">₱0.00</span>
                        </div>
                        <div class="breakdown-row total">
                            <span class="label">Total Amount:</span>
                            <span class="value" id="total_amount_display">₱0.00</span>
                        </div>
                    </div>
                    <div class="breakdown-detail">
                        <span id="nights_display">0 nights</span>
                        <span id="guests_display">0 guests</span>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <button type="submit" class="btn-submit">
                    <i class="fas fa-check-circle"></i> Create Reservation
                </button>
            </div>
        </form>
    </div>
    
    <script>
        let accommodationTotal = 0;
        let entranceFeeTotal = 0;
        let totalAmount = 0;
        let nights = 0;
        
        // Entrance fee rates from database
        const adultFee = <?php echo $adult_fee ?? 50; ?>;
        const childFee = <?php echo $child_fee ?? 25; ?>;
        const seniorFee = <?php echo $senior_fee ?? 40; ?>;
        
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }
        
        function togglePoolSelection(checkbox) {
            const label = checkbox.closest('.pool-checkbox');
            if (checkbox.checked) {
                label.classList.add('selected');
            } else {
                label.classList.remove('selected');
            }
        }
        
        function toggleBookingType() {
            const type = document.querySelector('input[name="booking_type"]:checked').value;
            const roomSelection = document.getElementById('room_selection');
            const cottageSelection = document.getElementById('cottage_selection');
            
            // Update radio option styling
            document.querySelectorAll('.radio-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.querySelector('input[name="booking_type"]:checked').closest('.radio-option').classList.add('selected');
            
            if (type === 'room') {
                roomSelection.style.display = 'block';
                cottageSelection.style.display = 'none';
                document.getElementById('cottage_id').value = '';
            } else if (type === 'cottage' || type === 'daytour_cottage') {
                roomSelection.style.display = 'none';
                cottageSelection.style.display = 'block';
                document.getElementById('room_id').value = '';
                
                // For day tour + cottage, set check-out to same day as check-in
                if (type === 'daytour_cottage') {
                    const checkIn = document.getElementById('check_in_date').value;
                    if (checkIn) {
                        document.getElementById('check_out_date').value = checkIn;
                        document.getElementById('check_out_date').min = checkIn;
                    }
                }
            } else if (type === 'both') {
                roomSelection.style.display = 'block';
                cottageSelection.style.display = 'block';
            } else { // daytour
                roomSelection.style.display = 'none';
                cottageSelection.style.display = 'none';
                document.getElementById('room_id').value = '';
                document.getElementById('cottage_id').value = '';
                
                // For day tour, set check-out to same day as check-in
                const checkIn = document.getElementById('check_in_date').value;
                if (checkIn) {
                    document.getElementById('check_out_date').value = checkIn;
                    document.getElementById('check_out_date').min = checkIn;
                }
            }
            
            calculateTotal();
        }
        
        function updateDates() {
            const checkIn = document.getElementById('check_in_date').value;
            const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
            
            if (checkIn) {
                if (bookingType === 'daytour' || bookingType === 'daytour_cottage') {
                    document.getElementById('check_out_date').value = checkIn;
                    document.getElementById('check_out_date').min = checkIn;
                } else {
                    const nextDay = new Date(checkIn);
                    nextDay.setDate(nextDay.getDate() + 1);
                    document.getElementById('check_out_date').value = nextDay.toISOString().split('T')[0];
                    document.getElementById('check_out_date').min = nextDay.toISOString().split('T')[0];
                }
            }
        }
        
        function calculateTotal() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const roomSelect = document.getElementById('room_id');
            const cottageSelect = document.getElementById('cottage_id');
            const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
            
            let accommodation = 0;
            
            if (checkIn && checkOut) {
                if (bookingType === 'daytour' || bookingType === 'daytour_cottage') {
                    nights = 1;
                } else {
                    nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24));
                    if (nights < 1) nights = 1;
                }
                
                document.getElementById('nights_display').innerText = nights + (nights > 1 ? ' nights' : ' night');
                
                if (roomSelect && roomSelect.value) {
                    const price = roomSelect.options[roomSelect.selectedIndex]?.dataset.price;
                    if (price) accommodation += parseFloat(price) * nights;
                }
                
                if (cottageSelect && cottageSelect.value) {
                    const price = cottageSelect.options[cottageSelect.selectedIndex]?.dataset.price;
                    if (price) accommodation += parseFloat(price) * nights;
                }
            }
            
            const adults = parseInt(document.getElementById('adults').value) || 0;
            const children = parseInt(document.getElementById('children').value) || 0;
            const seniors = parseInt(document.getElementById('seniors').value) || 0;
            
            const entranceFee = (adults * adultFee) + (children * childFee) + (seniors * seniorFee);
            
            // Update displays
            document.getElementById('adult_count_display').innerText = adults;
            document.getElementById('child_count_display').innerText = children;
            document.getElementById('senior_count_display').innerText = seniors;
            document.getElementById('entrance_fee_total').innerText = '₱' + entranceFee.toFixed(2);
            
            const total = accommodation + entranceFee;
            
            document.getElementById('accommodation_total_display').innerText = '₱' + accommodation.toFixed(2);
            document.getElementById('entrance_fee_display').innerText = '₱' + entranceFee.toFixed(2);
            document.getElementById('total_amount_display').innerText = '₱' + total.toFixed(2);
            
            document.getElementById('guests_display').innerText = (adults + children + seniors) + ' guests';
            
            accommodationTotal = accommodation;
            entranceFeeTotal = entranceFee;
            totalAmount = total;
            
            updateDownpayment();
        }
        
        function selectDate(day) {
            const year = <?php echo $current_year; ?>;
            const month = <?php echo $current_month; ?>;
            const selectedDate = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            document.getElementById('check_in_date').value = selectedDate;
            
            const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
            if (bookingType === 'daytour' || bookingType === 'daytour_cottage') {
                document.getElementById('check_out_date').value = selectedDate;
                document.getElementById('check_out_date').min = selectedDate;
            } else {
                const nextDay = new Date(year, month - 1, day + 1);
                const nextDayStr = nextDay.toISOString().split('T')[0];
                document.getElementById('check_out_date').value = nextDayStr;
                document.getElementById('check_out_date').min = nextDayStr;
            }
            
            calculateTotal();
        }
        
        function selectPaymentType(type) {
            // Update tabs
            document.querySelectorAll('.payment-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
            
            // Set hidden input
            document.getElementById('payment_type').value = type;
            
            // Show/hide relevant sections
            const paymentMethods = document.getElementById('paymentMethodsSection');
            const downpaymentSection = document.getElementById('downpaymentSection');
            
            if (type === 'full' || type === 'downpayment') {
                paymentMethods.style.display = 'block';
                if (type === 'downpayment') {
                    downpaymentSection.classList.add('show');
                } else {
                    downpaymentSection.classList.remove('show');
                }
            } else {
                paymentMethods.style.display = 'none';
                downpaymentSection.classList.remove('show');
            }
        }
        
        function selectPaymentMethod(method) {
            // Remove selected class from all methods
            document.querySelectorAll('.payment-method-card').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Add selected class to clicked method
            event.currentTarget.classList.add('selected');
            
            // Set hidden input
            document.getElementById('selected_payment_method').value = method;
            
            // Show/hide instructions
            document.getElementById('cashInstructions').style.display = 'none';
            document.getElementById('gcashInstructions').style.display = 'none';
            
            if (method === 'cash') {
                document.getElementById('cashInstructions').style.display = 'block';
            } else if (method === 'gcash') {
                document.getElementById('gcashInstructions').style.display = 'block';
            }
        }
        
        function updateDownpayment() {
            const percentage = parseInt(document.getElementById('downpaymentRange').value);
            const downpaymentAmount = (totalAmount * percentage) / 100;
            
            document.getElementById('downpaymentAmount').innerText = '₱' + downpaymentAmount.toFixed(2);
            document.getElementById('downpayment_amount').value = downpaymentAmount.toFixed(2);
        }
        
        function previewFile() {
            const file = document.getElementById('payment_screenshot').files[0];
            const fileNameSpan = document.getElementById('file-name-text');
            const fileNameDiv = document.getElementById('file-name');
            const preview = document.getElementById('image-preview');
            
            if (file) {
                fileNameSpan.textContent = file.name;
                fileNameDiv.classList.add('show');
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.add('show');
                };
                reader.readAsDataURL(file);
            }
        }
        
        function validateForm() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
            const roomId = document.getElementById('room_id').value;
            const cottageId = document.getElementById('cottage_id').value;
            const paymentType = document.getElementById('payment_type').value;
            const paymentMethod = document.getElementById('selected_payment_method').value;
            
            if (!checkIn || !checkOut) {
                alert('Please select check-in and check-out dates');
                return false;
            }
            
            if (bookingType === 'room' && !roomId) {
                alert('Please select a room');
                return false;
            }
            
            if (bookingType === 'cottage' && !cottageId) {
                alert('Please select a cottage');
                return false;
            }
            
            if (bookingType === 'daytour_cottage' && !cottageId) {
                alert('Please select a cottage for your day tour');
                return false;
            }
            
            if (bookingType === 'both' && (!roomId || !cottageId)) {
                alert('Please select both a room and a cottage');
                return false;
            }
            
            const adults = parseInt(document.getElementById('adults').value);
            if (adults < 1) {
                alert('Please enter at least one adult');
                return false;
            }
            
            // Validate GCash payment screenshot
            if (paymentMethod === 'gcash') {
                const file = document.getElementById('payment_screenshot').files[0];
                if (!file) {
                    alert('Please upload your GCash payment screenshot');
                    return false;
                }
            }
            
            // Show summary of charges
            let confirmMessage = 'RESERVATION SUMMARY:\n';
            confirmMessage += `Accommodation: ₱${accommodationTotal.toFixed(2)}\n`;
            confirmMessage += `Entrance Fee: ₱${entranceFeeTotal.toFixed(2)}\n`;
            confirmMessage += `Total Amount: ₱${totalAmount.toFixed(2)}\n\n`;
            
            if (paymentType === 'full') {
                confirmMessage += 'Confirm reservation with FULL PAYMENT?';
            } else if (paymentType === 'downpayment') {
                const downpayment = document.getElementById('downpayment_amount').value;
                confirmMessage += `Confirm reservation with DOWNPAYMENT of ₱${parseFloat(downpayment).toFixed(2)}?\n`;
                confirmMessage += `Remaining balance: ₱${(totalAmount - parseFloat(downpayment)).toFixed(2)}`;
            }
            
            return confirm(confirmMessage);
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('check_in_date').min = today;
            
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('check_out_date').min = tomorrow.toISOString().split('T')[0];
            
            // Initialize radio button styling
            document.querySelectorAll('.radio-option').forEach(option => {
                const radio = option.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    option.classList.add('selected');
                }
            });
            
            // Initialize downpayment
            updateDownpayment();
            
            // Calculate initial total
            calculateTotal();
            
            // Initialize payment method - cash selected by default
            selectPaymentMethod('cash');
        });
        
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