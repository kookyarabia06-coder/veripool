<?php
/**
 * Veripool Reservation System - Guest Dashboard
 * Guest interface matching admin dashboard style
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

// Get current user (for sidebar)
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Check if user is guest
if ($current_user['role'] !== 'guest') {
    if ($current_user['role'] == 'admin' || $current_user['role'] == 'super_admin') {
        header("Location: " . BASE_URL . "/admin/dashboard.php");
    } elseif ($current_user['role'] == 'staff') {
        header("Location: " . BASE_URL . "/staff/dashboard.php");
    }
    exit;
}

// Get user's reservations
$reservations = $db->getRows("
    SELECT r.*, rm.room_number, rt.name as room_type
    FROM reservations r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
", [$current_user['id']]);

// Calculate statistics
$total_reservations = count($reservations);
$active_reservations = 0;
$completed_reservations = 0;
$upcoming_reservations = 0;
$total_spent = 0;

foreach ($reservations as $res) {
    if ($res['status'] == 'confirmed' || $res['status'] == 'checked_in') {
        $active_reservations++;
        if (strtotime($res['check_in_date']) > time()) {
            $upcoming_reservations++;
        }
    } elseif ($res['status'] == 'checked_out' || $res['status'] == 'completed') {
        $completed_reservations++;
        $total_spent += floatval($res['total_amount'] ?? 0);
    }
}

// Get current month and year for calendar
$current_month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$current_year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Adjust month/year if out of range
if ($current_month < 1) {
    $current_month = 12;
    $current_year--;
} elseif ($current_month > 12) {
    $current_month = 1;
    $current_year++;
}

// Get calendar data
$first_day = mktime(0, 0, 0, $current_month, 1, $current_year);
$days_in_month = date('t', $first_day);
$month_name = date('F Y', $first_day);
$starting_day = date('w', $first_day); // 0 = Sunday, 6 = Saturday

// Get reservations for calendar
$start_date = date('Y-m-01', $first_day);
$end_date = date('Y-m-t', $first_day);

$calendar_reservations = $db->getRows("
    SELECT r.*, rm.room_number, rt.name as room_type
    FROM reservations r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE r.user_id = ?
    AND (
        (r.check_in_date BETWEEN ? AND ?) OR
        (r.check_out_date BETWEEN ? AND ?) OR
        (? BETWEEN r.check_in_date AND r.check_out_date)
    )
    ORDER BY r.check_in_date
", [$current_user['id'], $start_date, $end_date, $start_date, $end_date, $start_date]);

// Organize reservations by date
$reservations_by_date = [];
foreach ($calendar_reservations as $res) {
    $check_in = strtotime($res['check_in_date']);
    $check_out = strtotime($res['check_out_date']);
    
    // Mark all days between check-in and check-out
    for ($date = $check_in; $date <= $check_out; $date += 86400) {
        $date_key = date('Y-m-d', $date);
        if (!isset($reservations_by_date[$date_key])) {
            $reservations_by_date[$date_key] = [];
        }
        $reservations_by_date[$date_key][] = $res;
    }
}

// Handle any messages
$message = isset($_SESSION['message']) ? $_SESSION['message'] : '';
$message_type = isset($_SESSION['message_type']) ? $_SESSION['message_type'] : '';
unset($_SESSION['message']);
unset($_SESSION['message_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Dashboard - Veripool Resort</title>
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f4f7fc;
            display: flex;
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: all 0.3s;
        }

        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }

        .top-bar h1 {
            font-size: 1.6rem;
            color: #102C57;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .top-bar h1 i {
            color: #1679AB;
        }

        .date {
            color: #666;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8f9fa;
            padding: 10px 20px;
            border-radius: 10px;
        }

        .date i {
            color: #1679AB;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s;
            border: 1px solid rgba(22,121,171,0.1);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(22,121,171,0.15);
            border-color: #1679AB;
        }

        .stat-icon {
            width: 65px;
            height: 65px;
            background: linear-gradient(135deg, #FFCBCB, #FFB1B1);
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
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .stat-info .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #102C57;
            line-height: 1.2;
            margin-bottom: 5px;
        }

        .stat-info .trend {
            color: #1679AB;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .trend i {
            font-size: 0.7rem;
        }

        /* Welcome Card */
        .welcome-card {
            background: linear-gradient(135deg, #102C57, #1679AB);
            color: white;
            padding: 35px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(22,121,171,0.3);
            position: relative;
            overflow: hidden;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,203,203,0.1));
            transform: skewX(-20deg) translateX(100px);
        }

        .welcome-card h2 {
            font-size: 2.2rem;
            margin-bottom: 15px;
            color: #FFCBCB;
            position: relative;
        }

        .welcome-card p {
            font-size: 1.1rem;
            opacity: 0.95;
            position: relative;
            max-width: 600px;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.03);
            overflow: hidden;
            border: 1px solid rgba(22,121,171,0.1);
            height: fit-content;
        }

        .card-header {
            padding: 20px 25px;
            background: #f8f9fa;
            border-bottom: 2px solid #FFCBCB;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            color: #102C57;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-header h3 i {
            color: #1679AB;
        }

        .card-header .badge {
            background: #FFCBCB;
            color: #102C57;
            padding: 6px 15px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .card-body {
            padding: 25px;
        }

        /* Calendar Styles */
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .calendar-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #102C57;
        }

        .calendar-nav {
            display: flex;
            gap: 10px;
        }

        .calendar-nav-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid #e9ecef;
            background: white;
            color: #1679AB;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            text-decoration: none;
        }

        .calendar-nav-btn:hover {
            background: #1679AB;
            color: white;
            border-color: #1679AB;
        }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-weight: 600;
            color: #102C57;
            margin-bottom: 10px;
            padding: 10px 0;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }

        .calendar-day {
            aspect-ratio: 1;
            padding: 8px;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            background: white;
        }

        .calendar-day:hover {
            border-color: #1679AB;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(22,121,171,0.1);
        }

        .calendar-day.empty {
            background: #f8f9fa;
            border: 1px dashed #e9ecef;
            cursor: default;
        }

        .calendar-day.empty:hover {
            transform: none;
            border-color: #e9ecef;
            box-shadow: none;
        }

        .day-number {
            font-weight: 600;
            color: #102C57;
            margin-bottom: 3px;
        }

        .empty .day-number {
            color: #aaa;
        }

        .day-indicators {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .day-indicator {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #1679AB;
        }

        .day-indicator.multiple {
            background: #FFCBCB;
        }

        .day-indicator.confirmed {
            background: #28a745;
        }

        .calendar-day.has-reservation {
            background: #f0f9ff;
            border-color: #1679AB;
        }

        .calendar-day.today {
            border: 2px solid #FFCBCB;
        }

        /* Reservation Tooltip */
        .reservation-tooltip {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #102C57;
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: 10;
            display: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .calendar-day:hover .reservation-tooltip {
            display: block;
        }

        .reservation-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: #102C57 transparent transparent transparent;
        }

        /* Legend */
        .calendar-legend {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #666;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 4px;
        }

        .legend-color.reservation {
            background: #1679AB;
        }

        .legend-color.today {
            background: #FFCBCB;
            border: 2px solid #FFCBCB;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
            border-radius: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 16px 20px;
            background: #f8f9fa;
            color: #102C57;
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 2px solid #FFCBCB;
        }

        td {
            padding: 16px 20px;
            border-bottom: 1px solid #e9ecef;
            color: #444;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: #f8f9fa;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 500;
            letter-spacing: 0.3px;
        }

        .status-pending { 
            background: #fff3cd; 
            color: #856404;
            border-left: 3px solid #ffc107;
        }
        .status-confirmed { 
            background: #d4edda; 
            color: #155724;
            border-left: 3px solid #28a745;
        }
        .status-checked_in { 
            background: #cce5ff; 
            color: #004085;
            border-left: 3px solid #007bff;
        }
        .status-checked_out,
        .status-completed { 
            background: #e2e3e5; 
            color: #383d41;
            border-left: 3px solid #6c757d;
        }
        .status-cancelled { 
            background: #f8d7da; 
            color: #721c24;
            border-left: 3px solid #dc3545;
        }

        /* OTP Code */
        .otp-code {
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            color: #1679AB;
            background: #e8f4fd;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 2px;
            display: inline-block;
        }

        /* Action Buttons */
        .action-btn {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            border: 1px solid transparent;
        }

        .btn-view {
            background: #e8f4fd;
            color: #1679AB;
        }

        .btn-view:hover {
            background: #1679AB;
            color: white;
        }

        /* Alert */
        .alert {
            padding: 16px 22px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid;
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
            border-left-color: #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 30px;
            color: #666;
        }

        .empty-state i {
            font-size: 4.5rem;
            color: #FFCBCB;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: #102C57;
            margin-bottom: 12px;
            font-size: 1.4rem;
        }

        .empty-state p {
            margin-bottom: 25px;
            color: #888;
        }

        .btn-primary {
            background: #1679AB;
            color: white;
            padding: 12px 28px;
            border: none;
            border-radius: 10px;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(22,121,171,0.3);
        }

        .btn-primary:hover {
            background: #102C57;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(22,121,171,0.4);
        }

        .btn-secondary {
            background: white;
            color: #1679AB;
            padding: 8px 16px;
            border: 2px solid #1679AB;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-secondary:hover {
            background: #1679AB;
            color: white;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }

        .quick-action-card {
            flex: 1;
            background: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            text-decoration: none;
            color: #102C57;
            border: 1px solid rgba(22,121,171,0.1);
            transition: all 0.3s;
        }

        .quick-action-card:hover {
            transform: translateY(-5px);
            border-color: #1679AB;
            box-shadow: 0 5px 20px rgba(22,121,171,0.1);
        }

        .quick-action-card i {
            font-size: 2rem;
            color: #1679AB;
            margin-bottom: 12px;
        }

        .quick-action-card span {
            display: block;
            font-weight: 500;
            margin-bottom: 5px;
        }

        .quick-action-card small {
            color: #888;
            font-size: 0.8rem;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .quick-actions {
                flex-wrap: wrap;
            }
            
            .quick-action-card {
                min-width: 200px;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .welcome-card h2 {
                font-size: 1.6rem;
            }
            
            .calendar-days {
                font-size: 0.8rem;
            }
            
            .day-indicators {
                display: none;
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
                <i class="fas fa-tachometer-alt"></i>
                Guest Dashboard
            </h1>
            <div class="date">
                <i class="far fa-calendar-alt"></i> 
                <span><?php echo date('l, F d, Y'); ?></span>
                <i class="far fa-clock" style="margin-left: 10px;"></i> 
                <span><?php echo date('h:i A'); ?></span>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Welcome Card -->
        <div class="welcome-card">
            <h2>Welcome back, <?php echo htmlspecialchars($current_user['full_name'] ?: $current_user['username']); ?>! 👋</h2>
            <p>Manage your reservations, view booking history, and plan your next stay at Veripool Resort.</p>
        </div>

      
        
        <!-- Dashboard Grid with Calendar -->
        <div class="dashboard-grid">
            <!-- Calendar Card -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-calendar"></i> Reservation Calendar</h3>
                    <span class="badge"><?php echo $month_name; ?></span>
                </div>
                <div class="card-body">
                    <div class="calendar-header">
                        <div class="calendar-title">Your Stays</div>
                        <div class="calendar-nav">
                            <a href="?month=<?php echo $current_month - 1; ?>&year=<?php echo $current_year; ?>" class="calendar-nav-btn">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <a href="?month=<?php echo $current_month + 1; ?>&year=<?php echo $current_year; ?>" class="calendar-nav-btn">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="calendar-weekdays">
                        <div>Sun</div>
                        <div>Mon</div>
                        <div>Tue</div>
                        <div>Wed</div>
                        <div>Thu</div>
                        <div>Fri</div>
                        <div>Sat</div>
                    </div>
                    
                    <div class="calendar-days">
                        <?php
                        // Fill empty cells before first day of month
                        for ($i = 0; $i < $starting_day; $i++): ?>
                            <div class="calendar-day empty">
                                <span class="day-number"></span>
                            </div>
                        <?php endfor; ?>
                        
                        <?php
                        // Fill days of month
                        for ($day = 1; $day <= $days_in_month; $day++):
                            $current_date = date('Y-m-d', strtotime("$current_year-$current_month-$day"));
                            $is_today = ($current_date == date('Y-m-d'));
                            $has_reservation = isset($reservations_by_date[$current_date]);
                            $reservation_count = $has_reservation ? count($reservations_by_date[$current_date]) : 0;
                            
                            // Get reservation statuses for this day
                            $statuses = [];
                            if ($has_reservation) {
                                foreach ($reservations_by_date[$current_date] as $res) {
                                    $statuses[] = $res['status'];
                                }
                            }
                        ?>
                            <div class="calendar-day <?php echo $has_reservation ? 'has-reservation' : ''; ?> <?php echo $is_today ? 'today' : ''; ?>">
                                <span class="day-number"><?php echo $day; ?></span>
                                
                                <?php if ($has_reservation): ?>
                                    <div class="day-indicators">
                                        <?php foreach (array_slice($statuses, 0, 3) as $status): ?>
                                            <span class="day-indicator <?php echo $status; ?>"></span>
                                        <?php endforeach; ?>
                                        <?php if ($reservation_count > 3): ?>
                                            <span class="day-indicator multiple"></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Tooltip with reservation details -->
                                    <div class="reservation-tooltip">
                                        <?php foreach ($reservations_by_date[$current_date] as $res): ?>
                                            <div style="margin-bottom: 5px; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 5px;">
                                                <strong>Room <?php echo $res['room_number']; ?></strong><br>
                                                <?php echo $res['room_type']; ?><br>
                                                <small><?php echo ucfirst($res['status']); ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                        
                        <?php
                        // Calculate remaining cells to complete the grid
                        $total_cells = $starting_day + $days_in_month;
                        $remaining_cells = ceil($total_cells / 7) * 7 - $total_cells;
                        
                        for ($i = 0; $i < $remaining_cells; $i++): ?>
                            <div class="calendar-day empty">
                                <span class="day-number"></span>
                            </div>
                        <?php endfor; ?>
                    </div>
                    
                    <div class="calendar-legend">
                        <div class="legend-item">
                            <span class="legend-color reservation"></span>
                            <span>Reservation Day</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color today"></span>
                            <span>Today</span>
                        </div>
                    </div>
                </div>
            </div>
           
        
        <!-- Recent Reservations -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Reservations</h3>
                <span class="badge">Last 5 Bookings</span>
            </div>
            <div class="card-body">
                <?php if (empty($reservations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Reservations Yet</h3>
                        <p>Ready to experience Veripool Resort? Start planning your perfect stay today!</p>
                        <a href="new-reservation.php" class="btn-primary">
                            <i class="fas fa-plus-circle"></i> Book Your First Stay
                        </a>
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
                                    <th>Status</th>
                                    <th>OTP</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($reservations, 0, 5) as $res): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($res['reservation_number']); ?></strong></td>
                                    <td>
                                        <?php if ($res['room_number']): ?>
                                            Room <?php echo htmlspecialchars($res['room_number']); ?>
                                            <br><small><?php echo htmlspecialchars($res['room_type']); ?></small>
                                        <?php else: ?>
                                            <span style="color: #888;">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($res['check_in_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($res['check_out_date'])); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $res['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $res['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($res['otp_code'] && $res['status'] == 'confirmed'): ?>
                                            <span class="otp-code"><?php echo htmlspecialchars($res['otp_code']); ?></span>
                                        <?php elseif ($res['status'] == 'checked_in'): ?>
                                            <span style="color: #28a745;">✓ Checked In</span>
                                        <?php else: ?>
                                            <span style="color: #888;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="view-reservation.php?id=<?php echo $res['id']; ?>" class="action-btn btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (count($reservations) > 5): ?>
                    <div style="text-align: right; margin-top: 20px;">
                        <a href="my-reservations.php" class="btn-secondary">
                            <i class="fas fa-arrow-right"></i> View All Reservations
                        </a>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-hide alerts
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