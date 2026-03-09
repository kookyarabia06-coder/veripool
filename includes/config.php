<?php
/**
 * Veripool Reservation System - Configuration File
 * FIXED: Only set session settings if no session is active
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Create logs directory if it doesn't exist
$logs_dir = __DIR__ . '/../logs';
if (!file_exists($logs_dir)) {
    mkdir($logs_dir, 0777, true);
}
ini_set('error_log', $logs_dir . '/error.log');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'veripool');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application configuration
define('APP_NAME', 'Veripool Resort');
define('APP_VERSION', '1.0.0');

// Base URL detection
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$dir_name = str_replace('\\', '/', dirname($script_name));
$base_url = rtrim($protocol . $host . $dir_name, '/');

// If in subdirectory, adjust base URL
if (strpos($base_url, '/veripool') !== false) {
    define('APP_URL', $base_url);
} else {
    define('APP_URL', $base_url . '/veripool');
}

// Timezone
date_default_timezone_set('Asia/Manila');

// ===== SESSION CONFIGURATION =====
// Only set session settings if NO session is active
if (session_status() === PHP_SESSION_NONE) {
    // No session active yet, we can set these
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', 0);
    ini_set('session.gc_maxlifetime', 7200);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);
    ini_set('session.name', 'VERIPOOL_SESSION');
    ini_set('session.cache_limiter', 'nocache');
    
    // Now start session
    session_start();
} else {
    // Session already active, just use it
    // Don't try to change any session settings
    error_log("Session already active in config.php - ID: " . session_id());
}

// Debug logging function
if (!function_exists('debug_log')) {
    function debug_log($message, $data = null) {
        $log_message = date('Y-m-d H:i:s') . " - " . $message;
        if ($data !== null) {
            $log_message .= " - " . print_r($data, true);
        }
        error_log($log_message);
    }
}

// Color palette constants
define('COLOR_NAVY', '#102C57');
define('COLOR_BLUE', '#1679AB');
define('COLOR_PEACH', '#FFB1B1');
define('COLOR_PINK', '#FFCBCB');

debug_log("Configuration loaded", [
    'APP_URL' => APP_URL,
    'session_status' => session_status(),
    'session_id' => session_id() ?: 'none'
]);
?>