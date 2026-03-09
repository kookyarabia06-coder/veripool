<?php
/**
 * Veripool Reservation System - Helper Functions
 * Contains utility functions used throughout the application
 */

require_once 'Database.php';

/**
 * Debug function - prints variable in readable format
 */
function debug($data, $exit = false) {
    echo '<pre style="background: #f4f4f4; padding: 10px; border: 1px solid #ccc; margin: 10px;">';
    print_r($data);
    echo '</pre>';
    if ($exit) exit;
}

/**
 * Redirect to specified URL
 */
function redirect($url) {
    header("Location: " . APP_URL . $url);
    exit;
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check user role
 */
function hasRole($role) {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

/**
 * Check if user is admin or super admin
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'super_admin']);
}

/**
 * Get current user data
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    $db = Database::getInstance();
    return $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
}

/**
 * Sanitize input data
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Format date
 */
function formatDate($date, $format = 'M d, Y') {
    return date($format, strtotime($date));
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

/**
 * Get room types for dropdown
 */
function getRoomTypes() {
    $db = Database::getInstance();
    return $db->getRows("SELECT * FROM room_types ORDER BY name");
}

/**
 * Get cottages for dropdown
 */
function getCottages() {
    $db = Database::getInstance();
    return $db->getRows("SELECT * FROM cottages WHERE status = 'available' ORDER BY cottage_name");
}

/**
 * Get pools for display
 */
function getPools() {
    $db = Database::getInstance();
    return $db->getRows("SELECT * FROM pools WHERE status = 'open' ORDER BY name, type");
}

/**
 * Generate reservation number
 */
function generateReservationNumber() {
    $date = date('Ymd');
    $random = rand(1000, 9999);
    return "RES{$date}{$random}";
}

/**
 * Generate OTP code
 */
function generateOTP() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Log audit trail
 */
function logAudit($user_id, $action, $table_name = null, $record_id = null, $new_data = null, $old_data = null) {
    $db = Database::getInstance();
    
    $data = [
        'user_id' => $user_id,
        'action' => $action,
        'table_name' => $table_name,
        'record_id' => $record_id,
        'new_data' => $new_data ? json_encode($new_data) : null,
        'old_data' => $old_data ? json_encode($old_data) : null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ];
    
    return $db->insert('audit_trails', $data);
}

/**
 * Check room availability
 */
function checkRoomAvailability($room_id, $check_in, $check_out) {
    $db = Database::getInstance();
    
    $sql = "SELECT COUNT(*) FROM reservations 
            WHERE room_id = ? 
            AND status IN ('confirmed', 'checked_in')
            AND (
                (check_in_date <= ? AND check_out_date >= ?) OR
                (check_in_date <= ? AND check_out_date >= ?) OR
                (check_in_date >= ? AND check_out_date <= ?)
            )";
    
    $params = [$room_id, $check_out, $check_in, $check_in, $check_out, $check_in, $check_out];
    
    return $db->getValue($sql, $params) == 0;
}

/**
 * Get available rooms by type for date range
 */
function getAvailableRoomsByType($room_type_id, $check_in, $check_out) {
    $db = Database::getInstance();
    
    $sql = "SELECT r.*, rt.name as room_type_name, rt.base_price 
            FROM rooms r
            JOIN room_types rt ON r.room_type_id = rt.id
            WHERE r.room_type_id = ? 
            AND r.status = 'available'
            AND r.id NOT IN (
                SELECT room_id FROM reservations 
                WHERE status IN ('confirmed', 'checked_in')
                AND (
                    (check_in_date <= ? AND check_out_date >= ?) OR
                    (check_in_date <= ? AND check_out_date >= ?) OR
                    (check_in_date >= ? AND check_out_date <= ?)
                )
            )";
    
    $params = [$room_type_id, $check_out, $check_in, $check_in, $check_out, $check_in, $check_out];
    
    return $db->getRows($sql, $params);
}
