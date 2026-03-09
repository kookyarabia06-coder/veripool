<?php
/**
 * Veripool Reservation System - Logout
 * Updated with ngrok compatibility
 */

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
session_start();

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Dynamic BASE_URL detection for ngrok compatibility
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];

// Get the directory without the filename
$script_dir = dirname($script_name);

// Navigate up if needed (if logout.php is in a subdirectory)
$path_parts = explode('/', trim($script_dir, '/'));
// If we're in a subdirectory like /admin or /staff or /guest, go up one level
if (!empty($path_parts) && in_array(end($path_parts), ['admin', 'staff', 'guest'])) {
    array_pop($path_parts); // Remove the last directory
}
$base_dir = '/' . implode('/', $path_parts);

// Construct the base URL
$base_url = rtrim($protocol . $host . $base_dir, '/');

// Determine the correct login page path
// If we're in a subdirectory, go to root login.php, otherwise just login.php
if (in_array(basename(dirname($_SERVER['PHP_SELF'])), ['admin', 'staff', 'guest'])) {
    $login_url = $base_url . '/login.php';
} else {
    $login_url = 'login.php';
}

// For debugging - you can remove this in production
error_log("Logout - Redirecting to: " . $login_url);
error_log("Logout - Base URL: " . $base_url);
error_log("Logout - Script name: " . $_SERVER['SCRIPT_NAME']);
error_log("Logout - Current directory: " . basename(dirname($_SERVER['PHP_SELF'])));

// Redirect to login page
header("Location: " . $login_url);
exit;
?>

<!-- Optional: Simple logout confirmation page if redirect fails -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out - Veripool Resort</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #102C57 0%, #1679AB 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            padding: 20px;
        }
        .logout-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 100%;
            text-align: center;
            animation: slideUp 0.5s ease;
        }
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        h2 {
            color: #102C57;
            margin-bottom: 20px;
        }
        p {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .spinner {
            border: 4px solid rgba(22, 121, 171, 0.1);
            border-left-color: #1679AB;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .redirect-link {
            color: #1679AB;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        .redirect-link:hover {
            color: #102C57;
            text-decoration: underline;
        }
        .debug-info {
            margin-top: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            font-size: 12px;
            text-align: left;
            border-left: 4px solid #1679AB;
            display: none; /* Hidden by default, show with ?debug=1 */
        }
        .debug-info h4 {
            color: #102C57;
            margin: 0 0 10px 0;
            font-size: 14px;
        }
        .debug-info pre {
            margin: 0;
            color: #333;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="spinner"></div>
        <h2>Logging you out...</h2>
        <p>You have been successfully logged out. Redirecting to login page.</p>
        <p>
            <a href="<?php echo isset($login_url) ? htmlspecialchars($login_url) : 'login.php'; ?>" class="redirect-link">
                Click here if you're not redirected automatically
            </a>
        </p>
        
        <?php if (isset($_GET['debug']) && $_GET['debug'] == 1): ?>
        <div class="debug-info" style="display: block;">
            <h4>Debug Information:</h4>
            <pre>
Base URL: <?php echo isset($base_url) ? htmlspecialchars($base_url) : 'Not set'; ?>
Login URL: <?php echo isset($login_url) ? htmlspecialchars($login_url) : 'Not set'; ?>
Script Name: <?php echo isset($_SERVER['SCRIPT_NAME']) ? htmlspecialchars($_SERVER['SCRIPT_NAME']) : 'Not set'; ?>
Current Dir: <?php echo isset($_SERVER['SCRIPT_NAME']) ? htmlspecialchars(basename(dirname($_SERVER['SCRIPT_NAME']))) : 'Not set'; ?>
Host: <?php echo isset($_SERVER['HTTP_HOST']) ? htmlspecialchars($_SERVER['HTTP_HOST']) : 'Not set'; ?>
Protocol: <?php echo isset($_SERVER['HTTPS']) ? ($_SERVER['HTTPS'] === 'on' ? 'HTTPS' : 'HTTP') : 'HTTP'; ?>
            </pre>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-redirect after 2 seconds
        setTimeout(function() {
            window.location.href = '<?php echo isset($login_url) ? htmlspecialchars($login_url) : 'login.php'; ?>';
        }, 2000);
    </script>
</body>
</html>