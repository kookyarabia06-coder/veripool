<?php
/**
 * Veripool Reservation System - Registration Page
 * Redesigned to match Coastal Harmony theme
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('/');
}

$auth = new Auth();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'username' => sanitize($_POST['username']),
        'email' => sanitize($_POST['email']),
        'password' => $_POST['password'],
        'full_name' => sanitize($_POST['full_name']),
        'phone' => sanitize($_POST['phone']),
        'address' => sanitize($_POST['address'])
    ];
    
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if (empty($data['username']) || empty($data['email']) || empty($data['password']) || empty($data['full_name'])) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (strlen($data['password']) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif ($data['password'] !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        $result = $auth->register($data);
        
        if ($result['success']) {
            $success = 'Registration successful! You can now login.';
            // Clear form
            $_POST = [];
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo APP_NAME; ?></title>
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
        /* ===== COASTAL HARMONY THEME - REGISTER PAGE ===== */
        /* Matching homepage and login page */
        
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
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Decorative background elements - matching homepage */
        body::before {
            content: '';
            position: absolute;
            top: -150px;
            right: -100px;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(43, 111, 139, 0.03) 0%, transparent 70%);
            border-radius: 50%;
            z-index: 0;
        }
        
        body::after {
            content: '';
            position: absolute;
            bottom: -150px;
            left: -100px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(47, 133, 90, 0.03) 0%, transparent 70%);
            border-radius: 50%;
            z-index: 0;
        }
        
        .register-wrapper {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 800px;
            padding: 20px;
        }
        
        .register-container {
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
        .register-container::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: linear-gradient(135deg, transparent 50%, var(--gray-100) 50%);
            opacity: 0.5;
            z-index: 0;
        }
        
        /* Colored top bar - matching homepage sections */
        .register-container::after {
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
        
        .logo {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-900);
            font-size: 2.2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            text-decoration: none;
            position: relative;
            display: inline-block;
            padding-left: 15px;
        }
        
        .logo::before {
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
            font-size: 1rem;
            margin-top: 10px;
        }
        
        /* Alerts */
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
        }
        
        .alert i {
            font-size: 1.2rem;
        }
        
        .alert-danger {
            background: #FFF5F5;
            border-left-color: #C53030;
            border: 1px solid #FED7D7;
            color: #C53030;
        }
        
        .alert-success {
            background: #F0FFF4;
            border-left-color: var(--green-500);
            border: 1px solid #C6F6D5;
            color: var(--green-700);
        }
        
        /* Form styling */
        .registration-form {
            position: relative;
            z-index: 2;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .full-width {
            grid-column: span 2;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        label i {
            color: var(--blue-500);
            font-size: 0.95rem;
        }
        
        .required {
            color: var(--blue-500);
            margin-left: 4px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 16px 14px 45px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        textarea.form-control {
            padding: 14px 16px 14px 45px;
            resize: vertical;
            min-height: 80px;
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
        
        textarea + i {
            top: 24px;
            transform: none;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--blue-500);
            box-shadow: 0 0 0 4px rgba(43, 111, 139, 0.1);
        }
        
        .form-control:focus + i {
            color: var(--blue-500);
        }
        
        /* Password strength indicator (optional enhancement) */
        .password-strength {
            margin-top: 8px;
            font-size: 0.8rem;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .password-strength i {
            color: var(--gray-400);
        }
        
        .strength-bar {
            display: flex;
            gap: 5px;
            margin-top: 5px;
        }
        
        .strength-segment {
            height: 4px;
            flex: 1;
            background: var(--gray-200);
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        /* Register button */
        .btn-register {
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
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-register::before {
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
        
        .btn-register:hover {
            background: var(--blue-600);
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(43, 111, 139, 0.3);
        }
        
        .btn-register:hover::before {
            left: 100%;
        }
        
        /* Footer links */
        .form-footer {
            text-align: center;
            margin-top: 30px;
            position: relative;
            z-index: 2;
        }
        
        .login-link {
            margin-bottom: 15px;
        }
        
        .login-link p {
            color: var(--gray-600);
            font-size: 0.95rem;
        }
        
        .login-link a {
            color: var(--blue-500);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .login-link a:hover {
            color: var(--green-500);
            text-decoration: underline;
        }
        
        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--gray-500);
            text-decoration: none;
            font-size: 0.9rem;
            padding: 8px 16px;
            border-radius: 30px;
            background: var(--gray-100);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .back-home i {
            transition: transform 0.3s ease;
        }
        
        .back-home:hover {
            color: var(--blue-500);
            border-color: var(--blue-500);
            background: var(--white);
        }
        
        .back-home:hover i {
            transform: translateX(-3px);
        }
        
        /* Terms and conditions */
        .terms {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
            font-size: 0.85rem;
            color: var(--gray-500);
            text-align: center;
        }
        
        .terms a {
            color: var(--blue-500);
            text-decoration: none;
        }
        
        .terms a:hover {
            color: var(--green-500);
            text-decoration: underline;
        }
        
        /* Decorative elements */
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
        @media (max-width: 768px) {
            .register-container {
                padding: 30px 20px;
                border-radius: 30px 30px 30px 60px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .full-width {
                grid-column: span 1;
            }
            
            .logo {
                font-size: 1.8rem;
            }
        }
        
        @media (max-width: 480px) {
            .register-wrapper {
                padding: 10px;
            }
            
            .register-container {
                padding: 25px 15px;
            }
        }
        
        /* Animation for success message */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            animation: fadeIn 0.5s ease;
        }
    </style>
</head>
<body>
    <div class="register-wrapper">
        <div class="register-container">
            <div class="logo-area">
                <a href="/" class="logo">Veripool Resort</a>
                <div class="welcome-text">Create your account and start your journey</div>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="registration-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">
                            <i class="fas fa-user"></i> Full Name <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input type="text" id="full_name" name="full_name" class="form-control" required 
                                   value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>"
                                   placeholder="Enter your full name">
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="username">
                            <i class="fas fa-at"></i> Username <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input type="text" id="username" name="username" class="form-control" required 
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                   placeholder="Choose a username">
                            <i class="fas fa-user-tag"></i>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope"></i> Email <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input type="email" id="email" name="email" class="form-control" required 
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                   placeholder="your@email.com">
                            <i class="fas fa-envelope"></i>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">
                            <i class="fas fa-phone"></i> Phone
                        </label>
                        <div class="input-wrapper">
                            <input type="tel" id="phone" name="phone" class="form-control" 
                                   value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                                   placeholder="+63 XXX XXX XXXX">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label for="address">
                        <i class="fas fa-map-marker-alt"></i> Address
                    </label>
                    <div class="input-wrapper">
                        <textarea id="address" name="address" class="form-control" rows="2" 
                                  placeholder="Enter your complete address"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                        <i class="fas fa-map-pin"></i>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">
                            <i class="fas fa-lock"></i> Password <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input type="password" id="password" name="password" class="form-control" required 
                                   placeholder="Min. 6 characters" minlength="6">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="password-strength">
                            <i class="fas fa-info-circle"></i>
                            <span>Password must be at least 6 characters</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">
                            <i class="fas fa-check-circle"></i> Confirm Password <span class="required">*</span>
                        </label>
                        <div class="input-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required 
                                   placeholder="Re-enter password">
                            <i class="fas fa-check"></i>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn-register">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </form>
            
            <div class="form-footer">
                <div class="login-link">
                    <p>Already have an account? <a href="/login.php">Sign in here</a></p>
                </div>
                
                <a href="/veripool/index.php" class="back-home">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>
                
                <div class="terms">
                    By creating an account, you agree to our 
                    <a href="#">Terms of Service</a> and 
                    <a href="#">Privacy Policy</a>
                </div>
            </div>
            
            <div class="decor-dots"></div>
        </div>
    </div>
    
    <script src="assets/js/main.js"></script>
</body>
</html>