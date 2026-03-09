<?php
/**
 * Veripool Reservation System - Staff Walk-in Page
 * Handle walk-in reservations and check-ins for staff with calendar
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

// Check if is_walkin_account column exists in users table
$walkin_column_exists = false;
try {
    $columns = $db->getRows("SHOW COLUMNS FROM users LIKE 'is_walkin_account'");
    $walkin_column_exists = !empty($columns);
} catch (Exception $e) {
    $walkin_column_exists = false;
}

// Check if reservation_pools table exists
$pools_table_exists = false;
try {
    $result = $db->getRows("SHOW TABLES LIKE 'reservation_pools'");
    $pools_table_exists = !empty($result);
} catch (Exception $e) {
    $pools_table_exists = false;
}

// Get current user (for sidebar)
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Get all available rooms for walk-in
$available_rooms = $db->getRows("
    SELECT r.*, rt.name as room_type_name, rt.base_price, rt.max_occupancy
    FROM rooms r
    JOIN room_types rt ON r.room_type_id = rt.id
    WHERE r.status = 'available'
    ORDER BY r.room_number
");

// Get all available cottages for walk-in
$available_cottages = $db->getRows("
    SELECT * FROM cottages 
    WHERE status = 'available'
    ORDER BY cottage_name
");

// Get both pools (Ernesto and Pavilion)
$pools = $db->getRows("SELECT * FROM pools ORDER BY FIELD(name, 'Ernesto', 'Pavilion')");

// Get active entrance fees
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

// Get all reservations for calendar (to show booked dates)
$all_reservations = $db->getRows("
    SELECT r.id, r.check_in_date, r.check_out_date, r.status, rm.room_number, c.cottage_name
    FROM reservations r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN reservation_cottages rc ON r.id = rc.reservation_id
    LEFT JOIN cottages c ON rc.cottage_id = c.id
    WHERE r.status IN ('confirmed', 'checked_in', 'pending')
    ORDER BY r.check_in_date
");

// Get today's walk-in reservations
$today = date('Y-m-d');
$today_walkins = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone, u.email, rm.room_number, rt.name as room_type
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE DATE(r.created_at) = ? AND r.created_by = 'walkin'
    ORDER BY r.created_at DESC
", [$today]);

// Get recent walk-in guests (last 30 days)
$recent_walkins = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone, u.email, rm.room_number, rt.name as room_type
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE r.created_by = 'walkin'
    ORDER BY r.created_at DESC
    LIMIT 20
");

// Search for registered users by phone or email
$search_results = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = '%' . sanitize($_GET['search']) . '%';
    $search_results = $db->getRows("
        SELECT id, full_name, email, phone, address 
        FROM users 
        WHERE (full_name LIKE ? OR email LIKE ? OR phone LIKE ?) 
        AND role = 'guest'
        ORDER BY full_name
        LIMIT 10
    ", [$search_term, $search_term, $search_term]);
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

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Log POST data
    error_log("POST Data: " . print_r($_POST, true));
    
    if (isset($_POST['action'])) {
        
        // Create walk-in reservation
        if ($_POST['action'] === 'create_walkin') {
            try {
                // Get and validate form data
                $guest_name = isset($_POST['guest_name']) ? sanitize($_POST['guest_name']) : '';
                $guest_phone = isset($_POST['guest_phone']) ? sanitize($_POST['guest_phone']) : '';
                $guest_email = isset($_POST['guest_email']) ? sanitize($_POST['guest_email']) : null;
                $guest_address = isset($_POST['guest_address']) ? sanitize($_POST['guest_address']) : null;
                $existing_user_id = !empty($_POST['existing_user_id']) ? (int)$_POST['existing_user_id'] : null;
                
                $check_in_date = isset($_POST['check_in_date']) ? sanitize($_POST['check_in_date']) : '';
                $check_out_date = isset($_POST['check_out_date']) ? sanitize($_POST['check_out_date']) : '';
                $adults = isset($_POST['adults']) ? (int)$_POST['adults'] : 1;
                $children = isset($_POST['children']) ? (int)$_POST['children'] : 0;
                $seniors = isset($_POST['seniors']) ? (int)$_POST['seniors'] : 0;
                $room_id = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
                $cottage_id = !empty($_POST['cottage_id']) ? (int)$_POST['cottage_id'] : null;
                
                // Get selected pools
                $selected_pools = isset($_POST['pools']) ? $_POST['pools'] : [];
                
                // Validate required fields
                if (empty($guest_name)) {
                    throw new Exception("Guest name is required");
                }
                if (empty($guest_phone)) {
                    throw new Exception("Phone number is required");
                }
                if (empty($check_in_date)) {
                    throw new Exception("Check-in date is required");
                }
                if (empty($check_out_date)) {
                    throw new Exception("Check-out date is required");
                }
                
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
                
                // Calculate accommodation total
                $accommodation_total = 0;
                $nights = 0;
                $is_overnight = false;
                
                if ($room_id || $cottage_id) {
                    $nights = ceil((strtotime($check_out_date) - strtotime($check_in_date)) / (60 * 60 * 24));
                    $is_overnight = ($nights > 0);
                    
                    if ($room_id) {
                        $room = $db->getRow("
                            SELECT r.*, rt.base_price 
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
                }
                
                $total_amount = $accommodation_total + $entrance_fee_total;
                $amount_paid = $total_amount; // Full payment
                
                // Determine reservation type
                $reservation_type = 'daytour';
                if ($is_overnight) {
                    $reservation_type = 'overnight';
                } elseif ($cottage_id && !$room_id && $nights == 1) {
                    $reservation_type = 'daytour_with_cottage';
                }
                
                // Start transaction
                $db->beginTransaction();
                
                // Check if we're using an existing user or need to create a new one
                $guest_id = null;
                $is_new_account = false;
                
                if ($existing_user_id) {
                    // Use existing registered user
                    $guest = $db->getRow("SELECT * FROM users WHERE id = ?", [$existing_user_id]);
                    if ($guest) {
                        $guest_id = $guest['id'];
                        error_log("Using existing user by ID: {$guest_id}");
                    } else {
                        throw new Exception("Selected user not found");
                    }
                } else {
                    // First check if user exists by phone (most reliable for walk-ins)
                    $guest = null;
                    if (!empty($guest_phone)) {
                        $guest = $db->getRow("SELECT * FROM users WHERE phone = ?", [$guest_phone]);
                    }
                    
                    // If not found by phone, try email (but only if email is provided and not a temp email)
                    if (!$guest && !empty($guest_email) && strpos($guest_email, '@walkin.temp') === false) {
                        $guest = $db->getRow("SELECT * FROM users WHERE email = ?", [$guest_email]);
                    }
                    
                    if ($guest) {
                        // Use existing guest
                        $guest_id = $guest['id'];
                        error_log("Using existing guest ID: {$guest_id}");
                        
                        // Update guest info if needed
                        $update_data = [];
                        if (!empty($guest_name) && $guest['full_name'] != $guest_name) {
                            $update_data['full_name'] = $guest_name;
                        }
                        if (!empty($guest_address) && $guest['address'] != $guest_address) {
                            $update_data['address'] = $guest_address;
                        }
                        
                        if (!empty($update_data)) {
                            $db->update('users', $update_data, 'id = :id', ['id' => $guest_id]);
                            error_log("Updated guest info for ID: {$guest_id}");
                        }
                    } else {
                        // Create new guest user
                        $username = 'walkin_' . time() . rand(100, 999);
                        
                        // Generate a unique email
                        $final_email = $guest_email;
                        if (empty($final_email)) {
                            $final_email = $username . '@walkin.temp';
                        } else {
                            // Check if email already exists
                            $email_exists = $db->getRow("SELECT id FROM users WHERE email = ?", [$final_email]);
                            if ($email_exists) {
                                // Email exists, create a unique one
                                $final_email = $username . '_' . time() . '@walkin.temp';
                            }
                        }
                        
                        $guest_data = [
                            'username' => $username,
                            'email' => $final_email,
                            'password' => password_hash(bin2hex(random_bytes(4)), PASSWORD_DEFAULT),
                            'full_name' => $guest_name,
                            'phone' => $guest_phone,
                            'address' => $guest_address,
                            'role' => 'guest',
                            'status' => 'active',
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        // Only add is_walkin_account if column exists
                        if ($walkin_column_exists) {
                            $guest_data['is_walkin_account'] = 1;
                        }
                        
                        $guest_id = $db->insert('users', $guest_data);
                        
                        if (!$guest_id) {
                            throw new Exception("Failed to create guest account");
                        }
                        
                        $is_new_account = true;
                        error_log("Created new walk-in account ID: {$guest_id} for {$guest_name}");
                    }
                }
                
                // Generate reservation number
                $prefix = 'WALK';
                if ($reservation_type == 'overnight') {
                    $prefix = 'OVN';
                } elseif ($reservation_type == 'daytour_with_cottage') {
                    $prefix = 'DYC';
                } else {
                    $prefix = 'DAY';
                }
                
                $reservation_number = $prefix . date('Ymd') . rand(1000, 9999);
                
                // Create reservation (NO OTP needed for walk-ins)
                $reservation_data = [
                    'reservation_number' => $reservation_number,
                    'user_id' => $guest_id,
                    'room_id' => $room_id,
                    'check_in_date' => $check_in_date,
                    'check_out_date' => $check_out_date,
                    'adults' => $adults,
                    'children' => $children,
                    'seniors' => $seniors,
                    'total_guests' => $adults + $children + $seniors,
                    'total_amount' => $total_amount,
                    'amount_paid' => $amount_paid,
                    'status' => 'confirmed', // Walk-ins are immediately confirmed
                    'reservation_type' => $reservation_type,
                    'has_entrance_fee' => 1,
                    'entrance_fee_amount' => $entrance_fee_total,
                    'entrance_fee_paid' => $entrance_fee_total,
                    'entrance_fee_guests' => $adults + $children + $seniors,
                    'created_by' => 'walkin',
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                error_log("Attempting to insert reservation: " . print_r($reservation_data, true));
                
                $reservation_id = $db->insert('reservations', $reservation_data);
                
                if (!$reservation_id) {
                    throw new Exception("Failed to create reservation - no ID returned");
                }
                
                error_log("Reservation created successfully with ID: {$reservation_id}");
                
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
                    
                    // Update cottage status
                    $db->update('cottages', 
                        ['status' => 'occupied'], 
                        'id = :id', 
                        ['id' => $cottage_id]
                    );
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
                
                // Record entrance fee payment
                if ($entrance_fee_total > 0) {
                    $fee_payment_number = 'ENT' . date('Ymd') . rand(1000, 9999);
                    
                    $fee_breakdown = json_encode([
                        'adults' => $adults,
                        'children' => $children,
                        'seniors' => $seniors,
                        'adult_fee' => $adult_fee,
                        'child_fee' => $child_fee,
                        'senior_fee' => $senior_fee
                    ]);
                    
                    $fee_payment_data = [
                        'payment_number' => $fee_payment_number,
                        'reservation_id' => $reservation_id,
                        'guest_name' => $guest_name,
                        'guest_count' => $adults + $children + $seniors,
                        'fee_breakdown' => $fee_breakdown,
                        'total_amount' => $entrance_fee_total,
                        'payment_method' => 'cash',
                        'payment_status' => 'completed',
                        'created_by' => $_SESSION['user_id'],
                        'payment_date' => date('Y-m-d H:i:s'),
                        'notes' => 'Entrance fee for walk-in guest'
                    ];
                    
                    $db->insert('entrance_fee_payments', $fee_payment_data);
                }
                
                // Record accommodation payment
                if ($accommodation_total > 0) {
                    $payment_number = 'PAY' . date('Ymd') . rand(1000, 9999);
                    
                    $payment_data = [
                        'payment_number' => $payment_number,
                        'reservation_id' => $reservation_id,
                        'amount' => $accommodation_total,
                        'payment_method' => 'cash',
                        'payment_status' => 'completed',
                        'created_by' => $_SESSION['user_id'],
                        'payment_date' => date('Y-m-d H:i:s'),
                        'notes' => 'Full accommodation payment for walk-in guest'
                    ];
                    
                    $db->insert('payments', $payment_data);
                }
                
                // Update room status if room was assigned
                if ($room_id) {
                    $db->update('rooms', 
                        ['status' => 'occupied'], 
                        'id = :id', 
                        ['id' => $room_id]
                    );
                }
                
                $db->commit();
                
                $message = "Walk-in reservation created successfully! Reservation #: {$reservation_number}";
                $message_type = 'success';
                
                // Store in session for receipt
                $_SESSION['last_walkin'] = [
                    'reservation_number' => $reservation_number,
                    'guest_name' => $guest_name,
                    'total_amount' => $total_amount
                ];
                
                // Redirect to clear POST data
                header("Location: walkin.php?success=1");
                exit;
                
            } catch (Exception $e) {
                $db->rollback();
                $message = "Error: " . $e->getMessage();
                $message_type = 'error';
                error_log("Walk-in reservation error: " . $e->getMessage());
            }
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == 1 && isset($_SESSION['last_walkin'])) {
    $message = "Walk-in reservation created successfully! Reservation #: " . $_SESSION['last_walkin']['reservation_number'];
    $message_type = 'success';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Walk-in - Veripool Resort</title>
    
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
        }
        
        .calendar-day:hover {
            background: #e9ecef;
        }
        
        .calendar-day.empty {
            background: transparent;
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
        
        .walkin-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        
        .walkin-form {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .walkin-form h2 {
            color: #102C57;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .form-section {
            margin-bottom: 25px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .form-section h3 {
            color: #1679AB;
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
        .form-section.guest-search {
            background: #e8f4fd;
            border-left: 4px solid #1679AB;
        }
        
        .form-section.guest-search h3 {
            color: #102C57;
        }
        
        .form-section.pool-section {
            background: #FFF0F0;
            border-left: 4px solid #FFB1B1;
        }
        
        .form-section.pool-section h3 {
            color: #102C57;
        }
        
        .form-section.entrance-section {
            background: #FFF0F0;
            border-left: 4px solid #FFB1B1;
        }
        
        .form-section.entrance-section h3 {
            color: #102C57;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-row.three-col {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #102C57;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 1rem;
        }
        
        .form-control:focus {
            border-color: #1679AB;
            outline: none;
        }
        
        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .search-box input {
            flex: 1;
        }
        
        .search-box button {
            background: #1679AB;
            color: white;
            border: none;
            padding: 0 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .search-box button:hover {
            background: #102C57;
        }
        
        .search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        
        .search-result-item {
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .search-result-item:hover {
            background: #e8f4fd;
        }
        
        .search-result-item.selected {
            background: #1679AB;
            color: white;
        }
        
        .search-result-item .name {
            font-weight: bold;
        }
        
        .search-result-item .details {
            font-size: 0.85rem;
            color: #666;
        }
        
        .search-result-item.selected .details {
            color: #e0e0e0;
        }
        
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
        
        .total-display {
            background: #102C57;
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }
        
        .total-display .label {
            font-size: 0.9rem;
            color: #FFCBCB;
        }
        
        .total-display .amount {
            font-size: 2rem;
            font-weight: bold;
            color: #FFB1B1;
        }
        
        .total-breakdown {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .breakdown-item {
            background: rgba(255,255,255,0.1);
            padding: 8px;
            border-radius: 5px;
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
        
        .btn-submit {
            background: #1679AB;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s;
        }
        
        .btn-submit:hover {
            background: #102C57;
            transform: translateY(-2px);
        }
        
        .walkin-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .walkin-list h2 {
            color: #102C57;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .walkin-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 3px solid #1679AB;
        }
        
        .walkin-item.entrance-only {
            border-left-color: #FFB1B1;
        }
        
        .walkin-item.overnight {
            border-left-color: #102C57;
        }
        
        .walkin-item.daytour-cottage {
            border-left-color: #28a745;
        }
        
        .walkin-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .walkin-number {
            font-weight: bold;
            color: #102C57;
        }
        
        .type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .type-overnight {
            background: #102C57;
            color: white;
        }
        
        .type-daytour {
            background: #28a745;
            color: white;
        }
        
        .type-cottage {
            background: #ffc107;
            color: #102C57;
        }
        
        .entrance-badge {
            background: #FFB1B1;
            color: #102C57;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .pool-badge {
            background: #1679AB;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .walkin-status {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d4edda; color: #155724; }
        .status-checked_in { background: #cce5ff; color: #004085; }
        
        .walkin-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .walkin-details i {
            width: 20px;
            color: #1679AB;
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
        
        .registered-badge {
            background: #1679AB;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .success-receipt {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .receipt-details {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 10px;
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .btn-checkin {
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.85rem;
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

        .db-warning {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }

        .db-warning pre {
            background: #fff;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            overflow-x: auto;
            font-size: 0.85rem;
        }
        
        @media (max-width: 1024px) {
            .walkin-container {
                grid-template-columns: 1fr;
            }
            
            .form-row {
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
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <h1>
                <i class="fas fa-user-plus"></i>
                Walk-in Management
            </h1>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
                <span style="margin-left: 10px;"><i class="far fa-clock"></i> <?php echo date('h:i A'); ?></span>
            </div>
        </div>
        
        <?php if (!$pools_table_exists): ?>
        <div class="db-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Note:</strong> The reservation_pools table doesn't exist yet. Pool selections won't be saved until you create it.
        </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['last_walkin'])): ?>
        <div class="success-receipt">
            <h4><i class="fas fa-check-circle"></i> Walk-in Reservation Successful!</h4>
            <div class="receipt-details">
                <div>
                    <strong>Reservation #:</strong> <?php echo $_SESSION['last_walkin']['reservation_number']; ?>
                </div>
                <div>
                    <strong>Guest:</strong> <?php echo $_SESSION['last_walkin']['guest_name']; ?>
                </div>
                <div>
                    <strong>Total Paid:</strong> ₱<?php echo number_format($_SESSION['last_walkin']['total_amount'], 2); ?>
                </div>
                <div>
                    <span class="full-payment-badge">FULL PAYMENT</span>
                </div>
            </div>
            <?php unset($_SESSION['last_walkin']); ?>
        </div>
        <?php endif; ?>
        
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
        
        <!-- Walk-in Form -->
        <div class="walkin-container">
            <div class="walkin-form">
                <h2><i class="fas fa-user-plus"></i> New Walk-in Reservation</h2>
                
                <!-- Guest Search Section -->
                <div class="form-section guest-search">
                    <h3><i class="fas fa-search"></i> Search for Registered Guest</h3>
                    
                    <form method="GET" class="search-box">
                        <input type="text" name="search" class="form-control" placeholder="Search by name, email, or phone..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                        <button type="submit"><i class="fas fa-search"></i></button>
                    </form>
                    
                    <?php if (!empty($search_results)): ?>
                    <div class="search-results">
                        <?php foreach ($search_results as $user): ?>
                        <div class="search-result-item" onclick="selectExistingUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name'])); ?>', '<?php echo htmlspecialchars($user['phone']); ?>', '<?php echo htmlspecialchars($user['email']); ?>', '<?php echo htmlspecialchars(addslashes($user['address'])); ?>')">
                            <div class="name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div class="details">
                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone']); ?>
                                <?php if ($user['email']): ?> | <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif (isset($_GET['search']) && !empty($_GET['search'])): ?>
                    <p style="color: #856404; padding: 10px; background: #fff3cd; border-radius: 5px;">
                        <i class="fas fa-info-circle"></i> No registered guests found. Please fill in the form below for a new walk-in guest.
                    </p>
                    <?php endif; ?>
                </div>
                
                <form method="POST" id="walkinForm" onsubmit="return validateWalkinForm()">
                    <input type="hidden" name="action" value="create_walkin">
                    <input type="hidden" name="existing_user_id" id="existing_user_id" value="">
                    
                    <div class="form-section">
                        <h3>Guest Information</h3>
                        
                        <div class="form-group">
                            <label for="guest_name">Full Name *</label>
                            <input type="text" name="guest_name" id="guest_name" class="form-control" required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="guest_phone">Phone Number *</label>
                                <input type="tel" name="guest_phone" id="guest_phone" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="guest_email">Email</label>
                                <input type="email" name="guest_email" id="guest_email" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="guest_address">Address</label>
                            <input type="text" name="guest_address" id="guest_address" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Stay Details</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="check_in_date">Check-in Date *</label>
                                <input type="date" name="check_in_date" id="check_in_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>" required onchange="calculateTotal()">
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
                        
                        <div class="form-group">
                            <label>Select Type</label>
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
                        
                        <div id="cottage_selection" class="form-group">
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
                        <p style="font-size: 0.85rem; color: #666; margin-bottom: 10px;">Guests can use both pools</p>
                        
                        <div class="pool-checkbox-group">
                            <?php foreach ($pools as $pool): ?>
                            <label class="pool-checkbox <?php echo strtolower($pool['name']); ?>">
                                <input type="checkbox" name="pools[]" value="<?php echo $pool['id']; ?>" onchange="togglePoolSelection(this)">
                                <div>
                                    <div class="pool-name">
                                        <?php echo htmlspecialchars($pool['name']); ?>
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
                    
                    <div class="form-section entrance-section">
                        <h3><i class="fas fa-ticket-alt"></i> Entrance Fee (Required for All Guests)</h3>
                        
                        <div class="fee-summary">
                            <table>
                                <?php foreach ($entrance_fees as $fee): ?>
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
                    
                    <div class="form-section">
                        <h3>Payment Information - FULL PAYMENT REQUIRED</h3>
                        
                        <div class="total-display">
                            <div class="label">Total Amount to Pay (Full Payment)</div>
                            <div class="amount" id="total_amount_display">₱0.00</div>
                            <input type="hidden" name="total_amount" id="total_amount" value="0">
                            
                            <div class="total-breakdown">
                                <div class="breakdown-item">
                                    <div>Accommodation</div>
                                    <div id="accommodation_amount">₱0.00</div>
                                </div>
                                <div class="breakdown-item">
                                    <div>Entrance Fee</div>
                                    <div id="entrance_fee_display">₱0.00</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group" style="background: #d4edda; padding: 15px; border-radius: 5px; text-align: center; border-left: 4px solid #28a745;">
                            <i class="fas fa-info-circle" style="color: #28a745;"></i>
                            <strong style="color: #155724;">Walk-in guests must pay the FULL amount at the time of booking.</strong>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Process Full Payment
                    </button>
                </form>
            </div>
            
            <!-- Today's Walk-ins List -->
            <div class="walkin-list">
                <h2><i class="fas fa-calendar-day"></i> Today's Walk-ins (<?php echo count($today_walkins); ?>)</h2>
                
                <?php if (empty($today_walkins)): ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No walk-in reservations today.</p>
                <?php else: ?>
                    <?php foreach ($today_walkins as $walkin): ?>
                    <div class="walkin-item">
                        <div class="walkin-item-header">
                            <span class="walkin-number">
                                <?php echo $walkin['reservation_number']; ?>
                                <span class="full-payment-badge">PAID</span>
                            </span>
                            <span class="walkin-status status-<?php echo $walkin['status']; ?>">
                                <?php echo ucfirst($walkin['status']); ?>
                            </span>
                        </div>
                        
                        <div class="walkin-details">
                            <div><i class="fas fa-user"></i> <?php echo htmlspecialchars($walkin['guest_name']); ?></div>
                            <div><i class="fas fa-phone"></i> <?php echo $walkin['phone']; ?></div>
                            <?php if ($walkin['room_number']): ?>
                            <div><i class="fas fa-bed"></i> Room <?php echo $walkin['room_number']; ?></div>
                            <?php endif; ?>
                            <div><i class="fas fa-calendar-check"></i> <?php echo date('M d', strtotime($walkin['check_in_date'])); ?></div>
                            <div><i class="fas fa-money-bill"></i> ₱<?php echo number_format($walkin['total_amount'], 2); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Recent Walk-ins -->
                <h3 style="margin: 30px 0 15px; color: #102C57;">Recent Walk-ins</h3>
                
                <?php if (empty($recent_walkins)): ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No recent walk-in reservations.</p>
                <?php else: ?>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($recent_walkins as $walkin): ?>
                        <div class="walkin-item" style="margin-bottom: 5px; padding: 10px;">
                            <div style="display: flex; justify-content: space-between;">
                                <span style="font-weight: bold;"><?php echo $walkin['reservation_number']; ?></span>
                                <span style="font-size: 0.8rem;"><?php echo date('M d, Y', strtotime($walkin['created_at'])); ?></span>
                            </div>
                            <div style="font-size: 0.9rem; display: flex; justify-content: space-between;">
                                <span><?php echo htmlspecialchars($walkin['guest_name']); ?></span>
                                <span style="color: #28a745; font-weight: bold;">₱<?php echo number_format($walkin['total_amount'], 2); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
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
                    }
                }
            } else if (type === 'both') {
                roomSelection.style.display = 'block';
                cottageSelection.style.display = 'block';
            } else {
                roomSelection.style.display = 'none';
                cottageSelection.style.display = 'none';
                document.getElementById('room_id').value = '';
                document.getElementById('cottage_id').value = '';
            }
            
            calculateTotal();
        }
        
        function calculateTotal() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const roomSelect = document.getElementById('room_id');
            const cottageSelect = document.getElementById('cottage_id');
            const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
            
            let accommodation = 0;
            
            if (checkIn && checkOut) {
                let nights = 1;
                if (bookingType !== 'daytour' && bookingType !== 'daytour_cottage') {
                    nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24));
                    if (nights < 1) nights = 1;
                }
                
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
            
            <?php foreach ($entrance_fees as $fee): ?>
                <?php if ($fee['fee_type'] == 'adult'): ?>
                    const adultFee = <?php echo $fee['amount']; ?>;
                <?php elseif ($fee['fee_type'] == 'child'): ?>
                    const childFee = <?php echo $fee['amount']; ?>;
                <?php elseif ($fee['fee_type'] == 'senior'): ?>
                    const seniorFee = <?php echo $fee['amount']; ?>;
                <?php endif; ?>
            <?php endforeach; ?>
            
            const entranceFee = (adults * adultFee) + (children * childFee) + (seniors * seniorFee);
            
            // Update displays
            document.getElementById('adult_count_display').innerText = adults;
            document.getElementById('child_count_display').innerText = children;
            document.getElementById('senior_count_display').innerText = seniors;
            document.getElementById('entrance_fee_total').innerText = '₱' + entranceFee.toFixed(2);
            
            const total = accommodation + entranceFee;
            
            document.getElementById('accommodation_amount').innerText = '₱' + accommodation.toFixed(2);
            document.getElementById('entrance_fee_display').innerText = '₱' + entranceFee.toFixed(2);
            document.getElementById('total_amount_display').innerText = '₱' + total.toFixed(2);
            document.getElementById('total_amount').value = total;
        }
        
        function selectDate(day) {
            const year = <?php echo $current_year; ?>;
            const month = <?php echo $current_month; ?>;
            const selectedDate = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            document.getElementById('check_in_date').value = selectedDate;
            
            const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
            if (bookingType === 'daytour' || bookingType === 'daytour_cottage') {
                document.getElementById('check_out_date').value = selectedDate;
            } else {
                const nextDay = new Date(year, month - 1, day + 1);
                document.getElementById('check_out_date').value = nextDay.toISOString().split('T')[0];
            }
            
            calculateTotal();
        }
        
        function selectExistingUser(userId, name, phone, email, address) {
            document.getElementById('existing_user_id').value = userId;
            document.getElementById('guest_name').value = name;
            document.getElementById('guest_phone').value = phone;
            document.getElementById('guest_email').value = email;
            document.getElementById('guest_address').value = address;
            
            // Highlight selected item
            document.querySelectorAll('.search-result-item').forEach(item => {
                item.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            alert('Guest information loaded from existing account');
        }
        
        function validateWalkinForm() {
            const guestName = document.getElementById('guest_name').value;
            const guestPhone = document.getElementById('guest_phone').value;
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
            const roomId = document.getElementById('room_id').value;
            const cottageId = document.getElementById('cottage_id').value;
            
            if (!guestName || !guestPhone || !checkIn || !checkOut) {
                alert('Please fill in all required fields');
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
            
            const totalAmount = parseFloat(document.getElementById('total_amount').value) || 0;
            if (totalAmount <= 0) {
                alert('Total amount must be greater than zero');
                return false;
            }
            
            return confirm(`Process FULL PAYMENT of ₱${totalAmount.toFixed(2)} for this walk-in guest?`);
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
        
        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            calculateTotal();
            
            // Initialize radio button styling
            document.querySelectorAll('.radio-option').forEach(option => {
                const radio = option.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    option.classList.add('selected');
                }
            });
        });
    </script>
</body>
</html>