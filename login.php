<?php
/**
 * Veripool Reservation System - Simple Login Page
 * Redesigned to match Coastal Harmony theme
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
session_start();

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    redirectToDashboard($_SESSION['user_role']);
    exit;
}

// Database connection
$host = 'localhost';
$dbname = 'veripool';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$error = '';

// Helper function to redirect
function redirectToDashboard($role) {
    switch($role) {
        
        case 'admin':
            header("Location: admin/dashboard.php");
            break;
        case 'staff':
            header("Location: staff/dashboard.php");
            break;
        default:
            header("Location: guest/dashboard.php");
    }
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            // Get user from database
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_username'] = $user['username'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['logged_in'] = true;
                    
                    // Update last login
                    $update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $update->execute([$user['id']]);
                    
                    // Redirect to appropriate dashboard
                    redirectToDashboard($user['role']);
                    
                } else {
                    $error = 'Invalid username or password';
                }
            } else {
                $error = 'Invalid username or password';
            }
        } catch(PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Veripool Resort</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Import fonts to match homepage -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* ===== COASTAL HARMONY THEME - LOGIN PAGE ===== */
        /* Matching the homepage design with gray, blue, and green */
        
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
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--white) 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Decorative background elements - matching homepage */
        body::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(43, 111, 139, 0.03) 0%, transparent 70%);
            border-radius: 50%;
            z-index: 0;
        }
        
        body::after {
            content: '';
            position: absolute;
            bottom: -100px;
            left: -100px;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(47, 133, 90, 0.03) 0%, transparent 70%);
            border-radius: 50%;
            z-index: 0;
        }
        
        .login-wrapper {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 440px;
            padding: 20px;
        }
        
        .login-container {
            background: var(--white);
            padding: 40px;
            border-radius: 30px 30px 30px 100px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            animation: slideUp 0.6s ease;
            position: relative;
            overflow: hidden;
        }
        
        /* Decorative corner element */
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, transparent 50%, var(--gray-100) 50%);
            opacity: 0.5;
            z-index: 0;
        }
        
        /* Colored top bar - matching homepage sections */
        .login-container::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--blue-500), var(--green-500));
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Logo area */
        .logo-area {
            text-align: center;
            margin-bottom: 30px;
            position: relative;
            z-index: 2;
        }
        
        h1 {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-900);
            font-size: 2.2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 8px;
            position: relative;
            display: inline-block;
            padding-left: 15px;
        }
        
        h1::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 5px;
            height: 30px;
            background: linear-gradient(135deg, var(--blue-500), var(--green-500));
            border-radius: 5px;
        }
        
        .welcome-text {
            color: var(--gray-600);
            font-size: 0.95rem;
            margin-top: 5px;
        }
        
        /* Form styling */
        .form-group {
            margin-bottom: 22px;
            position: relative;
            z-index: 2;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        label i {
            color: var(--blue-500);
            font-size: 1rem;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        input {
            width: 100%;
            padding: 14px 16px 14px 45px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            font-size: 1.1rem;
            transition: color 0.3s ease;
            pointer-events: none;
        }
        
        input:focus {
            outline: none;
            border-color: var(--blue-500);
            box-shadow: 0 0 0 4px rgba(43, 111, 139, 0.1);
        }
        
        input:focus + i {
            color: var(--blue-500);
        }
        
        /* Password visibility toggle (optional) - add later with JS if needed */
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: var(--blue-500);
        }
        
        /* Options row (remember me + forgot password) */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            font-size: 0.9rem;
            z-index: 2;
            position: relative;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--gray-600);
            cursor: pointer;
        }
        
        .remember-me input[type="checkbox"] {
            width: auto;
            accent-color: var(--green-500);
        }
        
        .forgot-password {
            color: var(--blue-500);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .forgot-password:hover {
            color: var(--green-500);
            text-decoration: underline;
        }
        
        /* Login button */
        button {
            width: 100%;
            padding: 16px;
            background: var(--blue-500);
            color: white;
            border: none;
            border-radius: 40px;
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 10px 20px rgba(43, 111, 139, 0.2);
            position: relative;
            z-index: 2;
            overflow: hidden;
        }
        
        button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
            z-index: -1;
        }
        
        button:hover {
            background: var(--blue-600);
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(43, 111, 139, 0.3);
        }
        
        button:hover::before {
            left: 100%;
        }
        
        /* Error message */
        .error {
            background: #FFF5F5;
            color: #C53030;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 4px solid #C53030;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 2;
            border: 1px solid #FED7D7;
        }
        
        .error i {
            font-size: 1.1rem;
        }
        
        /* Demo credentials box - matching homepage cards */
        .demo-box {
            margin-top: 35px;
            padding: 25px;
            background: var(--gray-100);
            border-radius: 20px;
            border: 1px solid var(--gray-200);
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }
        
        .demo-box:hover {
            border-color: var(--blue-500);
            box-shadow: var(--shadow-md);
        }
        
        .demo-box h3 {
            color: var(--gray-900);
            margin-bottom: 18px;
            font-size: 1.1rem;
            font-family: 'Montserrat', sans-serif;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .demo-box h3 i {
            color: var(--green-500);
        }
        
        .demo-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        
        .demo-item {
            background: var(--white);
            padding: 12px 8px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .demo-item:hover {
            border-color: var(--green-500);
            transform: translateY(-2px);
        }
        
        .demo-item strong {
            display: block;
            color: var(--blue-500);
            font-size: 0.85rem;
            margin-bottom: 5px;
        }
        
        .demo-item p {
            color: var(--gray-600);
            font-size: 0.8rem;
            font-family: monospace;
            background: var(--gray-100);
            padding: 4px 8px;
            border-radius: 20px;
        }
        
        /* Form footer with links */
        .form-footer {
            margin-top: 30px;
            text-align: center;
            position: relative;
            z-index: 2;
        }
        
        .signup-link {
            margin-bottom: 15px;
        }
        
        .signup-link p {
            color: var(--gray-600);
            font-size: 0.95rem;
        }
        
        .signup-link a {
            color: var(--blue-500);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .signup-link a:hover {
            color: var(--green-500);
            text-decoration: underline;
        }
        
        .back-link {
            text-align: center;
            margin-top: 25px;
            position: relative;
            z-index: 2;
        }
        
        .back-link a {
            color: var(--gray-500);
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            padding: 8px 16px;
            border-radius: 30px;
            background: var(--gray-100);
            border: 1px solid var(--gray-200);
        }
        
        .back-link a i {
            transition: transform 0.3s ease;
        }
        
        .back-link a:hover {
            color: var(--blue-500);
            border-color: var(--blue-500);
            background: var(--white);
        }
        
        .back-link a:hover i {
            transform: translateX(-3px);
        }
        
        /* Additional decorative elements */
        .decor-dots {
            position: absolute;
            bottom: 20px;
            right: 20px;
            font-size: 2rem;
            color: var(--gray-200);
            opacity: 0.3;
            z-index: 1;
            pointer-events: none;
        }
        
        .decor-dots::before {
            content: '✦ ✦ ✦';
        }
        
        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
                border-radius: 30px 30px 30px 60px;
            }
            
            .demo-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            h1 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <div class="logo-area">
                <h1>Veripool Resort</h1>
                <div class="welcome-text">Welcome back! Please login to your account.</div>
            </div>
            
            <?php if ($error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i> Username or Email
                    </label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" required 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                               placeholder="Enter your username or email">
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" required 
                               placeholder="Enter your password">
                        <i class="fas fa-lock"></i>
                        <!-- Optional password toggle (add with JS later if wanted) -->
                        <!-- <span class="password-toggle"><i class="fas fa-eye"></i></span> -->
                    </div>
                </div>
                
                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember"> Remember me
                    </label>
                    <a href="#" class="forgot-password">Forgot Password?</a>
                </div>
                
                <button type="submit">
                    <i class="fas fa-sign-in-alt" style="margin-right: 8px;"></i> Login
                </button>
            </form>
            
            <div class="demo-box">
                <h3>
                    <i class="fas fa-key"></i> Demo Credentials
                </h3>
                <div class="demo-grid">
                    <div class="demo-item">
                        <strong>👑 Admin</strong>
                        <p>admin1 / password</p>
                    </div>
                    <div class="demo-item">
                        <strong>👔 Staff</strong>
                        <p>staff1 / password</p>
                    </div>
                    <div class="demo-item">
                        <strong>👤 Guest</strong>
                        <p>kooky / password</p>
                    </div>
                </div>
            </div>
            
            <div class="form-footer">
                <div class="signup-link">
                    <p>No account? <a href="/veripool/register.php">Sign up now</a></p>
                </div>
                
                <div class="back-link">
                    <a href="/veripool/index.php">
                        <i class="fas fa-arrow-left"></i> Back to Homepage
                    </a>
                </div>
            </div>
            
            <div class="decor-dots"></div>
        </div>
    </div>
    
    <!-- Optional: Add password toggle functionality -->
    <script>
        // Simple password visibility toggle (optional)
        // Uncomment if you want to add the toggle functionality
        
        document.addEventListener('DOMContentLoaded', function() {
            const togglePassword = document.querySelector('.password-toggle');
            if (togglePassword) {
                const passwordInput = document.querySelector('#password');
                
                togglePassword.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.querySelector('i').classList.toggle('fa-eye');
                    this.querySelector('i').classList.toggle('fa-eye-slash');
                });
            }
        });
    </script>
</body>
</html>