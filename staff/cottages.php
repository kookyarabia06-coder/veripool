<?php
/**
 * Veripool Reservation System - Staff Cottages Page
 * Manage cottage bookings
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

// Get current user
$user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Get all cottages with their status
$cottages = $db->getRows("
    SELECT c.*, 
           (SELECT COUNT(*) FROM reservation_cottages rc 
            JOIN reservations r ON rc.reservation_id = r.id 
            WHERE rc.cottage_id = c.id AND r.status = 'checked_in') as occupied
    FROM cottages c
    ORDER BY c.id
");

// Get today's cottage bookings
$today = date('Y-m-d');
$today_bookings = $db->getRows("
    SELECT rc.*, r.reservation_number, u.full_name as guest_name, u.phone, c.cottage_name
    FROM reservation_cottages rc
    JOIN reservations r ON rc.reservation_id = r.id
    JOIN users u ON r.user_id = u.id
    JOIN cottages c ON rc.cottage_id = c.id
    WHERE DATE(rc.created_at) = ?
    ORDER BY rc.created_at DESC
", [$today]);

// Get active cottage bookings
$active_bookings = $db->getRows("
    SELECT rc.*, r.reservation_number, u.full_name as guest_name, u.phone, 
           c.cottage_name, c.cottage_type, r.check_in_date, r.check_out_date,
           DATEDIFF(r.check_out_date, CURDATE()) as days_remaining
    FROM reservation_cottages rc
    JOIN reservations r ON rc.reservation_id = r.id
    JOIN users u ON r.user_id = u.id
    JOIN cottages c ON rc.cottage_id = c.id
    WHERE r.status = 'checked_in'
    ORDER BY r.check_out_date ASC
");

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Update cottage status
        if ($_POST['action'] === 'update_status' && isset($_POST['cottage_id'])) {
            $cottage_id = (int)$_POST['cottage_id'];
            $new_status = sanitize($_POST['status']);
            
            $db->update('cottages', 
                ['status' => $new_status], 
                'id = :id', 
                ['id' => $cottage_id]
            );
            
            $message = "Cottage status updated successfully";
            $message_type = 'success';
        }
        
        // Check-out cottage guest
        if ($_POST['action'] === 'checkout_cottage' && isset($_POST['cottage_booking_id'])) {
            $cottage_booking_id = (int)$_POST['cottage_booking_id'];
            
            $booking = $db->getRow("SELECT reservation_id, cottage_id FROM reservation_cottages WHERE id = ?", [$cottage_booking_id]);
            
            if ($booking) {
                $db->update('reservations', 
                    ['status' => 'checked_out'], 
                    'id = :id', 
                    ['id' => $booking['reservation_id']]
                );
                
                $db->update('cottages', 
                    ['status' => 'available'], 
                    'id = :id', 
                    ['id' => $booking['cottage_id']]
                );
                
                $message = "Cottage guest checked out successfully";
                $message_type = 'success';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cottages - Staff Portal</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/staff.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .cottage-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .cottage-status-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
        }
        
        .cottage-status-card h3 {
            color: #102C57;
            margin-bottom: 10px;
        }
        
        .cottage-status-card .price {
            font-size: 1.3rem;
            font-weight: bold;
            color: #1679AB;
            margin-bottom: 10px;
        }
        
        .status-select {
            width: 100%;
            padding: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        
        .update-btn {
            width: 100%;
            padding: 8px;
            background: #1679AB;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .update-btn:hover {
            background: #102C57;
        }
        
        .occupied-badge {
            background: #cce5ff;
            color: #004085;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
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
                <i class="fas fa-home"></i>
                Cottages Management
            </h1>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Cottage Status Grid -->
        <h3 style="margin-bottom: 15px; color: #102C57;">Cottage Status</h3>
        <div class="cottage-grid">
            <?php foreach ($cottages as $cottage): ?>
            <div class="cottage-status-card">
                <h3><?php echo htmlspecialchars($cottage['cottage_name']); ?></h3>
                <div class="price">₱<?php echo number_format($cottage['price'], 2); ?>/day</div>
                <p><strong>Type:</strong> <?php echo ucfirst($cottage['cottage_type']); ?></p>
                <p><strong>Capacity:</strong> <?php echo $cottage['capacity']; ?> guests</p>
                <p><strong>Status:</strong> 
                    <?php if ($cottage['occupied'] > 0): ?>
                        <span class="occupied-badge"><i class="fas fa-user"></i> Occupied</span>
                    <?php else: ?>
                        <span class="status-badge status-<?php echo $cottage['status']; ?>">
                            <?php echo ucfirst($cottage['status']); ?>
                        </span>
                    <?php endif; ?>
                </p>
                
                <?php if ($cottage['occupied'] == 0): ?>
                <form method="POST" style="margin-top: 15px;">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="cottage_id" value="<?php echo $cottage['id']; ?>">
                    <select name="status" class="status-select">
                        <option value="available" <?php echo $cottage['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                        <option value="maintenance" <?php echo $cottage['status'] == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="unavailable" <?php echo $cottage['status'] == 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
                    </select>
                    <button type="submit" class="update-btn">Update Status</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Today's Cottage Bookings -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-calendar-day"></i> Today's Cottage Bookings</h3>
                <span class="badge"><?php echo count($today_bookings); ?> bookings</span>
            </div>
            <div class="card-body">
                <?php if (empty($today_bookings)): ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No cottage bookings for today.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reservation #</th>
                                    <th>Guest</th>
                                    <th>Cottage</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($today_bookings as $booking): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($booking['reservation_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['cottage_name']); ?></td>
                                    <td><?php echo date('h:i A', strtotime($booking['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Active Cottage Guests -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-users"></i> Currently in Cottages</h3>
                <span class="badge"><?php echo count($active_bookings); ?> guests</span>
            </div>
            <div class="card-body">
                <?php if (empty($active_bookings)): ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No guests currently in cottages.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Cottage</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Days Left</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_bookings as $booking): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($booking['guest_name']); ?></strong>
                                        <br><small><?php echo $booking['phone']; ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($booking['cottage_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></td>
                                    <td>
                                        <?php 
                                        $days = $booking['days_remaining'];
                                        if ($days < 0) {
                                            echo "<span style='color: #dc3545;'>Overdue</span>";
                                        } elseif ($days == 0) {
                                            echo "<span style='color: #ffc107;'>Today</span>";
                                        } else {
                                            echo $days . " days";
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($booking['days_remaining'] <= 0): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="checkout_cottage">
                                                <input type="hidden" name="cottage_booking_id" value="<?php echo $booking['id']; ?>">
                                                <button type="submit" class="btn-sm btn-warning" onclick="return confirm('Check out this guest?')">
                                                    Check Out
                                                </button>
                                            </form>
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