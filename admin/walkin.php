<?php
/**
 * Veripool Reservation System - Admin Walk-in Page
 * Walk-in reservations management with actions - No OTP for walk-ins
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

// Get current user (for sidebar)
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Get filter parameters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build query for filtered walk-ins
$where = "WHERE r.created_by = 'walkin'";
$params = [];

if ($status_filter != 'all') {
    $where .= " AND r.status = :status";
    $params['status'] = $status_filter;
}

if (isset($_GET['date_from']) && isset($_GET['date_to'])) {
    $where .= " AND DATE(r.created_at) BETWEEN :date_from AND :date_to";
    $params['date_from'] = $date_from;
    $params['date_to'] = $date_to;
}

// FIXED: Get filtered walk-in reservations - USING CORRECT COLUMN NAME 'total_amount'
$filtered_walkins = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone, u.email, rm.room_number, rt.name as room_type,
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id) as payment_paid,
           (SELECT COALESCE(SUM(total_amount), 0) FROM entrance_fee_payments WHERE reservation_id = r.id) as entrance_fee_paid,
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id) + 
           (SELECT COALESCE(SUM(total_amount), 0) FROM entrance_fee_payments WHERE reservation_id = r.id) as total_paid
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    $where
    ORDER BY r.created_at DESC
", $params);

// FIXED: Get today's walk-in reservations - USING CORRECT COLUMN NAME 'total_amount'
$today = date('Y-m-d');
$today_walkins = $db->getRows("
    SELECT r.*, u.full_name as guest_name, u.phone, u.email, rm.room_number, rt.name as room_type,
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id) as payment_paid,
           (SELECT COALESCE(SUM(total_amount), 0) FROM entrance_fee_payments WHERE reservation_id = r.id) as entrance_fee_paid,
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id) + 
           (SELECT COALESCE(SUM(total_amount), 0) FROM entrance_fee_payments WHERE reservation_id = r.id) as total_paid
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    WHERE DATE(r.created_at) = ? AND r.created_by = 'walkin'
    ORDER BY r.created_at DESC
", [$today]);

// Get all walk-in reservations for statistics
$all_walkins = $db->getRows("
    SELECT r.*, u.full_name as guest_name
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    WHERE r.created_by = 'walkin'
");

// Get statistics
$total_walkins = count($all_walkins);
$today_count = count($today_walkins);
$total_revenue = 0;
$pending_count = 0;
$confirmed_count = 0;
$checked_in_count = 0;
$checked_out_count = 0;
$cancelled_count = 0;

foreach ($all_walkins as $walkin) {
    $total_revenue += $walkin['total_amount'];
    if ($walkin['status'] == 'pending') $pending_count++;
    elseif ($walkin['status'] == 'confirmed') $confirmed_count++;
    elseif ($walkin['status'] == 'checked_in') $checked_in_count++;
    elseif ($walkin['status'] == 'checked_out') $checked_out_count++;
    elseif ($walkin['status'] == 'cancelled') $cancelled_count++;
}

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Check-in walk-in guest
        if ($_POST['action'] === 'checkin' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            
            // Get reservation details
            $reservation = $db->getRow("
                SELECT r.*, rm.id as room_id
                FROM reservations r
                LEFT JOIN rooms rm ON r.room_id = rm.id
                WHERE r.id = ?
            ", [$reservation_id]);
            
            if ($reservation) {
                $db->beginTransaction();
                
                try {
                    // Update reservation status
                    $db->update('reservations', [
                        'status' => 'checked_in',
                        'verified_by' => $_SESSION['user_id'],
                        'verified_at' => date('Y-m-d H:i:s')
                    ], 'id = :id', ['id' => $reservation_id]);
                    
                    // Update room status if room exists
                    if (!empty($reservation['room_id'])) {
                        $db->update('rooms', 
                            ['status' => 'occupied'], 
                            'id = :id', 
                            ['id' => $reservation['room_id']]
                        );
                    }
                    
                    $db->commit();
                    
                    $_SESSION['message'] = "Guest checked in successfully";
                    $_SESSION['message_type'] = 'success';
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $_SESSION['message'] = "Error during check-in: " . $e->getMessage();
                    $_SESSION['message_type'] = 'error';
                }
            }
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        
        // Check-out walk-in guest
        if ($_POST['action'] === 'checkout' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            
            // Get reservation details
            $reservation = $db->getRow("
                SELECT r.*, rm.id as room_id
                FROM reservations r
                LEFT JOIN rooms rm ON r.room_id = rm.id
                WHERE r.id = ?
            ", [$reservation_id]);
            
            if ($reservation) {
                $db->beginTransaction();
                
                try {
                    // Update reservation status
                    $db->update('reservations', [
                        'status' => 'checked_out',
                        'checked_out_by' => $_SESSION['user_id'],
                        'checked_out_at' => date('Y-m-d H:i:s')
                    ], 'id = :id', ['id' => $reservation_id]);
                    
                    // Update room status if room exists
                    if (!empty($reservation['room_id'])) {
                        $db->update('rooms', 
                            ['status' => 'available'], 
                            'id = :id', 
                            ['id' => $reservation['room_id']]
                        );
                    }
                    
                    $db->commit();
                    
                    $_SESSION['message'] = "Guest checked out successfully";
                    $_SESSION['message_type'] = 'success';
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $_SESSION['message'] = "Error during check-out: " . $e->getMessage();
                    $_SESSION['message_type'] = 'error';
                }
            }
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        
        // Edit walk-in reservation
        if ($_POST['action'] === 'edit' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            $check_in_date = sanitize($_POST['check_in_date']);
            $check_out_date = sanitize($_POST['check_out_date']);
            $status = sanitize($_POST['status']);
            $total_amount = (float)$_POST['total_amount'];
            
            $db->update('reservations', [
                'check_in_date' => $check_in_date,
                'check_out_date' => $check_out_date,
                'status' => $status,
                'total_amount' => $total_amount,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => $reservation_id]);
            
            $_SESSION['message'] = "Reservation updated successfully";
            $_SESSION['message_type'] = 'success';
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        
        // Delete walk-in reservation
        if ($_POST['action'] === 'delete' && isset($_POST['reservation_id'])) {
            $reservation_id = (int)$_POST['reservation_id'];
            
            // Check if has payments
            $has_payments = $db->getValue("SELECT COUNT(*) FROM payments WHERE reservation_id = ?", [$reservation_id]);
            $has_entrance_payments = $db->getValue("SELECT COUNT(*) FROM entrance_fee_payments WHERE reservation_id = ?", [$reservation_id]);
            
            if ($has_payments > 0 || $has_entrance_payments > 0) {
                $_SESSION['message'] = "Cannot delete reservation with existing payments";
                $_SESSION['message_type'] = 'error';
            } else {
                $db->delete('reservations', 'id = :id', ['id' => $reservation_id]);
                $_SESSION['message'] = "Reservation deleted successfully";
                $_SESSION['message_type'] = 'success';
            }
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Handle any messages from session
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
    <title>Walk-in Management - Veripool Admin</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">
    
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/admin/css/walkin.css">
    <link rel="stylesheet" href="/assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* Additional styles for walk-in page */
        .info-box {
            background: linear-gradient(135deg, #102C57, #1679AB);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 5px 15px rgba(22,121,171,0.3);
        }
        
        .info-box i {
            font-size: 2.5rem;
            color: #FFCBCB;
        }
        
        .info-box p {
            margin: 0;
            font-size: 1rem;
        }
        
        .info-box p strong {
            color: #FFB1B1;
        }
        
        .info-box p small {
            color: #FFCBCB;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card-sm {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .stat-card-sm .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .stat-card-sm .stat-label {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
        }
        
        .payment-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
        }
        
        .payment-badge.paid {
            background: #d4edda;
            color: #155724;
        }
        
        .payment-badge.partial {
            background: #fff3cd;
            color: #856404;
        }
        
        .payment-badge.unpaid {
            background: #f8d7da;
            color: #721c24;
        }
        
        .filter-select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.7rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            filter: brightness(0.95);
        }
        
        .btn-edit {
            background: #17a2b8;
            color: white;
        }
        
        .btn-checkin {
            background: #28a745;
            color: white;
        }
        
        .btn-checkout {
            background: #ffc107;
            color: #102C57;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        /* Modal Styles */
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
            padding: 25px;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
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
            margin: 0;
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
            padding: 8px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        .form-control:focus {
            border-color: #1679AB;
            outline: none;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-save {
            background: #28a745;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            flex: 2;
            font-weight: bold;
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            flex: 1;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .btn-group {
                flex-direction: column;
                width: 100%;
            }
            
            .btn-group .btn {
                width: 100%;
            }
            
            .action-buttons {
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
                <i class="fas fa-user-plus" style="color: #1679AB;"></i>
                Walk-in Management
            </h1>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
                <span style="margin-left: 15px;"><i class="far fa-clock"></i> <?php echo date('h:i A'); ?></span>
            </div>
        </div>
        
        <!-- Info Alert - Walk-ins don't need OTP -->
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <p>
                <strong>Note:</strong> Walk-in reservations do not require OTP generation. 
                Guests can check in directly at the front desk without an OTP code.
                <br><small>Entrance fees are automatically included in the total amount and marked as paid.</small>
            </p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Quick Stats -->
        <div class="stats-cards">
            <div class="stat-card-sm">
                <div class="stat-value"><?php echo $total_walkins; ?></div>
                <div class="stat-label">Total Walk-ins</div>
            </div>
            <div class="stat-card-sm">
                <div class="stat-value"><?php echo $today_count; ?></div>
                <div class="stat-label">Today</div>
            </div>
            <div class="stat-card-sm">
                <div class="stat-value"><?php echo $pending_count; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card-sm">
                <div class="stat-value"><?php echo $confirmed_count; ?></div>
                <div class="stat-label">Confirmed</div>
            </div>
            <div class="stat-card-sm">
                <div class="stat-value"><?php echo $checked_in_count; ?></div>
                <div class="stat-label">Checked In</div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; width: 100%;">
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> Date From</label>
                    <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> Date To</label>
                    <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-tag"></i> Status</label>
                    <select name="status" class="filter-select">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="confirmed" <?php echo $status_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="checked_in" <?php echo $status_filter == 'checked_in' ? 'selected' : ''; ?>>Checked In</option>
                        <option value="checked_out" <?php echo $status_filter == 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group btn-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="walkin.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Today's Walk-ins -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-calendar-day"></i> 
                    Today's Walk-ins
                </h3>
                <span class="badge">
                    <i class="fas fa-user-plus"></i> <?php echo $today_count; ?> today
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($today_walkins)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-plus"></i>
                        <h3>No Walk-ins Today</h3>
                        <p>There are no walk-in reservations recorded for today.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Reservation #</th>
                                    <th>Guest</th>
                                    <th>Room/Cottage</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($today_walkins as $walkin): ?>
                                <tr>
                                    <td>
                                        <i class="far fa-clock" style="color: #1679AB;"></i>
                                        <?php echo date('h:i A', strtotime($walkin['created_at'])); ?>
                                    </td>
                                    <td><strong><?php echo $walkin['reservation_number']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($walkin['guest_name']); ?></td>
                                    <td>
                                        <?php if ($walkin['room_number']): ?>
                                            <i class="fas fa-bed"></i> Room <?php echo $walkin['room_number']; ?>
                                        <?php else: ?>
                                            <span style="color: #999;">No Room</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d', strtotime($walkin['check_in_date'])); ?></td>
                                    <td><?php echo date('M d', strtotime($walkin['check_out_date'])); ?></td>
                                    <td><strong>₱<?php echo number_format($walkin['total_amount'], 2); ?></strong></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $walkin['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $walkin['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($walkin['status'] != 'checked_out' && $walkin['status'] != 'cancelled'): ?>
                                                <?php if ($walkin['status'] == 'checked_in'): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Check out this guest?')">
                                                        <input type="hidden" name="action" value="checkout">
                                                        <input type="hidden" name="reservation_id" value="<?php echo $walkin['id']; ?>">
                                                        <button type="submit" class="btn-action btn-checkout" title="Check Out">
                                                            <i class="fas fa-sign-out-alt"></i> Out
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Check in this guest?')">
                                                        <input type="hidden" name="action" value="checkin">
                                                        <input type="hidden" name="reservation_id" value="<?php echo $walkin['id']; ?>">
                                                        <button type="submit" class="btn-action btn-checkin" title="Check In">
                                                            <i class="fas fa-sign-in-alt"></i> In
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <button class="btn-action btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($walkin)); ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            
                                            <?php if ($walkin['status'] == 'pending' || $walkin['status'] == 'cancelled'): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this reservation? This cannot be undone.')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="reservation_id" value="<?php echo $walkin['id']; ?>">
                                                    <button type="submit" class="btn-action btn-delete" title="Delete">
                                                        <i class="fas fa-trash"></i> Del
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
        
        <!-- Filtered Walk-in Reservations -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-list"></i> 
                    Walk-in Reservations
                    <?php if ($status_filter != 'all' || isset($_GET['date_from'])): ?>
                        <span style="font-size: 0.9rem; color: #FFCBCB; margin-left: 10px;">
                            (Filtered)
                        </span>
                    <?php endif; ?>
                </h3>
                <span class="badge">
                    <i class="fas fa-database"></i> <?php echo count($filtered_walkins); ?> records
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($filtered_walkins)): ?>
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No Walk-in Reservations Found</h3>
                        <p>No walk-in reservations match your filter criteria.</p>
                        <a href="walkin.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reservation #</th>
                                    <th>Guest</th>
                                    <th>Contact</th>
                                    <th>Room</th>
                                    <th>Check In/Out</th>
                                    <th>Amount</th>
                                    <th>Paid</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filtered_walkins as $walkin): 
                                    $payment_paid = $walkin['payment_paid'] ?? 0;
                                    $entrance_fee_paid = $walkin['entrance_fee_paid'] ?? 0;
                                    $total_paid = $walkin['total_paid'] ?? 0;
                                    $balance = $walkin['total_amount'] - $total_paid;
                                    
                                    // For walk-ins, consider entrance fees as automatically paid
                                    if ($walkin['has_entrance_fee'] == 1 && $entrance_fee_paid == 0) {
                                        // Assume entrance fee is paid for walk-ins
                                        $total_paid += $walkin['entrance_fee_amount'] ?? 0;
                                        $balance = $walkin['total_amount'] - $total_paid;
                                    }
                                    
                                    if ($balance <= 0) {
                                        $payment_status = 'paid';
                                        $payment_text = 'Paid';
                                    } elseif ($total_paid > 0) {
                                        $payment_status = 'partial';
                                        $payment_text = 'Partial';
                                    } else {
                                        $payment_status = 'unpaid';
                                        $payment_text = 'Unpaid';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <i class="far fa-calendar-alt" style="color: #1679AB;"></i>
                                        <?php echo date('M d, Y', strtotime($walkin['created_at'])); ?>
                                    </td>
                                    <td><strong><?php echo $walkin['reservation_number']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($walkin['guest_name']); ?></td>
                                    <td>
                                        <i class="fas fa-phone"></i> <?php echo $walkin['phone']; ?><br>
                                        <small><i class="fas fa-envelope"></i> <?php echo $walkin['email']; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($walkin['room_number']): ?>
                                            <i class="fas fa-bed"></i> Room <?php echo $walkin['room_number']; ?><br>
                                            <small><?php echo $walkin['room_type']; ?></small>
                                        <?php else: ?>
                                            <span style="color: #999;">No Room</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M d', strtotime($walkin['check_in_date'])); ?> - 
                                        <?php echo date('M d', strtotime($walkin['check_out_date'])); ?>
                                    </td>
                                    <td><strong>₱<?php echo number_format($walkin['total_amount'], 2); ?></strong></td>
                                    <td>
                                        <span class="payment-badge <?php echo $payment_status; ?>">
                                            <?php echo $payment_text; ?>
                                            <?php if ($payment_status == 'partial'): ?>
                                                <br><small>Paid: ₱<?php echo number_format($total_paid, 2); ?></small>
                                                <br><small style="color: #dc3545;">Due: ₱<?php echo number_format($balance, 2); ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $walkin['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $walkin['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($walkin['status'] != 'checked_out' && $walkin['status'] != 'cancelled'): ?>
                                                <?php if ($walkin['status'] == 'checked_in'): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Check out this guest?')">
                                                        <input type="hidden" name="action" value="checkout">
                                                        <input type="hidden" name="reservation_id" value="<?php echo $walkin['id']; ?>">
                                                        <button type="submit" class="btn-action btn-checkout" title="Check Out">
                                                            <i class="fas fa-sign-out-alt"></i> Out
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Check in this guest?')">
                                                        <input type="hidden" name="action" value="checkin">
                                                        <input type="hidden" name="reservation_id" value="<?php echo $walkin['id']; ?>">
                                                        <button type="submit" class="btn-action btn-checkin" title="Check In">
                                                            <i class="fas fa-sign-in-alt"></i> In
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <button class="btn-action btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($walkin)); ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            
                                            <?php if ($walkin['status'] == 'pending' || $walkin['status'] == 'cancelled'): ?>
                                                <?php if ($total_paid == 0): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this reservation? This cannot be undone.')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="reservation_id" value="<?php echo $walkin['id']; ?>">
                                                        <button type="submit" class="btn-action btn-delete" title="Delete">
                                                            <i class="fas fa-trash"></i> Del
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
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
        
       
    <!-- Edit Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Walk-in Reservation</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="reservation_id" id="edit_reservation_id">
                
                <div class="form-group">
                    <label for="edit_guest_name">Guest Name</label>
                    <input type="text" id="edit_guest_name" class="form-control" readonly disabled>
                </div>
                
                <div class="form-group">
                    <label for="edit_check_in_date">Check-in Date</label>
                    <input type="date" name="check_in_date" id="edit_check_in_date" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_check_out_date">Check-out Date</label>
                    <input type="date" name="check_out_date" id="edit_check_out_date" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_status">Status</label>
                    <select name="status" id="edit_status" class="form-control" required>
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="checked_in">Checked In</option>
                        <option value="checked_out">Checked Out</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_total_amount">Total Amount (₱)</label>
                    <input type="number" name="total_amount" id="edit_total_amount" class="form-control" step="0.01" min="0" required>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openEditModal(walkin) {
            document.getElementById('edit_reservation_id').value = walkin.id;
            document.getElementById('edit_guest_name').value = walkin.guest_name;
            document.getElementById('edit_check_in_date').value = walkin.check_in_date;
            document.getElementById('edit_check_out_date').value = walkin.check_out_date;
            document.getElementById('edit_status').value = walkin.status;
            document.getElementById('edit_total_amount').value = walkin.total_amount;
            
            document.getElementById('editModal').classList.add('active');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
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
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>