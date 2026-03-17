<?php
/**
 * Veripool Reservation System - Admin Dashboard
 * Dashboard view showing recent online and walk-in reservations
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
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin', 'staff'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get current user
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// ========== DASHBOARD STATISTICS ==========
$today = date('Y-m-d');

// Get recent online reservations (last 7 days)
$recent_online = $db->getRows("
    SELECT r.*, 
           u.full_name as guest_name, 
           u.phone as guest_phone,
           rm.room_number,
           rt.name as room_type,
           (SELECT COUNT(*) FROM payments WHERE reservation_id = r.id) as payment_count,
           ep.otp_code,
           ep.status as pass_status,
           DATEDIFF(r.check_in_date, CURDATE()) as days_until_checkin
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND (r.created_by IS NULL OR r.created_by != 'walkin')
    ORDER BY 
        CASE 
            WHEN r.status = 'pending' THEN 1
            WHEN r.status = 'confirmed' THEN 2
            WHEN r.status = 'checked_in' THEN 3
            WHEN r.status = 'checked_out' THEN 4
            ELSE 5
        END,
        r.created_at DESC
    LIMIT 10
");

// Get recent walk-in reservations (last 7 days)
$recent_walkin = $db->getRows("
    SELECT r.*, 
           u.full_name as guest_name, 
           u.phone as guest_phone,
           rm.room_number,
           rt.name as room_type,
           DATEDIFF(r.check_in_date, CURDATE()) as days_until_checkin
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND r.created_by = 'walkin'
    ORDER BY 
        CASE 
            WHEN r.status = 'pending' THEN 1
            WHEN r.status = 'confirmed' THEN 2
            WHEN r.status = 'checked_in' THEN 3
            WHEN r.status = 'checked_out' THEN 4
            ELSE 5
        END,
        r.created_at DESC
    LIMIT 10
");

// Get quick stats for header
$online_count = count($recent_online);
$walkin_count = count($recent_walkin);
$pending_count = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'pending'") ?: 0;
$confirmed_count = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'confirmed'") ?: 0;
$checked_in_count = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'checked_in'") ?: 0;

// ========== HANDLE ACTIONS ==========
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Update reservation status (quick actions from dashboard)
        if ($_POST['action'] === 'quick_update' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            $new_status = sanitize($_POST['status']);
            
            // Get current reservation data
            $reservation = $db->getRow("SELECT * FROM reservations WHERE id = ?", [$reservation_id]);
            
            if ($reservation) {
                // For online reservations, prevent direct check-in without OTP
                if ($new_status === 'checked_in' && $reservation['created_by'] != 'walkin') {
                    $message = "Online reservations require OTP verification";
                    $message_type = 'error';
                } else {
                    // Update reservation status
                    $db->update('reservations', 
                        ['status' => $new_status], 
                        'id = :id', 
                        ['id' => $reservation_id]
                    );
                    
                    // Update room status if checking in/out
                    if ($new_status === 'checked_in' && $reservation['room_id']) {
                        $db->update('rooms', 
                            ['status' => 'occupied'], 
                            'id = :id', 
                            ['id' => $reservation['room_id']]
                        );
                    }
                    
                    if ($new_status === 'checked_out' && $reservation['room_id']) {
                        $db->update('rooms', 
                            ['status' => 'available'], 
                            'id = :id', 
                            ['id' => $reservation['room_id']]
                        );
                    }
                    
                    $message = "Reservation status updated successfully";
                    $message_type = 'success';
                }
            }
        }
        
        // Refresh data
        header("Location: index.php");
        exit;
    }
}

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    switch($status) {
        case 'pending': return 'status-pending';
        case 'confirmed': return 'status-confirmed';
        case 'checked_in': return 'status-checked_in';
        case 'checked_out': return 'status-checked_out';
        case 'cancelled': return 'status-cancelled';
        default: return 'status-pending';
    }
}

// Helper function to get status display text
function getStatusDisplay($status) {
    switch($status) {
        case 'checked_in': return 'In';
        case 'checked_out': return 'Out';
        case 'confirmed': return 'Conf';
        case 'pending': return 'Pen';
        case 'cancelled': return 'Canc';
        default: return substr($status, 0, 3);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Veripool Admin</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Sidebar CSS (already updated with Coastal Harmony theme) -->
    <link rel="stylesheet" href="/assets/css/sidebar.css">
    
    <style>
        /* ===== COASTAL HARMONY THEME - ADMIN DASHBOARD ===== */
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
        
        /* Main Content Layout - Fixed sidebar width */
        .main-content {
            margin-left: 280px; /* Same as sidebar width */
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 40px;
            position: relative;
            z-index: 2;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, transparent 50%, var(--gray-100) 50%);
            opacity: 0.5;
        }
        
        .stat-card.online {
            border-top: 4px solid var(--blue-500);
        }
        
        .stat-card.walkin {
            border-top: 4px solid var(--green-500);
        }
        
        .stat-card.pending {
            border-top: 4px solid #ED8936;
        }
        
        .stat-card.active {
            border-top: 4px solid var(--green-500);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-title {
            color: var(--gray-600);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stat-title i {
            color: var(--blue-500);
            font-size: 1rem;
        }
        
        .stat-number {
            color: var(--gray-900);
            font-size: 2.5rem;
            font-weight: 700;
            font-family: 'Montserrat', sans-serif;
            line-height: 1;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--gray-500);
            font-size: 0.8rem;
        }
        
        .stat-icon {
            position: absolute;
            bottom: 15px;
            right: 15px;
            font-size: 3rem;
            color: var(--gray-200);
            z-index: 0;
        }
        
        /* Reservations Grid */
        .reservations-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-top: 20px;
            position: relative;
            z-index: 2;
        }
        
        .reservation-card {
            background: var(--white);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .reservation-card:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .reservation-card .card-header {
            padding: 15px 20px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Montserrat', sans-serif;
        }
        
        .reservation-card.online .card-header {
            background: linear-gradient(135deg, var(--blue-500), var(--blue-600));
            color: white;
        }
        
        .reservation-card.walkin .card-header {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
        }
        
        .reservation-card .card-header i {
            margin-right: 8px;
        }
        
        .reservation-card .card-header .badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 500;
        }
        
        .reservation-card .card-body {
            padding: 20px;
            max-height: 500px;
            overflow-y: auto;
            background: var(--white);
        }
        
        .reservation-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        
        .reservation-table th {
            text-align: left;
            padding: 12px 8px;
            background: var(--gray-100);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .reservation-table td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--gray-200);
            vertical-align: middle;
            color: var(--gray-700);
        }
        
        .reservation-table tr:hover td {
            background: var(--gray-100);
        }
        
        .guest-info {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .room-number {
            font-family: monospace;
            background: var(--gray-100);
            padding: 3px 6px;
            border-radius: 6px;
            font-size: 0.7rem;
            border: 1px solid var(--gray-200);
        }
        
        .date-badge {
            background: var(--gray-100);
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            color: var(--gray-700);
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
        
        .status-confirmed { 
            background: #DEF7EC; 
            color: var(--green-700); 
        }
        
        .status-checked_in { 
            background: #E1EFFE; 
            color: var(--blue-700); 
        }
        
        .status-checked_out { 
            background: var(--gray-200); 
            color: var(--gray-700); 
        }
        
        .status-cancelled { 
            background: #FEE2E2; 
            color: #B91C1C; 
        }
        
        .otp-badge {
            background: var(--gray-900);
            color: var(--gray-100);
            padding: 3px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 1px;
        }
        
        .action-select {
            padding: 6px 8px;
            font-size: 0.7rem;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            color: var(--gray-700);
            background: var(--white);
            cursor: pointer;
            transition: all 0.3s ease;
            width: 70px;
        }
        
        .action-select:focus {
            outline: none;
            border-color: var(--blue-500);
            box-shadow: 0 0 0 3px rgba(43, 111, 139, 0.1);
        }
        
        .action-select.online option[value="checked_in"] {
            color: var(--gray-400);
        }
        
        .view-all-link {
            text-align: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-200);
        }
        
        .view-all-link a {
            color: var(--blue-500);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .view-all-link a:hover {
            color: var(--green-500);
            gap: 12px;
        }
        
        /* Responsive - No mobile support as requested */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 992px) {
            .reservations-grid {
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
                <i class="fas fa-calendar-check"></i>
                Dashboard
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
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card online">
                <div class="stat-title">
                    <i class="fas fa-globe"></i> Online
                </div>
                <div class="stat-number"><?php echo $online_count; ?></div>
                <div class="stat-label">recent reservations</div>
                <div class="stat-icon"><i class="fas fa-globe"></i></div>
            </div>
            
            <div class="stat-card walkin">
                <div class="stat-title">
                    <i class="fas fa-user-plus"></i> Walk-in
                </div>
                <div class="stat-number"><?php echo $walkin_count; ?></div>
                <div class="stat-label">recent reservations</div>
                <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
            </div>
            
            <div class="stat-card pending">
                <div class="stat-title">
                    <i class="fas fa-clock"></i> Pending
                </div>
                <div class="stat-number"><?php echo $pending_count; ?></div>
                <div class="stat-label">awaiting confirmation</div>
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
            </div>
            
            <div class="stat-card active">
                <div class="stat-title">
                    <i class="fas fa-check-circle"></i> Checked In
                </div>
                <div class="stat-number"><?php echo $checked_in_count; ?></div>
                <div class="stat-label">current guests</div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
        
        <!-- Recent Reservations - Split View -->
        <div class="reservations-grid">
            <!-- Online Reservations -->
            <div class="reservation-card online">
                <div class="card-header">
                    <span><i class="fas fa-globe"></i> Recent Online Reservations</span>
                    <span class="badge">Last 7 days</span>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_online)): ?>
                        <div class="empty-state">
                            <i class="fas fa-globe"></i>
                            <p>No online reservations found</p>
                        </div>
                    <?php else: ?>
                        <table class="reservation-table">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>OTP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_online as $res): ?>
                                <tr>
                                    <td>
                                        <span class="guest-info"><?php echo htmlspecialchars(substr($res['guest_name'], 0, 15)); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($res['room_number']): ?>
                                            <span class="room-number"><?php echo $res['room_number']; ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="date-badge"><?php echo date('m/d', strtotime($res['check_in_date'])); ?></span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo getStatusBadgeClass($res['status']); ?>">
                                            <?php echo getStatusDisplay($res['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($res['otp_code'])): ?>
                                            <span class="otp-badge"><?php echo $res['otp_code']; ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400);">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="view-all-link">
                            <a href="reservations.php?type=online"><i class="fas fa-arrow-right"></i> View All Online Reservations</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Walk-in Reservations -->
            <div class="reservation-card walkin">
                <div class="card-header">
                    <span><i class="fas fa-user-plus"></i> Recent Walk-in Reservations</span>
                    <span class="badge">Last 7 days</span>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_walkin)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-plus"></i>
                            <p>No walk-in reservations found</p>
                        </div>
                    <?php else: ?>
                        <table class="reservation-table">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_walkin as $res): ?>
                                <tr>
                                    <td>
                                        <span class="guest-info"><?php echo htmlspecialchars(substr($res['guest_name'], 0, 15)); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($res['room_number']): ?>
                                            <span class="room-number"><?php echo $res['room_number']; ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="date-badge"><?php echo date('m/d', strtotime($res['check_in_date'])); ?></span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo getStatusBadgeClass($res['status']); ?>">
                                            <?php echo getStatusDisplay($res['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="quick_update">
                                            <input type="hidden" name="reservation_id" value="<?php echo $res['id']; ?>">
                                            <select name="status" class="action-select" onchange="this.form.submit()">
                                                <option value="pending" <?php echo $res['status'] == 'pending' ? 'selected' : ''; ?>>Pen</option>
                                                <option value="confirmed" <?php echo $res['status'] == 'confirmed' ? 'selected' : ''; ?>>Conf</option>
                                                <option value="checked_in" <?php echo $res['status'] == 'checked_in' ? 'selected' : ''; ?>>In</option>
                                                <option value="checked_out" <?php echo $res['status'] == 'checked_out' ? 'selected' : ''; ?>>Out</option>
                                                <option value="cancelled" <?php echo $res['status'] == 'cancelled' ? 'selected' : ''; ?>>Canc</option>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="view-all-link">
                            <a href="reservations.php?type=walkin"><i class="fas fa-arrow-right"></i> View All Walk-in Reservations</a>
                        </div>
                    <?php endif; ?>
                </div>
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