<?php
/**
 * Veripool Reservation System - Admin Reservations Page
 * Single table for all reservations with edit functionality and pending date adjustments
 * Coastal Harmony Theme - Gray, Blue, Green - With Consistent Button Colors
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

// Check if user is logged in and is admin/staff
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin', 'staff'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get current user
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Initialize EntryPassManager
$entryPassManager = new EntryPassManager($db);

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Approve date adjustment
    if (isset($_POST['action']) && $_POST['action'] === 'approve_adjustment') {
        $request_id = (int)$_POST['request_id'];
        
        $result = $entryPassManager->approveDateAdjustment($request_id, $current_user['id']);
        
        if ($result['success']) {
            $message = "Date adjustment approved successfully. OTP remains: " . $result['new_otp'];
            $message_type = 'success';
        } else {
            $message = "Failed to approve adjustment: " . $result['message'];
            $message_type = 'error';
        }
    }
    
    // Reject date adjustment
    if (isset($_POST['action']) && $_POST['action'] === 'reject_adjustment') {
        $request_id = (int)$_POST['request_id'];
        $rejection_reason = isset($_POST['rejection_reason']) ? sanitize($_POST['rejection_reason']) : '';
        
        // Get request details for email
        $request = $db->getRow("
            SELECT dar.*, u.email, u.full_name
            FROM date_adjustment_requests dar
            JOIN users u ON dar.user_id = u.id
            WHERE dar.id = ?
        ", [$request_id]);
        
        if ($request) {
            // Update request status
            $db->update('date_adjustment_requests', 
                [
                    'status' => 'rejected',
                    'reviewed_by' => $current_user['id'],
                    'reviewed_at' => date('Y-m-d H:i:s'),
                    'admin_notes' => $rejection_reason
                ],
                'id = :id',
                ['id' => $request_id]
            );
            
            // Send rejection email
            $subject = "Date Adjustment Request Rejected";
            $html_message = '
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #C53030; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { padding: 30px; background: #f9f9f9; }
                    .reason-box { background: #FEF3C7; border-left: 4px solid #ED8936; padding: 15px; margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>Date Adjustment Request Rejected</h2>
                    </div>
                    <div class="content">
                        <p>Dear ' . htmlspecialchars($request['full_name']) . ',</p>
                        <p>Your request to adjust your reservation dates has been rejected.</p>
                        
                        <div class="reason-box">
                            <strong>Reason:</strong><br>
                            ' . htmlspecialchars($rejection_reason) . '
                        </div>
                        
                        <p>Please contact the resort directly if you need assistance.</p>
                        <p>Thank you for understanding.</p>
                    </div>
                </div>
            </body>
            </html>';
            
            $entryPassManager->sendEmailPublic($request['email'], $subject, $html_message);
            
            $message = "Date adjustment rejected successfully.";
            $message_type = 'success';
        }
    }
    
    // UPDATE RESERVATION
    if (isset($_POST['action']) && $_POST['action'] === 'update_reservation') {
        $reservation_id = (int)$_POST['reservation_id'];
        
        // Get current reservation data
        $current_reservation = $db->getRow("SELECT * FROM reservations WHERE id = ?", [$reservation_id]);
        
        if (!$current_reservation) {
            $message = "Reservation not found";
            $message_type = 'error';
        } else {
            // Prepare update data
            $update_data = [
                'check_in_date' => $_POST['check_in_date'],
                'check_out_date' => $_POST['check_out_date'],
                'adults' => (int)$_POST['adults'],
                'children' => (int)$_POST['children'],
                'seniors' => (int)$_POST['seniors'],
                'total_guests' => (int)$_POST['adults'] + (int)$_POST['children'] + (int)$_POST['seniors'],
                'special_requests' => sanitize($_POST['special_requests']),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Handle facility change
            $facility_changed = false;
            
            if (isset($_POST['facility_type'])) {
                if ($_POST['facility_type'] === 'room' && !empty($_POST['room_id'])) {
                    if ($current_reservation['room_id'] != $_POST['room_id']) {
                        $facility_changed = true;
                        
                        // Free up old room if exists
                        if ($current_reservation['room_id']) {
                            $db->update('rooms', ['status' => 'available'], 'id = :id', ['id' => $current_reservation['room_id']]);
                        }
                        // Mark new room as reserved
                        $db->update('rooms', ['status' => 'reserved'], 'id = :id', ['id' => (int)$_POST['room_id']]);
                        
                        $update_data['room_id'] = (int)$_POST['room_id'];
                        $update_data['cottage_id'] = null;
                    }
                } elseif ($_POST['facility_type'] === 'cottage' && !empty($_POST['cottage_id'])) {
                    if ($current_reservation['cottage_id'] != $_POST['cottage_id']) {
                        $facility_changed = true;
                        
                        // Free up old cottage if exists
                        if ($current_reservation['cottage_id']) {
                            $db->update('cottages', ['status' => 'available'], 'id = :id', ['id' => $current_reservation['cottage_id']]);
                        }
                        // Mark new cottage as reserved
                        $db->update('cottages', ['status' => 'reserved'], 'id = :id', ['id' => (int)$_POST['cottage_id']]);
                        
                        $update_data['cottage_id'] = (int)$_POST['cottage_id'];
                        $update_data['room_id'] = null;
                    }
                }
            }
            
            // Recalculate nights for potential date change
            $nights = ceil((strtotime($update_data['check_out_date']) - strtotime($update_data['check_in_date'])) / (60 * 60 * 24));
            
            // Update total amount based on existing facility prices
            if ($current_reservation['check_in_date'] != $update_data['check_in_date'] || 
                $current_reservation['check_out_date'] != $update_data['check_out_date']) {
                
                // Get the price per night from the original reservation total
                $original_nights = ceil((strtotime($current_reservation['check_out_date']) - strtotime($current_reservation['check_in_date'])) / (60 * 60 * 24));
                if ($original_nights > 0) {
                    $price_per_night = $current_reservation['total_amount'] / $original_nights;
                    $update_data['total_amount'] = $price_per_night * $nights;
                }
            }
            
            // Recalculate entrance fee if guest count changed
            if ($update_data['total_guests'] != $current_reservation['total_guests']) {
                $entrance_rate = $db->getValue("SELECT setting_value FROM settings WHERE setting_key = 'entrance_fee'") ?: 50;
                
                // Calculate entrance fee based on guest categories
                // Adults and seniors pay full rate, children might have discount
                $adult_fee = ($update_data['adults'] + $update_data['seniors']) * $entrance_rate;
                
                // Check if there's a child discount setting
                $child_discount = $db->getValue("SELECT setting_value FROM settings WHERE setting_key = 'child_discount'") ?: 0.5;
                $child_fee = $update_data['children'] * ($entrance_rate * $child_discount);
                
                $update_data['entrance_fee_amount'] = $adult_fee + $child_fee;
            }
            
            // Update reservation
            $db->update('reservations', $update_data, 'id = :id', ['id' => $reservation_id]);
            
            // Update entry pass validity if dates changed
            if ($current_reservation['check_in_date'] != $update_data['check_in_date'] || 
                $current_reservation['check_out_date'] != $update_data['check_out_date']) {
                
                $db->update('entry_passes', 
                    [
                        'valid_from' => $update_data['check_in_date'] . ' 14:00:00',
                        'valid_until' => $update_data['check_out_date'] . ' 11:00:00'
                    ],
                    'reservation_id = :id',
                    ['id' => $reservation_id]
                );
            }
            
            $message = "Reservation updated successfully";
            $message_type = 'success';
        }
    }
    
    // UPDATE RESERVATION STATUS
    if (isset($_POST['action']) && $_POST['action'] === 'update_status' && isset($_POST['reservation_id'])) {
        $reservation_id = (int)$_POST['reservation_id'];
        $new_status = sanitize($_POST['status']);
        
        // Get current reservation data
        $reservation = $db->getRow("SELECT * FROM reservations WHERE id = ?", [$reservation_id]);
        
        if ($new_status === 'confirmed') {
            $has_pending_payments = $db->getValue("
                SELECT COUNT(*) FROM payments 
                WHERE reservation_id = ? AND payment_status = 'pending'
            ", [$reservation_id]);
            
            $has_pending_entrance = $db->getValue("
                SELECT COUNT(*) FROM entrance_fee_payments 
                WHERE reservation_id = ? AND payment_status = 'pending'
            ", [$reservation_id]);
            
            if ($has_pending_payments > 0 || $has_pending_entrance > 0) {
                $message = "Cannot confirm reservation. Please verify pending payments and entrance fees first.";
                $message_type = 'error';
            } else {
                $db->update('reservations', ['status' => $new_status], 'id = :id', ['id' => $reservation_id]);
                $message = "Reservation status updated";
                $message_type = 'success';
            }
        }
        elseif ($new_status === 'checked_in') {
            // Check if entrance fee is fully paid
            $entrance_balance = $reservation['entrance_fee_amount'] - $reservation['entrance_fee_paid'];
            
            if ($entrance_balance > 0) {
                $message = "Cannot check in. Entrance fee of ₱" . number_format($entrance_balance, 2) . " is not fully paid.";
                $message_type = 'error';
            } else {
                $db->update('reservations', 
                    ['status' => 'checked_in', 'updated_at' => date('Y-m-d H:i:s')], 
                    'id = :id', 
                    ['id' => $reservation_id]
                );
                
                // Update facility status
                if ($reservation['room_id']) {
                    $db->update('rooms', ['status' => 'occupied'], 'id = :id', ['id' => $reservation['room_id']]);
                }
                if ($reservation['cottage_id']) {
                    $db->update('cottages', ['status' => 'occupied'], 'id = :id', ['id' => $reservation['cottage_id']]);
                }
                
                $message = "Guest checked in successfully";
                $message_type = 'success';
            }
        }
        elseif ($new_status === 'checked_out') {
            if ($reservation['status'] !== 'checked_in') {
                $message = "Cannot check out. Guest is not checked in.";
                $message_type = 'error';
            } else {
                // Deactivate entry passes
                $db->update('entry_passes', 
                    ['status' => 'used', 'used_at' => date('Y-m-d H:i:s')], 
                    'reservation_id = :id AND status = :status', 
                    ['id' => $reservation_id, 'status' => 'active']
                );
                
                // Update facility status
                if ($reservation['room_id']) {
                    $db->update('rooms', ['status' => 'available'], 'id = :id', ['id' => $reservation['room_id']]);
                }
                if ($reservation['cottage_id']) {
                    $db->update('cottages', ['status' => 'available'], 'id = :id', ['id' => $reservation['cottage_id']]);
                }
                
                $db->update('reservations', ['status' => 'checked_out'], 'id = :id', ['id' => $reservation_id]);
                
                $message = "Guest checked out successfully";
                $message_type = 'success';
            }
        }
        elseif ($new_status === 'cancelled') {
            if ($reservation['status'] === 'checked_in') {
                $message = "Cannot cancel checked-in reservation";
                $message_type = 'error';
            } else {
                $db->update('entry_passes', ['status' => 'expired'], 'reservation_id = :id', ['id' => $reservation_id]);
                
                if ($reservation['room_id']) {
                    $db->update('rooms', ['status' => 'available'], 'id = :id', ['id' => $reservation['room_id']]);
                }
                if ($reservation['cottage_id']) {
                    $db->update('cottages', ['status' => 'available'], 'id = :id', ['id' => $reservation['cottage_id']]);
                }
                
                $db->update('reservations', ['status' => 'cancelled'], 'id = :id', ['id' => $reservation_id]);
                
                $message = "Reservation cancelled";
                $message_type = 'success';
            }
        }
    }
    
    // OTP VERIFICATION
    if (isset($_POST['action']) && $_POST['action'] === 'verify_otp' && isset($_POST['otp_code'])) {
        $otp_code = sanitize($_POST['otp_code']);
        $reservation_id = (int)$_POST['reservation_id'];
        
        // Check if entrance fee is fully paid
        $reservation = $db->getRow("SELECT entrance_fee_amount, entrance_fee_paid FROM reservations WHERE id = ?", [$reservation_id]);
        $entrance_balance = $reservation['entrance_fee_amount'] - $reservation['entrance_fee_paid'];
        
        if ($entrance_balance > 0) {
            $message = "Cannot check in. Entrance fee of ₱" . number_format($entrance_balance, 2) . " is not fully paid.";
            $message_type = 'error';
        } else {
            $verification = $entryPassManager->verifyEntryPass($otp_code, $reservation_id);
            
            if (!$verification['success']) {
                $message = $verification['message'];
                $message_type = 'error';
            } else {
                $pass = $verification['data'];
                
                if ($pass['status'] == 'used') {
                    $message = "OTP already used at: " . date('M d, Y h:i A', strtotime($pass['used_at']));
                    $message_type = 'error';
                } else {
                    $db->update('entry_passes', 
                        ['status' => 'used', 'used_at' => date('Y-m-d H:i:s')], 
                        'id = :id', 
                        ['id' => $pass['id']]
                    );
                    
                    $db->update('reservations', 
                        ['status' => 'checked_in', 'updated_at' => date('Y-m-d H:i:s')], 
                        'id = :id', 
                        ['id' => $reservation_id]
                    );
                    
                    // Update facility status
                    $reservation = $db->getRow("SELECT room_id, cottage_id FROM reservations WHERE id = ?", [$reservation_id]);
                    if ($reservation) {
                        if ($reservation['room_id']) {
                            $db->update('rooms', ['status' => 'occupied'], 'id = :id', ['id' => $reservation['room_id']]);
                        }
                        if ($reservation['cottage_id']) {
                            $db->update('cottages', ['status' => 'occupied'], 'id = :id', ['id' => $reservation['cottage_id']]);
                        }
                    }
                    
                    $message = "OTP verified. Guest checked in.";
                    $message_type = 'success';
                }
            }
        }
    }
    
    // Refresh data if action was performed
    if (!empty($message)) {
        header("Location: reservations.php?status=$status_filter&date_from=$date_from&date_to=$date_to&search=" . urlencode($search) . "&msg=" . urlencode($message) . "&msg_type=$message_type");
        exit;
    }
}

// Check for message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $message_type = $_GET['msg_type'] ?? 'info';
}

// Get edit ID if any
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_reservation = null;

if ($edit_id > 0) {
    $edit_reservation = $db->getRow("
        SELECT r.*, 
               u.full_name as guest_name, 
               u.email as guest_email,
               u.phone as guest_phone,
               rm.room_number,
               rt.name as room_type,
               c.cottage_name,
               c.cottage_type
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN rooms rm ON r.room_id = rm.id
        LEFT JOIN room_types rt ON rm.room_type_id = rt.id
        LEFT JOIN cottages c ON r.cottage_id = c.id
        WHERE r.id = ?
    ", [$edit_id]);
}

// ===== GET PENDING ADJUSTMENT REQUESTS =====
$pending_adjustments = $db->getRows("
    SELECT dar.*, 
           u.full_name as guest_name, 
           u.email as guest_email,
           r.reservation_number, 
           r.check_in_date as original_check_in, 
           r.check_out_date as original_check_out,
           CASE 
               WHEN r.room_id IS NOT NULL THEN CONCAT('Room ', rm.room_number)
               WHEN r.cottage_id IS NOT NULL THEN c.cottage_name
               ELSE 'No facility'
           END as facility_name,
           ep.otp_code,
           ep.date_adjustments as current_adjustments
    FROM date_adjustment_requests dar
    JOIN users u ON dar.user_id = u.id
    JOIN reservations r ON dar.reservation_id = r.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN cottages c ON r.cottage_id = c.id
    JOIN entry_passes ep ON dar.entry_pass_id = ep.id
    WHERE dar.status = 'pending'
    ORDER BY dar.created_at ASC
");

// ===== ALL RESERVATIONS =====
$where_conditions = ["1=1"];
$params = [];

if ($status_filter != 'all') {
    $where_conditions[] = "r.status = :status";
    $params['status'] = $status_filter;
}

if (isset($_GET['date_from']) && isset($_GET['date_to'])) {
    $where_conditions[] = "DATE(r.created_at) BETWEEN :date_from AND :date_to";
    $params['date_from'] = $date_from;
    $params['date_to'] = $date_to;
}

if (!empty($search)) {
    $where_conditions[] = "(r.reservation_number LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)";
    $params['search'] = "%$search%";
}

$where_clause = implode(" AND ", $where_conditions);

$reservations = $db->getRows("
    SELECT r.*, 
           u.full_name as guest_name, 
           u.email as guest_email,
           u.phone as guest_phone,
           CASE 
               WHEN r.created_by = 'walkin' THEN 'Walk-in'
               ELSE 'Online'
           END as reservation_type,
           -- Facility information
           CASE 
               WHEN r.room_id IS NOT NULL THEN CONCAT('Room ', rm.room_number)
               WHEN r.cottage_id IS NOT NULL THEN c.cottage_name
               ELSE 'No facility'
           END as facility_name,
           CASE 
               WHEN r.room_id IS NOT NULL THEN 'room'
               WHEN r.cottage_id IS NOT NULL THEN 'cottage'
               ELSE 'none'
           END as facility_type,
           rm.room_number,
           rt.name as room_type,
           c.cottage_name,
           c.cottage_type,
           -- Accommodation payments
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id AND payment_status = 'completed') as accommodation_paid,
           -- Entrance fee payments
           (SELECT COALESCE(SUM(total_amount), 0) FROM entrance_fee_payments WHERE reservation_id = r.id AND payment_status = 'completed') as entrance_paid,
           -- Total paid
           ((SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id AND payment_status = 'completed') +
            (SELECT COALESCE(SUM(total_amount), 0) FROM entrance_fee_payments WHERE reservation_id = r.id AND payment_status = 'completed')) as total_paid,
           (SELECT COUNT(*) FROM payments WHERE reservation_id = r.id AND payment_status = 'pending') as pending_payments,
           (SELECT notes FROM payments WHERE reservation_id = r.id AND notes LIKE '%Screenshot:%' LIMIT 1) as screenshot_note,
           -- Entry pass info
           ep.otp_code,
           ep.status as pass_status,
           ep.valid_from,
           ep.valid_until,
           ep.date_adjustments,
           (SELECT COUNT(*) FROM date_adjustment_requests WHERE entry_pass_id = ep.id AND status = 'pending') as pending_adjustment,
           -- Remaining balance
           (r.total_amount - ((SELECT COALESCE(SUM(amount), 0) FROM payments WHERE reservation_id = r.id AND payment_status = 'completed') +
            (SELECT COALESCE(SUM(total_amount), 0) FROM entrance_fee_payments WHERE reservation_id = r.id AND payment_status = 'completed'))) as remaining_balance,
           r.entrance_fee_amount,
           r.entrance_fee_paid,
           (r.entrance_fee_amount - r.entrance_fee_paid) as entrance_fee_balance,
           r.adults,
           r.children,
           r.seniors,
           r.total_guests
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN rooms rm ON r.room_id = rm.id
    LEFT JOIN room_types rt ON rm.room_type_id = rt.id
    LEFT JOIN cottages c ON r.cottage_id = c.id
    LEFT JOIN entry_passes ep ON r.id = ep.reservation_id
    WHERE $where_clause
    ORDER BY 
        CASE 
            WHEN r.status = 'pending' AND (SELECT COUNT(*) FROM payments WHERE reservation_id = r.id AND payment_status = 'pending') > 0 THEN 1
            WHEN r.status = 'pending' THEN 2
            WHEN r.status = 'confirmed' THEN 3
            WHEN r.status = 'checked_in' THEN 4
            WHEN r.status = 'checked_out' THEN 5
            WHEN r.status = 'cancelled' THEN 6
            ELSE 7
        END,
        r.created_at DESC
", $params);

// Get counts
$total_count = count($reservations);
$pending_payments_count = $db->getValue("SELECT COUNT(*) FROM payments WHERE payment_status = 'pending'") ?: 0;
$pending_entrance_count = $db->getValue("SELECT COUNT(*) FROM entrance_fee_payments WHERE payment_status = 'pending'") ?: 0;
$pending_adjustments_count = count($pending_adjustments);

// Get available rooms and cottages for edit form (without price)
$available_rooms = $db->getRows("
    SELECT r.*, rt.name as room_type_name 
    FROM rooms r 
    JOIN room_types rt ON r.room_type_id = rt.id 
    WHERE r.status IN ('available', 'reserved')
    ORDER BY rt.name, r.room_number
");

$available_cottages = $db->getRows("
    SELECT * FROM cottages 
    WHERE status IN ('available', 'reserved')
    ORDER BY cottage_type, cottage_name
");

// Helper function to extract screenshot filename
function extractScreenshotFilename($notes) {
    if (preg_match('/Screenshot: ([^\s]+)/', $notes, $matches)) {
        return $matches[1];
    }
    return null;
}

// Helper function for balance class
function getBalanceClass($balance) {
    return $balance <= 0 ? 'balance-zero' : 'balance-positive';
}

// Helper function to get facility icon
function getFacilityIcon($type) {
    switch ($type) {
        case 'room':
            return '<i class="fas fa-bed" style="color: #2B6F8B;"></i>';
        case 'cottage':
            return '<i class="fas fa-home" style="color: #2F855A;"></i>';
        default:
            return '<i class="fas fa-question-circle" style="color: #A0AEC0;"></i>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservations - Veripool Admin</title>
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
        /* ===== COASTAL HARMONY THEME - RESERVATIONS PAGE ===== */
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
            --btn-edit: #2B6F8B;
            --btn-edit-hover: #1E5770;
            --btn-delete: #C53030;
            --btn-delete-hover: #9B2C2C;
            --btn-checkin: #2F855A;
            --btn-checkin-hover: #276749;
            --btn-verify: #2F855A;
            --btn-verify-hover: #276749;
            --btn-payment: #ED8936;
            --btn-payment-hover: #DD6B20;
            --btn-entrance: #9F7AEA;
            --btn-entrance-hover: #805AD5;
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
            border-left-color: var(--btn-delete);
            color: var(--btn-delete);
            background: #FFF5F5;
            border-color: #FED7D7;
        }
        
        /* Tab Navigation */
        .tab-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            padding-bottom: 5px;
            flex-wrap: wrap;
            align-items: center;
            position: relative;
            z-index: 2;
        }
        
        .tab-btn {
            padding: 12px 25px;
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 40px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gray-700);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        
        .tab-btn:hover {
            background: var(--gray-100);
            border-color: var(--blue-500);
            color: var(--blue-500);
            transform: translateY(-2px);
        }
        
        .tab-btn.active {
            background: var(--blue-500);
            color: white;
            border-color: var(--blue-500);
            box-shadow: 0 4px 10px rgba(43, 111, 139, 0.2);
        }
        
        .tab-btn .badge {
            background: var(--gray-200);
            color: var(--gray-700);
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .tab-btn.active .badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            background: var(--white);
            padding: 15px 20px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            position: relative;
            z-index: 2;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--gray-700);
        }
        
        .stat-item i {
            color: var(--blue-500);
            font-size: 1rem;
        }
        
        .stat-item.warning i {
            color: var(--btn-payment);
        }
        
        .stat-item.danger i {
            color: var(--btn-delete);
        }
        
        .stat-item strong {
            color: var(--gray-900);
            margin-right: 3px;
            font-weight: 600;
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
            min-width: 120px;
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
        
        .search-group {
            flex: 2;
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
        
        .btn-outline {
            background: transparent;
            color: var(--gray-700);
            border: 2px solid var(--gray-300);
        }
        
        .btn-outline:hover {
            border-color: var(--blue-500);
            color: var(--blue-500);
        }
        
        /* Section Header */
        .section-header {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-bottom: 2px solid var(--blue-500);
            padding: 15px 20px;
            border-radius: 12px 12px 0 0;
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 2;
        }
        
        .section-header:first-of-type {
            margin-top: 0;
        }
        
        .section-header h2 {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-900);
            font-size: 1.2rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-header h2 i {
            color: var(--blue-500);
        }
        
        .section-header .badge {
            background: var(--gray-100);
            color: var(--gray-700);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid var(--gray-200);
        }
        
        /* Card */
        .card {
            background: var(--white);
            border-radius: 0 0 12px 12px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            border-top: none;
            margin-bottom: 30px;
            overflow: hidden;
            position: relative;
            z-index: 2;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Adjustment Cards */
        .adjustments-section {
            margin-bottom: 30px;
        }
        
        .adjustment-card {
            background: #FEF3C7;
            border-left: 4px solid var(--btn-payment);
            padding: 20px;
            margin-bottom: 15px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border: 1px solid #FDE68A;
        }
        
        .adjustment-info {
            flex: 1;
        }
        
        .adjustment-info h4 {
            color: #92400E;
            margin-bottom: 8px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .adjustment-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
            background: var(--btn-payment);
            color: white;
        }
        
        .adjustment-pending {
            animation: pulse 1.5s infinite;
        }
        
        .adjustment-count {
            background: var(--gray-900);
            color: var(--gray-100);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.6rem;
            margin-left: 5px;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .adjustment-dates {
            display: flex;
            gap: 20px;
            margin: 10px 0;
            flex-wrap: wrap;
        }
        
        .date-box {
            background: white;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 0.85rem;
            border: 1px solid var(--gray-200);
        }
        
        .date-box.original {
            border-left: 3px solid var(--btn-delete);
        }
        
        .date-box.requested {
            border-left: 3px solid var(--green-500);
        }
        
        .date-label {
            font-size: 0.7rem;
            color: var(--gray-600);
            margin-bottom: 3px;
        }
        
        .date-value {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .adjustment-reason {
            background: white;
            padding: 10px 15px;
            border-radius: 8px;
            font-style: italic;
            color: var(--gray-700);
            font-size: 0.85rem;
            margin-top: 10px;
            border: 1px solid var(--gray-200);
        }
        
        .adjustment-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-approve {
            background: var(--green-500);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-approve:hover {
            background: var(--green-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-reject {
            background: var(--btn-delete);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-reject:hover {
            background: var(--btn-delete-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        
        table th {
            text-align: left;
            padding: 12px 8px;
            background: var(--gray-100);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.7rem;
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
        
        /* Type Badge */
        .type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 30px;
            font-size: 0.6rem;
            font-weight: 600;
        }
        
        .type-online {
            background: var(--blue-500);
            color: white;
        }
        
        .type-walkin {
            background: var(--green-500);
            color: white;
        }
        
        /* Facility Display */
        .facility-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .facility-icon {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            border-radius: 8px;
            border: 1px solid var(--gray-200);
        }
        
        .facility-name {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .facility-type {
            font-size: 0.6rem;
            color: var(--gray-600);
            margin-top: 2px;
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
            color: var(--btn-delete); 
        }
        
        /* Balance Display */
        .balance-positive {
            color: var(--btn-delete);
            font-weight: 600;
        }
        
        .balance-zero {
            color: var(--green-600);
            font-weight: 600;
        }
        
        /* Payment Breakdown */
        .payment-breakdown {
            font-size: 0.65rem;
            color: var(--gray-500);
            margin-top: 3px;
        }
        
        /* OTP Code */
        .otp-code {
            font-family: monospace;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--blue-500);
            cursor: pointer;
            background: var(--gray-100);
            padding: 2px 6px;
            border-radius: 4px;
        }
        
        .otp-code:hover {
            background: var(--gray-200);
            text-decoration: underline;
        }
        
        /* Guest Count */
        .guest-count {
            font-size: 0.65rem;
            color: var(--gray-600);
            margin-top: 3px;
        }
        
        /* Action Buttons - CONSISTENT COLORS */
        .action-buttons {
            display: flex;
            gap: 3px;
            flex-wrap: wrap;
        }
        
        .btn-icon {
            padding: 5px 8px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.65rem;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            font-weight: 500;
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        /* Edit Button - Blue */
        .btn-edit { 
            background: var(--btn-edit); 
            color: white; 
        }
        .btn-edit:hover {
            background: var(--btn-edit-hover);
        }
        
        /* Check-in Button - Green */
        .btn-checkin { 
            background: var(--btn-checkin); 
            color: white; 
        }
        .btn-checkin:hover {
            background: var(--btn-checkin-hover);
        }
        
        /* Verify Button - Green (same as check-in) */
        .btn-verify { 
            background: var(--btn-verify); 
            color: white; 
        }
        .btn-verify:hover {
            background: var(--btn-verify-hover);
        }
        
        /* Delete Button - Red */
        .btn-delete { 
            background: var(--btn-delete); 
            color: white; 
        }
        .btn-delete:hover {
            background: var(--btn-delete-hover);
        }
        
        /* Payment Button - Orange */
        .btn-payment { 
            background: var(--btn-payment); 
            color: white; 
        }
        .btn-payment:hover {
            background: var(--btn-payment-hover);
        }
        
        /* Entrance Button - Purple */
        .btn-entrance { 
            background: var(--btn-entrance); 
            color: white; 
        }
        .btn-entrance:hover {
            background: var(--btn-entrance-hover);
        }
        
        .select-status {
            padding: 5px 8px;
            font-size: 0.65rem;
            border: 2px solid var(--gray-200);
            border-radius: 6px;
            width: 80px;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
        }
        
        .select-status:focus {
            outline: none;
            border-color: var(--blue-500);
        }
        
        /* Screenshot Thumb */
        .screenshot-thumb {
            width: 32px;
            height: 32px;
            background: var(--gray-100);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .screenshot-thumb:hover {
            background: var(--gray-200);
            transform: scale(1.1);
        }
        
        .screenshot-thumb i {
            font-size: 1rem;
            color: var(--blue-500);
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
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .modal-header h3 {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-900);
            font-size: 1.2rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-header h3 i {
            color: var(--blue-500);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-500);
            transition: color 0.3s ease;
        }
        
        .modal-close:hover {
            color: var(--btn-delete);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--blue-500);
            box-shadow: 0 0 0 3px rgba(43, 111, 139, 0.1);
        }
        
        .form-control[readonly] {
            background: var(--gray-100);
            color: var(--gray-600);
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-col {
            flex: 1;
        }
        
        .guest-info {
            background: var(--gray-100);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            border: 1px solid var(--gray-200);
        }
        
        .guest-info p {
            margin: 5px 0;
        }
        
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: var(--blue-500);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .btn-submit:hover {
            background: var(--blue-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        /* Staff Notice */
        .staff-notice {
            background: #E1EFFE;
            color: var(--blue-700);
            padding: 12px 15px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 15px;
            border-left: 4px solid var(--blue-500);
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .filter-section form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .stats-bar {
                flex-direction: column;
                gap: 8px;
            }
            
            .adjustment-card {
                flex-direction: column;
                align-items: stretch;
            }
            
            .adjustment-actions {
                justify-content: flex-end;
            }
            
            .form-row {
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
                <i class="fas fa-calendar-check"></i>
                Reservations
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
        
        <!-- Tab Navigation -->
        <div class="tab-nav">
            <a href="reservations.php" class="tab-btn active">
                <i class="fas fa-calendar-check"></i> All Reservations
                <?php if ($pending_adjustments_count > 0): ?>
                    <span class="badge"><?php echo $pending_adjustments_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="verify-payments.php" class="tab-btn">
                <i class="fas fa-credit-card"></i> Verify Payments
                <?php if ($pending_payments_count > 0 || $pending_entrance_count > 0): ?>
                    <span class="badge"><?php echo $pending_payments_count + $pending_entrance_count; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <!-- Stats Bar -->
        <div class="stats-bar">
            <div class="stat-item">
                <i class="fas fa-calendar-check"></i>
                <span><strong><?php echo $total_count; ?></strong> Total Reservations</span>
            </div>
            <div class="stat-item warning">
                <i class="fas fa-clock"></i>
                <span><strong><?php echo $pending_payments_count; ?></strong> Pending Payments</span>
            </div>
            <div class="stat-item danger">
                <i class="fas fa-ticket-alt"></i>
                <span><strong><?php echo $pending_entrance_count; ?></strong> Pending Entrance Fees</span>
            </div>
            <?php if ($pending_adjustments_count > 0): ?>
            <div class="stat-item warning">
                <i class="fas fa-calendar-alt"></i>
                <span><strong><?php echo $pending_adjustments_count; ?></strong> Pending Adjustments</span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET">
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" class="filter-input">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="confirmed" <?php echo $status_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="checked_in" <?php echo $status_filter == 'checked_in' ? 'selected' : ''; ?>>Checked In</option>
                        <option value="checked_out" <?php echo $status_filter == 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group search-group">
                    <label>Search</label>
                    <input type="text" name="search" class="filter-input" placeholder="Name, email, phone, reservation #" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group" style="display: flex; gap: 5px;">
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a href="reservations.php" class="btn btn-outline">Clear</a>
                </div>
            </form>
        </div>
        
        <!-- PENDING ADJUSTMENT REQUESTS SECTION -->
        <?php if (!empty($pending_adjustments)): ?>
        <div class="adjustments-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-calendar-alt"></i> Pending Date Adjustment Requests
                </h2>
                <span class="badge"><?php echo count($pending_adjustments); ?> pending</span>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <?php foreach ($pending_adjustments as $adj): ?>
                    <div class="adjustment-card">
                        <div class="adjustment-info">
                            <h4>
                                <?php echo htmlspecialchars($adj['guest_name']); ?> 
                                <small style="font-weight: normal; color: var(--gray-600);">(<?php echo $adj['guest_email']; ?>)</small>
                                <span class="adjustment-badge adjustment-pending">Pending Review</span>
                            </h4>
                            
                            <p style="margin: 5px 0; font-size: 0.85rem; color: var(--gray-700);">
                                <strong>Reservation:</strong> <?php echo $adj['reservation_number']; ?> | 
                                <strong>Facility:</strong> <?php echo $adj['facility_name']; ?> |
                                <strong>OTP:</strong> <span class="otp-code" onclick="copyOTP('<?php echo $adj['otp_code']; ?>')"><?php echo $adj['otp_code']; ?></span>
                                <?php if ($adj['current_adjustments'] > 0): ?>
                                <span class="adjustment-count">Adj: <?php echo $adj['current_adjustments']; ?>/2</span>
                                <?php endif; ?>
                            </p>
                            
                            <div class="adjustment-dates">
                                <div class="date-box original">
                                    <div class="date-label">Original Dates</div>
                                    <div class="date-value">
                                        <?php echo date('M d, Y', strtotime($adj['original_check_in'])); ?> - 
                                        <?php echo date('M d, Y', strtotime($adj['original_check_out'])); ?>
                                    </div>
                                </div>
                                <div class="date-box requested">
                                    <div class="date-label">Requested Dates</div>
                                    <div class="date-value">
                                        <?php echo date('M d, Y', strtotime($adj['requested_check_in'])); ?> - 
                                        <?php echo date('M d, Y', strtotime($adj['requested_check_out'])); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($adj['reason'])): ?>
                            <div class="adjustment-reason">
                                <i class="fas fa-quote-left" style="color: var(--gray-400); font-size: 0.7rem;"></i>
                                <?php echo htmlspecialchars($adj['reason']); ?>
                                <i class="fas fa-quote-right" style="color: var(--gray-400); font-size: 0.7rem;"></i>
                            </div>
                            <?php endif; ?>
                            
                            <p style="margin-top: 5px; font-size: 0.7rem; color: var(--gray-500);">
                                Requested: <?php echo date('M d, Y h:i A', strtotime($adj['created_at'])); ?>
                            </p>
                        </div>
                        
                        <div class="adjustment-actions">
                            <form method="POST" onsubmit="return confirm('Approve this date adjustment? The OTP will remain the same and the reservation dates will be updated.');">
                                <input type="hidden" name="action" value="approve_adjustment">
                                <input type="hidden" name="request_id" value="<?php echo $adj['id']; ?>">
                                <button type="submit" class="btn-approve">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                            </form>
                            
                            <button onclick="showRejectModal(<?php echo $adj['id']; ?>, '<?php echo addslashes($adj['guest_name']); ?>')" class="btn-reject">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- ALL RESERVATIONS TABLE -->
        <div class="section-header">
            <h2>
                <i class="fas fa-list"></i> All Reservations
            </h2>
            <span class="badge"><?php echo $total_count; ?> total</span>
        </div>
        
        <div class="card">
            <div class="card-body">
                <?php if (empty($reservations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Reservations Found</h3>
                        <p>No reservations match your filter criteria.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Res #</th>
                                    <th>Type</th>
                                    <th>Guest</th>
                                    <th>Facility</th>
                                    <th>Dates</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Entrance Fee</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>OTP/Adj</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reservations as $res): 
                                    $balance = $res['remaining_balance'];
                                    $balance_class = getBalanceClass($balance);
                                    $entrance_balance = $res['entrance_fee_balance'];
                                    $entrance_class = getBalanceClass($entrance_balance);
                                    $screenshot = extractScreenshotFilename($res['screenshot_note'] ?? '');
                                    $res_type = $res['reservation_type'];
                                ?>
                                <tr>
                                    <td><strong><?php echo substr($res['reservation_number'], -6); ?></strong></td>
                                    <td>
                                        <span class="type-badge type-<?php echo strtolower($res_type); ?>">
                                            <?php echo $res_type; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($res['guest_name']); ?>
                                        <br><small style="color: var(--gray-600);"><?php echo $res['guest_phone']; ?></small>
                                    </td>
                                    <td>
                                        <div class="facility-info">
                                            <span class="facility-icon"><?php echo getFacilityIcon($res['facility_type']); ?></span>
                                            <div>
                                                <span class="facility-name"><?php echo $res['facility_name']; ?></span>
                                                <?php if ($res['facility_type'] == 'room' && $res['room_type']): ?>
                                                    <div class="facility-type"><?php echo $res['room_type']; ?></div>
                                                <?php elseif ($res['facility_type'] == 'cottage' && $res['cottage_type']): ?>
                                                    <div class="facility-type"><?php echo ucfirst($res['cottage_type']); ?> Cottage</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo date('m/d', strtotime($res['check_in_date'])); ?>-<?php echo date('m/d', strtotime($res['check_out_date'])); ?>
                                    </td>
                                    <td>₱<?php echo number_format($res['total_amount'], 0); ?></td>
                                    <td>
                                        ₱<?php echo number_format($res['total_paid'], 0); ?>
                                        <div class="payment-breakdown">
                                            (Acc: ₱<?php echo number_format($res['accommodation_paid'], 0); ?> + 
                                            Ent: ₱<?php echo number_format($res['entrance_paid'], 0); ?>)
                                        </div>
                                    </td>
                                    <td class="<?php echo $balance_class; ?>">
                                        ₱<?php echo number_format($balance, 0); ?>
                                        <?php if ($balance <= 0): ?>
                                            <br><small style="color: var(--green-600);">FULLY PAID</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo $entrance_class; ?>">
                                            <?php if ($entrance_balance <= 0): ?>
                                                <i class="fas fa-check-circle" style="color: var(--green-500);"></i> Paid
                                            <?php else: ?>
                                                <i class="fas fa-exclamation-circle" style="color: var(--btn-delete);"></i> ₱<?php echo number_format($entrance_balance, 0); ?>
                                            <?php endif; ?>
                                        </span>
                                        <br><small class="guest-count"><?php echo $res['total_guests']; ?> guests</small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $res['status']; ?>">
                                            <?php echo ucfirst($res['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($screenshot): ?>
                                            <div class="screenshot-thumb" onclick="viewScreenshot('<?php echo $screenshot; ?>', '<?php echo $res['reservation_number']; ?>', <?php echo $res['accommodation_paid']; ?>)">
                                                <i class="fas fa-image"></i>
                                            </div>
                                        <?php elseif ($res['pending_payments'] > 0): ?>
                                            <span style="color: var(--btn-payment);">Pending</span>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($res['otp_code'])): ?>
                                            <span class="otp-code" onclick="copyOTP('<?php echo $res['otp_code']; ?>')" title="Click to copy OTP">
                                                <?php echo $res['otp_code']; ?>
                                            </span>
                                            <?php if ($res['date_adjustments'] > 0): ?>
                                                <br><small style="color: var(--green-600);">
                                                    <i class="fas fa-sync-alt"></i> Adj: <?php echo $res['date_adjustments']; ?>/2
                                                </small>
                                            <?php endif; ?>
                                            <?php if ($res['pending_adjustment'] > 0): ?>
                                                <br><span class="adjustment-badge adjustment-pending">Pending</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--gray-400);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $res['id']; ?>&status=<?php echo $status_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($search); ?>" class="btn-icon btn-edit" title="Edit Reservation">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="reservation_id" value="<?php echo $res['id']; ?>">
                                                <select name="status" class="select-status" onchange="this.form.submit()" <?php echo (($res['pending_payments'] > 0 || $entrance_balance > 0) && $res['status'] == 'pending') ? 'disabled' : ''; ?>>
                                                    <option value="pending" <?php echo $res['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="confirmed" <?php echo $res['status'] == 'confirmed' ? 'selected' : ''; ?>>Confirm</option>
                                                    <option value="checked_in" <?php echo $res['status'] == 'checked_in' ? 'selected' : ''; ?> <?php echo ($entrance_balance > 0) ? 'disabled' : ''; ?>>Check In</option>
                                                    <option value="checked_out" <?php echo $res['status'] == 'checked_out' ? 'selected' : ''; ?>>Check Out</option>
                                                    <option value="cancelled" <?php echo $res['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancel</option>
                                                </select>
                                            </form>
                                            
                                            <?php if ($res['status'] == 'confirmed' && !empty($res['otp_code']) && $entrance_balance <= 0): ?>
                                                <button onclick="showOTPVerification(<?php echo $res['id']; ?>, '<?php echo $res['reservation_number']; ?>', '<?php echo addslashes($res['guest_name']); ?>')" class="btn-icon btn-verify" title="Verify OTP">
                                                    <i class="fas fa-key"></i> OTP
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($res['pending_payments'] > 0): ?>
                                                <a href="verify-payments.php?reservation=<?php echo $res['id']; ?>" class="btn-icon btn-payment" title="Verify Payment">
                                                    <i class="fas fa-credit-card"></i> Pay
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($entrance_balance > 0): ?>
                                                <a href="verify-payments.php#entrance-fees" class="btn-icon btn-entrance" title="Entrance Fee Pending">
                                                    <i class="fas fa-ticket-alt"></i> Fee
                                                </a>
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
    </div>
    
    <!-- Edit Reservation Modal -->
    <?php if ($edit_reservation): ?>
    <div class="modal active" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Reservation #<?php echo substr($edit_reservation['reservation_number'], -6); ?></h3>
                <a href="?status=<?php echo $status_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($search); ?>" class="modal-close">&times;</a>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_reservation">
                <input type="hidden" name="reservation_id" value="<?php echo $edit_reservation['id']; ?>">
                
                <div class="guest-info">
                    <p><strong>Guest:</strong> <?php echo htmlspecialchars($edit_reservation['guest_name']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($edit_reservation['guest_email']); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($edit_reservation['guest_phone']); ?></p>
                    <p><strong>Reservation Type:</strong> <?php echo $edit_reservation['created_by'] == 'walkin' ? 'Walk-in' : 'Online'; ?></p>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <label>Check-in Date</label>
                        <input type="date" name="check_in_date" class="form-control" value="<?php echo $edit_reservation['check_in_date']; ?>" required>
                    </div>
                    <div class="form-col">
                        <label>Check-out Date</label>
                        <input type="date" name="check_out_date" class="form-control" value="<?php echo $edit_reservation['check_out_date']; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <label>Facility Type</label>
                        <select name="facility_type" class="form-control" id="facilityType" onchange="toggleFacilitySelect()">
                            <option value="room" <?php echo $edit_reservation['room_id'] ? 'selected' : ''; ?>>Room</option>
                            <option value="cottage" <?php echo $edit_reservation['cottage_id'] ? 'selected' : ''; ?>>Cottage</option>
                        </select>
                    </div>
                    <div class="form-col">
                        <label id="facilityLabel">Select Room</label>
                        <select name="room_id" class="form-control" id="roomSelect" <?php echo !$edit_reservation['room_id'] ? 'style="display:none;"' : ''; ?>>
                            <option value="">Select Room</option>
                            <?php foreach ($available_rooms as $room): ?>
                                <option value="<?php echo $room['id']; ?>" <?php echo ($edit_reservation['room_id'] == $room['id']) ? 'selected' : ''; ?>>
                                    Room <?php echo $room['room_number']; ?> - <?php echo $room['room_type_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="cottage_id" class="form-control" id="cottageSelect" <?php echo !$edit_reservation['cottage_id'] ? 'style="display:none;"' : ''; ?>>
                            <option value="">Select Cottage</option>
                            <?php foreach ($available_cottages as $cottage): ?>
                                <option value="<?php echo $cottage['id']; ?>" <?php echo ($edit_reservation['cottage_id'] == $cottage['id']) ? 'selected' : ''; ?>>
                                    <?php echo $cottage['cottage_name']; ?> - <?php echo ucfirst($cottage['cottage_type']); ?> Cottage
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <label>Adults</label>
                        <input type="number" name="adults" class="form-control" value="<?php echo $edit_reservation['adults']; ?>" min="0" required>
                    </div>
                    <div class="form-col">
                        <label>Children</label>
                        <input type="number" name="children" class="form-control" value="<?php echo $edit_reservation['children']; ?>" min="0" required>
                    </div>
                    <div class="form-col">
                        <label>Seniors</label>
                        <input type="number" name="seniors" class="form-control" value="<?php echo $edit_reservation['seniors']; ?>" min="0" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Special Requests</label>
                    <textarea name="special_requests" class="form-control" rows="3"><?php echo htmlspecialchars($edit_reservation['special_requests']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Update Reservation
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function toggleFacilitySelect() {
            var type = document.getElementById('facilityType').value;
            var roomSelect = document.getElementById('roomSelect');
            var cottageSelect = document.getElementById('cottageSelect');
            var facilityLabel = document.getElementById('facilityLabel');
            
            if (type === 'room') {
                roomSelect.style.display = 'block';
                cottageSelect.style.display = 'none';
                facilityLabel.textContent = 'Select Room';
                roomSelect.required = true;
                cottageSelect.required = false;
            } else {
                roomSelect.style.display = 'none';
                cottageSelect.style.display = 'block';
                facilityLabel.textContent = 'Select Cottage';
                roomSelect.required = false;
                cottageSelect.required = true;
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleFacilitySelect();
        });
    </script>
    <?php endif; ?>
    
    <!-- OTP Verification Modal -->
    <div class="modal" id="otpModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Online Reservation Check-in</h3>
                <button class="modal-close" onclick="closeOtpModal()">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="verify_otp">
                <input type="hidden" name="reservation_id" id="otp_reservation_id">
                
                <div class="guest-info" id="otpInfo"></div>
                
                <div class="staff-notice">
                    <i class="fas fa-info-circle"></i>
                    Ask the guest for their 6-digit OTP code sent via email.
                </div>
                
                <div class="form-group">
                    <label for="otp_code">Enter OTP from Guest</label>
                    <input type="text" name="otp_code" id="otp_code" class="form-control" placeholder="6-digit OTP" maxlength="6" required>
                </div>
                
                <button type="submit" class="btn-submit" style="background: var(--green-500);">
                    <i class="fas fa-check-circle"></i> Verify & Check In
                </button>
            </form>
        </div>
    </div>
    
    <!-- Reject Adjustment Modal -->
    <div class="modal" id="rejectModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle" style="color: var(--btn-delete);"></i> Reject Date Adjustment</h3>
                <button class="modal-close" onclick="closeRejectModal()">&times;</button>
            </div>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="action" value="reject_adjustment">
                <input type="hidden" name="request_id" id="reject_request_id">
                
                <div class="modal-body">
                    <p id="rejectGuestInfo" style="margin-bottom: 15px; color: var(--gray-700);"></p>
                    <div class="form-group">
                        <label>Reason for Rejection</label>
                        <textarea name="rejection_reason" rows="4" class="form-control" placeholder="Please explain why this adjustment request is being rejected..." required></textarea>
                    </div>
                </div>
                
                <div class="form-group" style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn-submit" style="background: var(--gray-500);" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn-submit" style="background: var(--btn-delete);">
                        <i class="fas fa-times"></i> Reject Request
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Screenshot View Modal -->
    <div class="modal" id="screenshotModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-image"></i> Payment Screenshot</h3>
                <button class="modal-close" onclick="closeScreenshotModal()">&times;</button>
            </div>
            
            <div id="screenshotContainer" style="text-align: center; margin-bottom: 15px;">
                <img id="screenshotImage" class="screenshot-image" src="" alt="Payment Screenshot" style="max-width: 100%; max-height: 300px; border-radius: 8px;">
            </div>
            
            <div id="paymentDetails" class="guest-info"></div>
            
            <a href="verify-payments.php" class="btn-submit" style="text-align: center; text-decoration: none; display: block; background: var(--blue-500);">
                Go to Verify Payments
            </a>
        </div>
    </div>
    
    <script>
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
        
        function showOTPVerification(id, number, guestName) {
            document.getElementById('otp_reservation_id').value = id;
            document.getElementById('otpInfo').innerHTML = `
                <p><strong>Reservation:</strong> ${number}</p>
                <p><strong>Guest:</strong> ${guestName}</p>
                <p><strong>Type:</strong> Online Booking (OTP Required)</p>
            `;
            document.getElementById('otpModal').classList.add('active');
        }
        
        function closeOtpModal() {
            document.getElementById('otpModal').classList.remove('active');
            document.getElementById('otp_code').value = '';
        }
        
        function showRejectModal(requestId, guestName) {
            document.getElementById('reject_request_id').value = requestId;
            document.getElementById('rejectGuestInfo').innerHTML = `<strong>Rejecting request for:</strong> ${guestName}`;
            document.getElementById('rejectModal').classList.add('active');
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
            document.getElementById('reject_request_id').value = '';
            document.querySelector('[name="rejection_reason"]').value = '';
        }
        
        function viewScreenshot(filename, number, amount) {
            document.getElementById('screenshotImage').src = '<?php echo BASE_URL; ?>/uploads/payments/' + filename;
            document.getElementById('paymentDetails').innerHTML = `
                <p><strong>Reservation:</strong> ${number}</p>
                <p><strong>Amount Paid:</strong> ₱${amount.toFixed(2)}</p>
            `;
            document.getElementById('screenshotModal').classList.add('active');
        }
        
        function closeScreenshotModal() {
            document.getElementById('screenshotModal').classList.remove('active');
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const otpModal = document.getElementById('otpModal');
            const rejectModal = document.getElementById('rejectModal');
            const screenshotModal = document.getElementById('screenshotModal');
            const editModal = document.getElementById('editModal');
            
            if (event.target == otpModal) {
                closeOtpModal();
            }
            if (event.target == rejectModal) {
                closeRejectModal();
            }
            if (event.target == screenshotModal) {
                closeScreenshotModal();
            }
            if (event.target == editModal) {
                window.location.href = '?status=<?php echo $status_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($search); ?>';
            }
        }
    </script>
</body>
</html>