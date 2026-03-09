<?php
/**
 * Veripool Reservation System - Guest View Entry Pass
 * Flexible version that adapts to your database structure
 */

// Enable error reporting for debugging
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

try {
    // Get database instance
    $db = Database::getInstance();
    
    // Get current user
    $current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
    
    // Get pass ID from URL
    $pass_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if (!$pass_id) {
        die("No pass ID provided");
    }
    
    // First, check what columns exist in reservations table
    $res_columns = [];
    $col_check = $db->getRows("SHOW COLUMNS FROM reservations");
    foreach ($col_check as $col) {
        $res_columns[] = $col['Field'];
    }
    
    // Build the SELECT part dynamically based on existing columns
    $select_fields = "r.reservation_number, r.check_in_date, r.check_out_date, r.total_amount, r.status as reservation_status";
    
    // Add optional fields if they exist
    if (in_array('guests', $res_columns)) {
        $select_fields .= ", r.guests";
    }
    if (in_array('room_id', $res_columns)) {
        $select_fields .= ", r.room_id";
    }
    if (in_array('cottage_id', $res_columns)) {
        $select_fields .= ", r.cottage_id";
    }
    if (in_array('pool_id', $res_columns)) {
        $select_fields .= ", r.pool_id";
    }
    
    // Get entry pass with available fields
    $query = "
        SELECT ep.*, 
               $select_fields,
               u.full_name,
               u.email,
               u.phone
        FROM entry_passes ep
        JOIN reservations r ON ep.reservation_id = r.id
        JOIN users u ON ep.user_id = u.id
        WHERE ep.id = ? AND ep.user_id = ?";
    
    $pass = $db->getRow($query, [$pass_id, $_SESSION['user_id']]);
    
    // If pass not found or doesn't belong to user, redirect
    if (!$pass) {
        $_SESSION['error'] = "Entry pass not found or you don't have permission to view it.";
        header("Location: dashboard.php");
        exit;
    }
    
    // Get facility name based on what's available
    $facility_name = 'Not specified';
    $facility_type = '';
    $number_of_guests = isset($pass['guests']) ? $pass['guests'] : 1;
    
    // Try to get facility info from various possible columns
    if (!empty($pass['room_id'])) {
        $room = $db->getRow("SELECT room_number FROM rooms WHERE id = ?", [$pass['room_id']]);
        $facility_name = $room ? 'Room ' . $room['room_number'] : 'Room #' . $pass['room_id'];
        $facility_type = 'room';
    } elseif (!empty($pass['cottage_id'])) {
        $cottage = $db->getRow("SELECT name FROM cottages WHERE id = ?", [$pass['cottage_id']]);
        $facility_name = $cottage ? $cottage['name'] : 'Cottage #' . $pass['cottage_id'];
        $facility_type = 'cottage';
    } elseif (!empty($pass['pool_id'])) {
        $pool = $db->getRow("SELECT name FROM pools WHERE id = ?", [$pass['pool_id']]);
        $facility_name = $pool ? $pool['name'] : 'Pool #' . $pass['pool_id'];
        $facility_type = 'pool';
    } else {
        // Try to get from reservation_facilities if that table exists
        $rf_exists = $db->getRow("SHOW TABLES LIKE 'reservation_facilities'");
        if ($rf_exists) {
            $facility = $db->getRow("
                SELECT rf.name, rf.type 
                FROM reservation_facilities rf 
                WHERE rf.reservation_id = ? 
                LIMIT 1
            ", [$pass['reservation_id']]);
            if ($facility) {
                $facility_name = $facility['name'];
                $facility_type = $facility['type'];
            }
        }
    }
    
    $pass['facility_name'] = $facility_name;
    $pass['facility_type'] = $facility_type;
    $pass['guests'] = $number_of_guests;
    
    // Calculate days until check-in
    $today = new DateTime();
    $check_in = new DateTime($pass['check_in_date']);
    $days_until_checkin = $today->diff($check_in)->days;
    if ($check_in < $today) {
        $days_until_checkin = -$days_until_checkin;
    }
    
    // Check if pass is valid
    $now = new DateTime();
    $valid_from = new DateTime($pass['valid_from']);
    $valid_until = new DateTime($pass['valid_until']);
    
    $is_valid = ($now >= $valid_from && $now <= $valid_until && $pass['status'] == 'active');
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage() . "<br>Please run the debug script to check your database structure.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Entry Pass - Veripool</title>
    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo BASE_URL; ?>/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo BASE_URL; ?>/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>/assets/favicon/site.webmanifest">
    
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
        }
        
        .pass-container {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .pass-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            border: 1px solid #102C57;
        }
        
        .pass-header {
            background: linear-gradient(135deg, #102C57, #1e3a6b);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .pass-header h1 {
            margin: 10px 0 5px;
            font-size: 28px;
        }
        
        .pass-header i {
            font-size: 50px;
            color: #FFCBCB;
        }
        
        .pass-header .subtitle {
            color: #FFCBCB;
            font-size: 14px;
            opacity: 0.9;
        }
        
        .pass-status {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-active {
            background: #28a745;
            color: white;
        }
        
        .status-used {
            background: #dc3545;
            color: white;
        }
        
        .status-expired {
            background: #ffc107;
            color: #102C57;
        }
        
        .pass-body {
            padding: 30px;
        }
        
        .guest-info {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .guest-info h2 {
            margin: 0;
            color: #102C57;
            font-size: 24px;
        }
        
        .guest-info p {
            margin: 5px 0 0;
            color: #666;
            font-size: 14px;
        }
        
        .otp-display {
            text-align: center;
            margin: 30px 0;
            padding: 30px 20px;
            background: #f8f9fa;
            border-radius: 15px;
            border: 3px dashed #102C57;
            position: relative;
        }
        
        .otp-label {
            font-size: 12px;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 2px;
            margin-bottom: 10px;
        }
        
        .otp-code {
            font-family: 'Courier New', monospace;
            font-size: 48px;
            font-weight: bold;
            color: #102C57;
            letter-spacing: 10px;
            line-height: 1.2;
            word-break: break-all;
        }
        
        .copy-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: #102C57;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .copy-btn:hover {
            background: #1679AB;
            transform: translateY(-50%) scale(1.1);
        }
        
        .pass-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            margin: 30px 0;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #666;
            font-weight: 500;
        }
        
        .detail-value {
            color: #102C57;
            font-weight: bold;
        }
        
        .facility-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 10px;
        }
        
        .facility-room {
            background: #cce5ff;
            color: #004085;
        }
        
        .facility-cottage {
            background: #d4edda;
            color: #155724;
        }
        
        .facility-pool {
            background: #fff3cd;
            color: #856404;
        }
        
        .validity-info {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }
        
        .validity-info i {
            color: #1679AB;
            margin-right: 5px;
        }
        
        .countdown {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background: #102C57;
            color: white;
            border-radius: 10px;
        }
        
        .countdown .days {
            font-size: 48px;
            font-weight: bold;
            color: #FFCBCB;
            line-height: 1;
        }
        
        .instructions {
            background: #fff3cd;
            padding: 20px;
            border-radius: 10px;
            margin: 30px 0;
            border-left: 4px solid #ffc107;
        }
        
        .instructions h3 {
            margin-top: 0;
            color: #856404;
            font-size: 18px;
        }
        
        .instructions ul {
            margin: 10px 0 0;
            padding-left: 20px;
        }
        
        .instructions li {
            margin: 8px 0;
            color: #856404;
        }
        
        .pass-footer {
            padding: 20px 30px;
            background: #f0f0f0;
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #102C57;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1679AB;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #102C57;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            display: none;
            animation: slideIn 0.3s ease;
            z-index: 1000;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        /* Debug info - remove in production */
        .debug-info {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 12px;
            border-left: 4px solid #1679AB;
        }
        
        @media print {
            .pass-footer, .copy-btn, .toast, .debug-info {
                display: none !important;
            }
            
            .pass-card {
                box-shadow: none;
                border: 2px solid #000;
            }
            
            body {
                background: white;
                padding: 0;
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .otp-code {
                font-size: 32px;
                letter-spacing: 5px;
            }
            
            .pass-footer {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .validity-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .copy-btn {
                width: 35px;
                height: 35px;
                right: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="pass-container">
      
        
        <div class="pass-card">
            <div class="pass-header">
                <i class="fas fa-ticket-alt"></i>
                <h1>Entry Pass</h1>
                <div class="subtitle">Veripool Reservation System</div>
                
                <div class="pass-status status-<?php echo $pass['status']; ?>">
                    <?php echo strtoupper($pass['status']); ?>
                </div>
            </div>
            
            <div class="pass-body">
                <div class="guest-info">
                    <h2><?php echo htmlspecialchars($pass['full_name']); ?></h2>
                    <p><i class="fas fa-tag"></i> Reservation #: <?php echo htmlspecialchars($pass['reservation_number']); ?></p>
                </div>
                
                <div class="otp-display">
                    <div class="otp-label">ENTRY CODE</div>
                    <div class="otp-code" id="otpCode"><?php echo htmlspecialchars($pass['otp_code']); ?></div>
                    <button class="copy-btn" onclick="copyOTP()" title="Copy OTP">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                
                <div class="pass-details">
                    <div class="detail-row">
                        <span class="detail-label">Facility:</span>
                        <span class="detail-value">
                            <?php echo htmlspecialchars($facility_name); ?>
                            <?php if (!empty($facility_type)): ?>
                                <span class="facility-badge facility-<?php echo $facility_type; ?>">
                                    <?php echo ucfirst($facility_type); ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Check-in Date:</span>
                        <span class="detail-value"><?php echo date('F d, Y', strtotime($pass['check_in_date'])); ?> (2:00 PM)</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Check-out Date:</span>
                        <span class="detail-value"><?php echo date('F d, Y', strtotime($pass['check_out_date'])); ?> (12:00 PM)</span>
                    </div>
                    <?php if (isset($pass['guests']) && $pass['guests'] > 0): ?>
                    <div class="detail-row">
                        <span class="detail-label">Number of Guests:</span>
                        <span class="detail-value"><?php echo $pass['guests']; ?> persons</span>
                    </div>
                    <?php endif; ?>
                    <div class="detail-row">
                        <span class="detail-label">Total Amount:</span>
                        <span class="detail-value">₱<?php echo number_format($pass['total_amount'], 2); ?></span>
                    </div>
                </div>
                
                <div class="validity-info">
                    <span><i class="far fa-clock"></i> From: <?php echo date('M d, Y h:i A', strtotime($pass['valid_from'])); ?></span>
                    <span><i class="far fa-hourglass"></i> Until: <?php echo date('M d, Y h:i A', strtotime($pass['valid_until'])); ?></span>
                </div>
                
                <?php if (!$is_valid && $pass['status'] == 'active'): ?>
                    <div class="countdown">
                        <?php if ($now < $valid_from): ?>
                            <div class="days"><?php echo max(0, $days_until_checkin); ?></div>
                            <div>days until your reservation</div>
                            <small style="opacity: 0.9;">Valid starting <?php echo date('M d, Y', strtotime($pass['check_in_date'])); ?></small>
                        <?php elseif ($now > $valid_until): ?>
                            <i class="fas fa-exclamation-circle" style="font-size: 40px; margin-bottom: 10px;"></i>
                            <div>This pass has expired</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="instructions">
                    <h3><i class="fas fa-info-circle"></i> Important Instructions</h3>
                    <ul>
                        <li>Present this OTP code at the entrance gate</li>
                        <li>Keep this code confidential - do not share with others</li>
                        <li>The pass is valid for one-time entry only</li>
                        <li>Bring a valid ID for verification</li>
                        <li>Check-in time starts at 2:00 PM</li>
                        <li>Check-out time is 12:00 PM</li>
                    </ul>
                </div>
            </div>
            
            <div class="pass-footer">
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print Pass
                </button>
                <a href="reservations.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="dashboard.php" class="btn btn-success">
                    <i class="fas fa-home"></i> Home
                </a>
            </div>
        </div>
    </div>
    
    <div class="toast" id="toast">
        <i class="fas fa-check-circle"></i> OTP copied to clipboard!
    </div>
    
    <script>
        function copyOTP() {
            const otpText = document.getElementById('otpCode').innerText;
            
            // Use modern clipboard API if available
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(otpText).then(function() {
                    showToast();
                }).catch(function(err) {
                    fallbackCopy(otpText);
                });
            } else {
                fallbackCopy(otpText);
            }
        }
        
        function fallbackCopy(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            showToast();
        }
        
        function showToast() {
            const toast = document.getElementById('toast');
            toast.style.display = 'block';
            
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => {
                    toast.style.display = 'none';
                    toast.style.opacity = '1';
                }, 300);
            }, 2000);
        }
    </script>
</body>
</html>