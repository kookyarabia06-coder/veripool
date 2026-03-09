<?php
/**
 * Veripool Reservation System - Staff Current Guests Page
 * View all currently checked-in guests
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

// Get current guests (checked in rooms)
$current_guests = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone, u.email, 
           rm.room_number, rt.name as room_type,
           DATEDIFF(r.check_out_date, CURDATE()) as days_remaining
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE r.status = 'checked_in'
    ORDER BY r.check_out_date ASC
");

// Get current cottage guests
$current_cottage_guests = $db->getRows("
    SELECT rc.*, r.reservation_number, u.full_name as guest_name, u.phone, 
           c.cottage_name, c.cottage_type,
           r.check_in_date, r.check_out_date,
           DATEDIFF(r.check_out_date, CURDATE()) as days_remaining
    FROM reservation_cottages rc
    JOIN reservations r ON rc.reservation_id = r.id
    JOIN users u ON r.user_id = u.id
    JOIN cottages c ON rc.cottage_id = c.id
    WHERE r.status = 'checked_in'
    ORDER BY r.check_out_date ASC
");

// Get statistics
$total_room_guests = count($current_guests);
$total_cottage_guests = count($current_cottage_guests);
$total_guests = $total_room_guests + $total_cottage_guests;

// Handle check-out
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Check-out room guest
        if ($_POST['action'] === 'checkout_room' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            
            $res = $db->getRow("SELECT room_id FROM reservations WHERE id = ?", [$reservation_id]);
            
            $db->update('reservations', 
                ['status' => 'checked_out'], 
                'id = :id', 
                ['id' => $reservation_id]
            );
            
            if ($res && $res['room_id']) {
                $db->update('rooms', 
                    ['status' => 'available'], 
                    'id = :id', 
                    ['id' => $res['room_id']]
                );
            }
            
            $message = "Guest checked out successfully";
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
                
                if ($booking['cottage_id']) {
                    $db->update('cottages', 
                        ['status' => 'available'], 
                        'id = :id', 
                        ['id' => $booking['cottage_id']]
                    );
                }
                
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
    <title>Current Guests - Staff Portal</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">
    
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/staff.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i> Menu
    </button>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h2>Staff Portal</h2>
            <p><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></p>
            <small>Front Desk Staff</small>
        </div>
        
        <ul class="sidebar-menu">
            <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="checkins.php"><i class="fas fa-sign-in-alt"></i> Check-ins</a></li>
            <li><a href="checkouts.php"><i class="fas fa-sign-out-alt"></i> Check-outs</a></li>
            <li><a href="guests.php"><i class="fas fa-users"></i> Current Guests</a></li>
            <li><a href="reservations.php"><i class="fas fa-calendar-check"></i> Reservations</a></li>
            <li><a href="cottages.php"><i class="fas fa-home"></i> Cottages</a></li>
            <li><a href="walkin.php"><i class="fas fa-user-plus"></i> Walk-in</a></li>
            <li><a href="rooms.php"><i class="fas fa-bed"></i> Room Status</a></li>
            <li><a href="../index.php"><i class="fas fa-arrow-left"></i> Back to Site</a></li>
            <li class="logout"><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <h1>
                <i class="fas fa-users"></i>
                Current Guests (<?php echo $total_guests; ?>)
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
        
        <!-- Quick Stats -->
        <div class="quick-stats" style="margin-bottom: 30px;">
            <div class="quick-stat">
                <div class="label">Room Guests</div>
                <div class="value"><?php echo $total_room_guests; ?></div>
            </div>
            <div class="quick-stat">
                <div class="label">Cottage Guests</div>
                <div class="value"><?php echo $total_cottage_guests; ?></div>
            </div>
            <div class="quick-stat">
                <div class="label">Total Guests</div>
                <div class="value"><?php echo $total_guests; ?></div>
            </div>
            <div class="quick-stat">
                <div class="label">Available Rooms</div>
                <div class="value"><?php echo $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'available'"); ?></div>
            </div>
        </div>
        
        <!-- Room Guests -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-bed"></i> Guests in Rooms</h3>
                <span class="badge"><?php echo $total_room_guests; ?> active</span>
            </div>
            <div class="card-body">
                <?php if (empty($current_guests)): ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No guests currently checked in rooms.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Days Left</th>
                                    <th>OTP</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_guests as $guest): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($guest['guest_name']); ?></strong>
                                        <br><small><?php echo $guest['phone']; ?></small>
                                    </td>
                                    <td>
                                        Room <?php echo $guest['room_number'] ?: 'N/A'; ?>
                                        <br><small><?php echo $guest['room_type']; ?></small>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($guest['check_in_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($guest['check_out_date'])); ?></td>
                                    <td>
                                        <?php 
                                        $days = $guest['days_remaining'];
                                        $days_class = 'days-green';
                                        if ($days < 0) {
                                            $days_class = 'days-red';
                                            $days_text = 'Overdue';
                                        } elseif ($days == 0) {
                                            $days_class = 'days-yellow';
                                            $days_text = 'Today';
                                        } else {
                                            $days_text = $days . ' days';
                                        }
                                        ?>
                                        <span class="days-badge <?php echo $days_class; ?>">
                                            <?php echo $days_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($guest['otp_code']): ?>
                                            <span class="otp-code"><?php echo $guest['otp_code']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($guest['days_remaining'] <= 0): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="checkout_room">
                                                <input type="hidden" name="reservation_id" value="<?php echo $guest['id']; ?>">
                                                <button type="submit" class="btn-sm btn-warning" onclick="return confirm('Check out this guest?')">
                                                    <i class="fas fa-sign-out-alt"></i> Check Out
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
        
        <!-- Cottage Guests -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-home"></i> Guests in Cottages</h3>
                <span class="badge"><?php echo $total_cottage_guests; ?> active</span>
            </div>
            <div class="card-body">
                <?php if (empty($current_cottage_guests)): ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No guests currently in cottages.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Cottage</th>
                                    <th>Type</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Days Left</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_cottage_guests as $guest): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($guest['guest_name']); ?></strong>
                                        <br><small><?php echo $guest['phone']; ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($guest['cottage_name']); ?></td>
                                    <td>
                                        <span class="status-badge" style="background: #FFB1B1; color: #102C57;">
                                            <?php echo ucfirst($guest['cottage_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($guest['check_in_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($guest['check_out_date'])); ?></td>
                                    <td>
                                        <?php 
                                        $days = $guest['days_remaining'];
                                        $days_class = 'days-green';
                                        if ($days < 0) {
                                            $days_class = 'days-red';
                                            $days_text = 'Overdue';
                                        } elseif ($days == 0) {
                                            $days_class = 'days-yellow';
                                            $days_text = 'Today';
                                        } else {
                                            $days_text = $days . ' days';
                                        }
                                        ?>
                                        <span class="days-badge <?php echo $days_class; ?>">
                                            <?php echo $days_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($guest['days_remaining'] <= 0): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="checkout_cottage">
                                                <input type="hidden" name="cottage_booking_id" value="<?php echo $guest['id']; ?>">
                                                <button type="submit" class="btn-sm btn-warning" onclick="return confirm('Check out this guest?')">
                                                    <i class="fas fa-sign-out-alt"></i> Check Out
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