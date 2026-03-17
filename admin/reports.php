<?php
/**
 * Veripool Reservation System - Admin Reports Page
 * Generate and view various reports
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

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get current user
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Get report parameters
$report_type = isset($_GET['type']) ? $_GET['type'] : 'revenue';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Initialize report data
$report_data = [];
$report_title = '';
$report_total = 0;

// Generate report based on type
switch ($report_type) {
    case 'revenue':
        $report_title = 'Revenue Report';
        
        // Daily revenue for the selected period
        $report_data = $db->getRows("
            SELECT 
                DATE(payment_date) as date,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount,
                SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash_amount,
                SUM(CASE WHEN payment_method = 'card' THEN amount ELSE 0 END) as card_amount,
                SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount ELSE 0 END) as bank_amount,
                SUM(CASE WHEN payment_method = 'online' THEN amount ELSE 0 END) as online_amount
            FROM payments
            WHERE payment_status = 'completed'
            AND DATE(payment_date) BETWEEN :date_from AND :date_to
            GROUP BY DATE(payment_date)
            ORDER BY date DESC
        ", ['date_from' => $date_from, 'date_to' => $date_to]);
        
        $report_total = $db->getValue("
            SELECT COALESCE(SUM(amount), 0) FROM payments 
            WHERE payment_status = 'completed'
            AND DATE(payment_date) BETWEEN ? AND ?
        ", [$date_from, $date_to]);
        break;
        
    case 'occupancy':
        $report_title = 'Room Occupancy Report';
        
        $report_data = $db->getRows("
            SELECT 
                rt.name as room_type,
                COUNT(DISTINCT r.id) as total_rooms,
                COUNT(DISTINCT CASE WHEN res.status IN ('confirmed', 'checked_in') THEN res.id END) as occupied_rooms,
                COUNT(DISTINCT CASE WHEN res.status = 'checked_out' THEN res.id END) as completed_stays,
                COALESCE(SUM(CASE WHEN res.status IN ('confirmed', 'checked_in', 'checked_out') THEN DATEDIFF(res.check_out_date, res.check_in_date) END), 0) as total_nights
            FROM room_types rt
            LEFT JOIN rooms rm ON rt.id = rm.room_type_id
            LEFT JOIN reservations res ON rm.id = res.room_id 
                AND res.check_in_date BETWEEN :date_from AND :date_to
            GROUP BY rt.id, rt.name
        ", ['date_from' => $date_from, 'date_to' => $date_to]);
        
        $total_rooms = $db->getValue("SELECT COUNT(*) FROM rooms");
        $occupied_now = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'occupied'");
        break;
        
    case 'guests':
        $report_title = 'Guest Report';
        
        $report_data = $db->getRows("
            SELECT 
                u.id,
                u.full_name,
                u.email,
                u.phone,
                COUNT(r.id) as total_reservations,
                SUM(r.total_amount) as total_spent,
                MAX(r.created_at) as last_visit
            FROM users u
            LEFT JOIN reservations r ON u.id = r.user_id
            WHERE u.role = 'guest'
            GROUP BY u.id
            ORDER BY total_spent DESC
            LIMIT 50
        ");
        
        $total_guests = $db->getValue("SELECT COUNT(*) FROM users WHERE role = 'guest'");
        $active_guests = $db->getValue("
            SELECT COUNT(DISTINCT user_id) FROM reservations 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        break;
        
    case 'payments':
        $report_title = 'Payment Methods Report';
        
        $report_data = $db->getRows("
            SELECT 
                payment_method,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount,
                MIN(amount) as min_amount,
                MAX(amount) as max_amount
            FROM payments
            WHERE payment_status = 'completed'
            AND DATE(payment_date) BETWEEN :date_from AND :date_to
            GROUP BY payment_method
        ", ['date_from' => $date_from, 'date_to' => $date_to]);
        
        $total_transactions = $db->getValue("
            SELECT COUNT(*) FROM payments 
            WHERE payment_status = 'completed'
            AND DATE(payment_date) BETWEEN ? AND ?
        ", [$date_from, $date_to]);
        
        $total_amount = $db->getValue("
            SELECT COALESCE(SUM(amount), 0) FROM payments 
            WHERE payment_status = 'completed'
            AND DATE(payment_date) BETWEEN ? AND ?
        ", [$date_from, $date_to]);
        break;
        
    case 'cottages':
        $report_title = 'Cottage Usage Report';
        
        $report_data = $db->getRows("
            SELECT 
                c.cottage_name,
                c.cottage_type,
                COUNT(rc.id) as total_bookings,
                SUM(DATEDIFF(r.check_out_date, r.check_in_date)) as total_days,
                SUM(rc.price_at_time * rc.quantity) as total_revenue
            FROM cottages c
            LEFT JOIN reservation_cottages rc ON c.id = rc.cottage_id
            LEFT JOIN reservations r ON rc.reservation_id = r.id
                AND r.check_in_date BETWEEN :date_from AND :date_to
            GROUP BY c.id
            ORDER BY total_bookings DESC
        ", ['date_from' => $date_from, 'date_to' => $date_to]);
        
        $total_bookings = 0;
        foreach ($report_data as $row) {
            $total_bookings += $row['total_bookings'];
        }
        break;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $filename = $report_type . '_report_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers based on report type
    switch ($report_type) {
        case 'revenue':
            fputcsv($output, ['Date', 'Transactions', 'Cash', 'Card', 'Bank Transfer', 'Online', 'Total']);
            foreach ($report_data as $row) {
                fputcsv($output, [
                    $row['date'],
                    $row['transaction_count'],
                    number_format($row['cash_amount'], 2),
                    number_format($row['card_amount'], 2),
                    number_format($row['bank_amount'], 2),
                    number_format($row['online_amount'], 2),
                    number_format($row['total_amount'], 2)
                ]);
            }
            break;
            
        case 'guests':
            fputcsv($output, ['Name', 'Email', 'Phone', 'Reservations', 'Total Spent', 'Last Visit']);
            foreach ($report_data as $row) {
                fputcsv($output, [
                    $row['full_name'],
                    $row['email'],
                    $row['phone'],
                    $row['total_reservations'],
                    number_format($row['total_spent'], 2),
                    $row['last_visit']
                ]);
            }
            break;
            
        case 'payments':
            fputcsv($output, ['Payment Method', 'Transactions', 'Total Amount', 'Average', 'Min', 'Max']);
            foreach ($report_data as $row) {
                fputcsv($output, [
                    ucfirst($row['payment_method']),
                    $row['transaction_count'],
                    number_format($row['total_amount'], 2),
                    number_format($row['average_amount'], 2),
                    number_format($row['min_amount'], 2),
                    number_format($row['max_amount'], 2)
                ]);
            }
            break;
            
        case 'occupancy':
            fputcsv($output, ['Room Type', 'Total Rooms', 'Occupied', 'Completed Stays', 'Total Nights']);
            foreach ($report_data as $row) {
                fputcsv($output, [
                    $row['room_type'],
                    $row['total_rooms'],
                    $row['occupied_rooms'],
                    $row['completed_stays'],
                    $row['total_nights']
                ]);
            }
            break;
            
        case 'cottages':
            fputcsv($output, ['Cottage', 'Type', 'Bookings', 'Total Days', 'Revenue']);
            foreach ($report_data as $row) {
                fputcsv($output, [
                    $row['cottage_name'],
                    ucfirst($row['cottage_type']),
                    $row['total_bookings'],
                    $row['total_days'],
                    number_format($row['total_revenue'], 2)
                ]);
            }
            break;
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Veripool Admin</title>
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
        /* ===== COASTAL HARMONY THEME - REPORTS PAGE ===== */
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
            
            /* Consistent Button Colors */
            --btn-export: #2F855A;
            --btn-export-hover: #276749;
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
        
        /* Report Header */
        .report-header {
            background: var(--white);
            padding: 25px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: 30px;
            position: relative;
            z-index: 2;
        }
        
        .report-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.8rem;
            color: var(--gray-900);
            margin-bottom: 5px;
        }
        
        .report-period {
            color: var(--gray-600);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .report-period i {
            color: var(--blue-500);
        }
        
        /* Report Type Buttons */
        .report-types {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 30px;
            position: relative;
            z-index: 2;
        }
        
        .report-type-btn {
            background: var(--white);
            padding: 20px 15px;
            border-radius: 16px;
            text-align: center;
            text-decoration: none;
            color: var(--gray-700);
            font-weight: 600;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        
        .report-type-btn:hover {
            border-color: var(--blue-500);
            color: var(--blue-500);
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .report-type-btn.active {
            background: var(--blue-500);
            color: white;
            border-color: var(--blue-500);
            box-shadow: 0 4px 10px rgba(43, 111, 139, 0.2);
        }
        
        .report-type-btn i {
            font-size: 2rem;
            color: var(--blue-500);
        }
        
        .report-type-btn.active i {
            color: white;
        }
        
        .report-type-btn span {
            font-size: 0.9rem;
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
            min-width: 150px;
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
        
        .btn-export {
            background: var(--btn-export);
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
        }
        
        .btn-export:hover {
            background: var(--btn-export-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            position: relative;
            z-index: 2;
        }
        
        .summary-card {
            background: var(--white);
            padding: 20px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            border-top: 4px solid var(--blue-500);
            transition: all 0.3s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .summary-card .label {
            color: var(--gray-600);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .summary-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            font-family: 'Montserrat', sans-serif;
            line-height: 1.2;
        }
        
        .summary-card .sub-value {
            color: var(--blue-500);
            font-size: 0.9rem;
            margin-top: 5px;
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
            font-size: 0.85rem;
        }
        
        table th {
            text-align: left;
            padding: 12px 8px;
            background: var(--gray-100);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
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
        
        /* Responsive */
        @media (max-width: 1200px) {
            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 992px) {
            .filter-section form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .report-types {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }
            
            .report-types {
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
        <div class="top-bar">
            <h1>
                <i class="fas fa-chart-bar"></i>
                Reports
            </h1>
            <div class="date-info">
                <span><i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?></span>
            </div>
        </div>
        
        <!-- Report Type Buttons -->
        <div class="report-types">
            <a href="?type=revenue&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-type-btn <?php echo $report_type == 'revenue' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span>Revenue</span>
            </a>
            <a href="?type=occupancy&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-type-btn <?php echo $report_type == 'occupancy' ? 'active' : ''; ?>">
                <i class="fas fa-bed"></i>
                <span>Occupancy</span>
            </a>
            <a href="?type=guests&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-type-btn <?php echo $report_type == 'guests' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span>Guests</span>
            </a>
            <a href="?type=payments&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-type-btn <?php echo $report_type == 'payments' ? 'active' : ''; ?>">
                <i class="fas fa-credit-card"></i>
                <span>Payments</span>
            </a>
            <a href="?type=cottages&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="report-type-btn <?php echo $report_type == 'cottages' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>Cottages</span>
            </a>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET">
                <input type="hidden" name="type" value="<?php echo $report_type; ?>">
                
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> Date From</label>
                    <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> Date To</label>
                    <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i> Generate
                    </button>
                </div>
                <div class="filter-group" style="text-align: right;">
                    <a href="?type=<?php echo $report_type; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&export=csv" 
                       class="btn-export">
                        <i class="fas fa-download"></i> Export CSV
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Report Header -->
        <div class="report-header">
            <h2 class="report-title"><?php echo $report_title; ?></h2>
            <div class="report-period">
                <i class="far fa-calendar-alt"></i>
                <?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <?php if ($report_type == 'revenue'): ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Revenue</div>
                <div class="value">₱<?php echo number_format($report_total, 2); ?></div>
                <div class="sub-value"><?php echo count($report_data); ?> days</div>
            </div>
            <div class="summary-card">
                <div class="label">Average Daily</div>
                <div class="value">₱<?php echo count($report_data) > 0 ? number_format($report_total / count($report_data), 2) : '0.00'; ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Transactions</div>
                <div class="value"><?php 
                    $total_trans = 0;
                    foreach ($report_data as $row) $total_trans += $row['transaction_count'];
                    echo $total_trans;
                ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($report_type == 'occupancy'): ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Rooms</div>
                <div class="value"><?php echo $total_rooms; ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Currently Occupied</div>
                <div class="value"><?php echo $occupied_now; ?></div>
                <div class="sub-value"><?php echo $total_rooms > 0 ? round(($occupied_now / $total_rooms) * 100, 1) : 0; ?>% occupancy</div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($report_type == 'guests'): ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Guests</div>
                <div class="value"><?php echo $total_guests; ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Active (30 days)</div>
                <div class="value"><?php echo $active_guests; ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($report_type == 'payments'): ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Transactions</div>
                <div class="value"><?php echo $total_transactions; ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Amount</div>
                <div class="value">₱<?php echo number_format($total_amount, 2); ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Average Transaction</div>
                <div class="value">₱<?php echo $total_transactions > 0 ? number_format($total_amount / $total_transactions, 2) : '0.00'; ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($report_type == 'cottages'): ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Bookings</div>
                <div class="value"><?php echo $total_bookings; ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Cottages</div>
                <div class="value"><?php echo count($report_data); ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Report Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Report Details</h3>
                <span class="badge"><?php echo count($report_data); ?> records</span>
            </div>
            <div class="card-body">
                <?php if (empty($report_data)): ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-bar"></i>
                        <p>No data available for the selected period.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <?php if ($report_type == 'revenue'): ?>
                                        <th>Date</th>
                                        <th>Transactions</th>
                                        <th>Cash</th>
                                        <th>Card</th>
                                        <th>Bank Transfer</th>
                                        <th>Online</th>
                                        <th>Total</th>
                                    <?php elseif ($report_type == 'occupancy'): ?>
                                        <th>Room Type</th>
                                        <th>Total Rooms</th>
                                        <th>Occupied</th>
                                        <th>Completed Stays</th>
                                        <th>Total Nights</th>
                                        <th>Occupancy Rate</th>
                                    <?php elseif ($report_type == 'guests'): ?>
                                        <th>Guest Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Reservations</th>
                                        <th>Total Spent</th>
                                        <th>Last Visit</th>
                                    <?php elseif ($report_type == 'payments'): ?>
                                        <th>Payment Method</th>
                                        <th>Transactions</th>
                                        <th>Total Amount</th>
                                        <th>Average</th>
                                        <th>Min</th>
                                        <th>Max</th>
                                    <?php elseif ($report_type == 'cottages'): ?>
                                        <th>Cottage</th>
                                        <th>Type</th>
                                        <th>Bookings</th>
                                        <th>Total Days</th>
                                        <th>Revenue</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <?php if ($report_type == 'revenue'): ?>
                                        <td><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                                        <td><?php echo $row['transaction_count']; ?></td>
                                        <td>₱<?php echo number_format($row['cash_amount'], 2); ?></td>
                                        <td>₱<?php echo number_format($row['card_amount'], 2); ?></td>
                                        <td>₱<?php echo number_format($row['bank_amount'], 2); ?></td>
                                        <td>₱<?php echo number_format($row['online_amount'], 2); ?></td>
                                        <td><strong>₱<?php echo number_format($row['total_amount'], 2); ?></strong></td>
                                    <?php elseif ($report_type == 'occupancy'): ?>
                                        <td><strong><?php echo $row['room_type']; ?></strong></td>
                                        <td><?php echo $row['total_rooms']; ?></td>
                                        <td><?php echo $row['occupied_rooms']; ?></td>
                                        <td><?php echo $row['completed_stays']; ?></td>
                                        <td><?php echo $row['total_nights']; ?></td>
                                        <td>
                                            <?php 
                                            $rate = $row['total_rooms'] > 0 ? round(($row['occupied_rooms'] / $row['total_rooms']) * 100, 1) : 0;
                                            echo $rate . '%';
                                            ?>
                                        </td>
                                    <?php elseif ($report_type == 'guests'): ?>
                                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['email']); ?></td>
                                        <td><?php echo $row['phone'] ?: '—'; ?></td>
                                        <td><?php echo $row['total_reservations']; ?></td>
                                        <td>₱<?php echo number_format($row['total_spent'], 2); ?></td>
                                        <td><?php echo $row['last_visit'] ? date('M d, Y', strtotime($row['last_visit'])) : 'Never'; ?></td>
                                    <?php elseif ($report_type == 'payments'): ?>
                                        <td><strong><?php echo ucfirst(str_replace('_', ' ', $row['payment_method'])); ?></strong></td>
                                        <td><?php echo $row['transaction_count']; ?></td>
                                        <td>₱<?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td>₱<?php echo number_format($row['average_amount'], 2); ?></td>
                                        <td>₱<?php echo number_format($row['min_amount'], 2); ?></td>
                                        <td>₱<?php echo number_format($row['max_amount'], 2); ?></td>
                                    <?php elseif ($report_type == 'cottages'): ?>
                                        <td><strong><?php echo htmlspecialchars($row['cottage_name']); ?></strong></td>
                                        <td><?php echo ucfirst($row['cottage_type']); ?></td>
                                        <td><?php echo $row['total_bookings']; ?></td>
                                        <td><?php echo $row['total_days']; ?></td>
                                        <td>₱<?php echo number_format($row['total_revenue'], 2); ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
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