<?php
/**
 * Veripool Reservation System - Admin Users Page
 * Edit, view, and delete users (No add functionality)
 * Coastal Harmony Theme - Gray, Blue, Green
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

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Get database instance
$db = Database::getInstance();

// Get current user
$current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);

// Get filter from URL
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build query based on filters
$where = "WHERE 1=1";
$params = [];

if ($role_filter != 'all') {
    $where .= " AND role = :role";
    $params['role'] = $role_filter;
}

if ($status_filter != 'all') {
    $where .= " AND status = :status";
    $params['status'] = $status_filter;
}

// Get all users
$users = $db->getRows("
    SELECT * FROM users 
    $where
    ORDER BY created_at DESC
", $params);

// Get statistics
$total_users = $db->getValue("SELECT COUNT(*) FROM users");
$total_guests = $db->getValue("SELECT COUNT(*) FROM users WHERE role = 'guest'");
$total_staff = $db->getValue("SELECT COUNT(*) FROM users WHERE role = 'staff'");
$total_admins = $db->getValue("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'super_admin')");
$total_active = $db->getValue("SELECT COUNT(*) FROM users WHERE status = 'active'");
$total_inactive = $db->getValue("SELECT COUNT(*) FROM users WHERE status = 'inactive'");

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Update user (Edit)
        if ($_POST['action'] === 'update_user' && isset($_POST['user_id'])) {
            $user_id = (int)$_POST['user_id'];
            $full_name = sanitize($_POST['full_name']);
            $phone = sanitize($_POST['phone']) ?: null;
            $address = sanitize($_POST['address']) ?: null;
            $role = sanitize($_POST['role']);
            $status = sanitize($_POST['status']);
            
            $update_data = [
                'full_name' => $full_name,
                'phone' => $phone,
                'address' => $address,
                'role' => $role,
                'status' => $status
            ];
            
            // Update password if provided
            if (!empty($_POST['new_password'])) {
                if (strlen($_POST['new_password']) < 6) {
                    $message = "Password must be at least 6 characters";
                    $message_type = 'error';
                } else {
                    $update_data['password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                }
            }
            
            if (empty($message)) {
                $db->update('users', $update_data, 'id = :id', ['id' => $user_id]);
                $message = "User updated successfully";
                $message_type = 'success';
                logAudit($_SESSION['user_id'], 'UPDATE_USER', 'users', $user_id, $update_data);
            }
        }
        
        // Delete user - FIXED VERSION
        if ($_POST['action'] === 'delete_user' && isset($_POST['user_id'])) {
            $user_id = (int)$_POST['user_id'];
            
            // Don't allow deleting yourself
            if ($user_id == $_SESSION['user_id']) {
                $message = "You cannot delete your own account";
                $message_type = 'error';
            } else {
                // Start transaction
                $db->beginTransaction();
                
                try {
                    // Check if user has reservations
                    $has_reservations = $db->getValue("SELECT COUNT(*) FROM reservations WHERE user_id = ?", [$user_id]);
                    if ($has_reservations > 0) {
                        throw new Exception("Cannot delete user with existing reservations");
                    }
                    
                    // Check if user has payments
                    $has_payments = $db->getValue("
                        SELECT COUNT(*) FROM payments WHERE created_by = ?", [$user_id]
                    );
                    
                    // Check if user has audit trails
                    $has_audit_trails = $db->getValue("SELECT COUNT(*) FROM audit_trails WHERE user_id = ?", [$user_id]);
                    
                    // If there are audit trails, either delete them or update them
                    if ($has_audit_trails > 0) {
                        // Option 1: Delete audit trails first (if you want to completely remove user)
                        $db->delete('audit_trails', 'user_id = :user_id', ['user_id' => $user_id]);
                        
                        // Option 2: Or update them to set user_id to NULL (if you want to keep history)
                        // $db->update('audit_trails', ['user_id' => null], 'user_id = :user_id', ['user_id' => $user_id]);
                    }
                    
                    // If user created payments, either delete them or update them
                    if ($has_payments > 0) {
                        // Option: Set created_by to NULL
                        $db->update('payments', ['created_by' => null], 'created_by = :user_id', ['user_id' => $user_id]);
                    }
                    
                    // Finally delete the user
                    $db->delete('users', 'id = :id', ['id' => $user_id]);
                    
                    $db->commit();
                    
                    $message = "User deleted successfully";
                    $message_type = 'success';
                    logAudit($_SESSION['user_id'], 'DELETE_USER', 'users', $user_id);
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $message = "Error deleting user: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
        
        // Refresh users data
        $users = $db->getRows("
            SELECT * FROM users 
            $where
            ORDER BY created_at DESC
        ", $params);
    }
}

// Get user for editing if requested
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_GET['edit']]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - Veripool Admin</title>
    <!-- POP UP ICON -->
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
        /* ===== COASTAL HARMONY THEME - USERS PAGE ===== */
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
        
        /* Info Messages */
        .info-message {
            background: var(--blue-500);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
        }
        
        .info-message i {
            font-size: 1.2rem;
        }
        
        .warning-message {
            background: #FEF3C7;
            color: #92400E;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #FDE68A;
            border-left: 4px solid #ED8936;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            position: relative;
            z-index: 2;
        }
        
        .stat-card {
            background: var(--white);
            padding: 20px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            border-top: 4px solid var(--blue-500);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            font-family: 'Montserrat', sans-serif;
            line-height: 1.2;
        }
        
        .stat-card .label {
            color: var(--gray-600);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            min-width: 150px;
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
        
        .filter-select {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--blue-500);
            box-shadow: 0 0 0 3px rgba(43, 111, 139, 0.1);
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
        
        /* Card */
        .card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: 30px;
            overflow: hidden;
            position: relative;
            z-index: 2;
        }
        
        .card-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, var(--white), var(--gray-100));
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-900);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .card-header h3 i {
            color: var(--blue-500);
        }
        
        .card-header .badge {
            background: var(--gray-100);
            color: var(--gray-700);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid var(--gray-200);
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        
        table th {
            text-align: left;
            padding: 12px 8px;
            background: var(--gray-100);
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.75rem;
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
        
        /* Role Badges */
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-super_admin { 
            background: #1A202C; 
            color: #FBBF24; 
        }
        .role-admin { 
            background: var(--blue-500); 
            color: white; 
        }
        .role-staff { 
            background: #718096; 
            color: white; 
        }
        .role-guest { 
            background: var(--green-500); 
            color: white; 
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
        
        .status-active { 
            background: #DEF7EC; 
            color: var(--green-700); 
        }
        .status-inactive { 
            background: #FEE2E2; 
            color: var(--btn-delete); 
        }
        
        /* Action Buttons - CONSISTENT WITH OTHER PAGES */
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-icon {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
            transition: all 0.3s ease;
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
        
        .btn-edit { 
            background: var(--btn-edit); 
            color: white; 
        }
        .btn-edit:hover {
            background: var(--btn-edit-hover);
        }
        
        .btn-delete { 
            background: var(--btn-delete); 
            color: white; 
        }
        .btn-delete:hover {
            background: var(--btn-delete-hover);
        }
        
        .btn-delete:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .no-add-badge {
            background: var(--gray-500);
            color: white;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.7rem;
            margin-left: 10px;
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
            text-decoration: none;
        }
        
        .modal-close:hover {
            color: var(--btn-delete);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--gray-700);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
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
        
        .btn-submit {
            background: var(--blue-500);
            color: white;
            padding: 14px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-submit:hover {
            background: var(--blue-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        h4 {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-900);
            margin: 20px 0 10px;
            font-size: 1rem;
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
        
        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 992px) {
            .filter-section form {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
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
                <i class="fas fa-users"></i>
                Users Management
            </h1>
            <div class="date-info">
                <span><i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?></span>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Info Message -->
        <div class="info-message">
            <i class="fas fa-info-circle"></i>
            <span>You can edit existing users and delete them. New users can only be created through registration or by walk-in reservations.</span>
        </div>
        
        <!-- User Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $total_users; ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="stat-card" style="border-top-color: var(--green-500);">
                <div class="number"><?php echo $total_guests; ?></div>
                <div class="label">Guests</div>
            </div>
            <div class="stat-card" style="border-top-color: #718096;">
                <div class="number"><?php echo $total_staff; ?></div>
                <div class="label">Staff</div>
            </div>
            <div class="stat-card" style="border-top-color: var(--blue-500);">
                <div class="number"><?php echo $total_admins; ?></div>
                <div class="label">Admins</div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET">
                <div class="filter-group">
                    <label>Role</label>
                    <select name="role" class="filter-select">
                        <option value="all" <?php echo $role_filter == 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <option value="guest" <?php echo $role_filter == 'guest' ? 'selected' : ''; ?>>Guest</option>
                        <option value="staff" <?php echo $role_filter == 'staff' ? 'selected' : ''; ?>>Staff</option>
                        <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="super_admin" <?php echo $role_filter == 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" class="filter-select">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
                <div class="filter-group">
                    <a href="users.php" class="btn btn-outline">Clear Filters</a>
                </div>
            </form>
        </div>
        
        <!-- Users Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Users List</h3>
                <span class="badge"><?php echo count($users); ?> users</span>
            </div>
            <div class="card-body">
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>No users found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><strong>#<?php echo $user['id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo $user['phone'] ?: '—'; ?></td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $user['status']; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : 'Never'; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $user['id']; ?>" class="btn-icon btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?\n\nThis will also delete all associated audit trails.');">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn-icon btn-delete" title="Delete">
                                                    <i class="fas fa-trash"></i> Delete
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
    </div>
    
    <!-- Edit User Modal -->
    <?php if ($edit_user): ?>
    <div class="modal active" id="editUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit User</h3>
                <a href="users.php" class="modal-close">&times;</a>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_user['username']); ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($edit_user['email']); ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" name="full_name" id="full_name" class="form-control" 
                           value="<?php echo htmlspecialchars($edit_user['full_name']); ?>" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" name="phone" id="phone" class="form-control" 
                               value="<?php echo htmlspecialchars($edit_user['phone']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Role</label>
                        <select name="role" id="role" class="form-control" required>
                            <option value="guest" <?php echo $edit_user['role'] == 'guest' ? 'selected' : ''; ?>>Guest</option>
                            <option value="staff" <?php echo $edit_user['role'] == 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="admin" <?php echo $edit_user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <?php if ($current_user['role'] == 'super_admin'): ?>
                            <option value="super_admin" <?php echo $edit_user['role'] == 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="form-control" required>
                            <option value="active" <?php echo $edit_user['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $edit_user['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" name="address" id="address" class="form-control" 
                               value="<?php echo htmlspecialchars($edit_user['address']); ?>">
                    </div>
                </div>
                
                <h4>Change Password <small style="font-weight: normal; color: var(--gray-500);">(Optional)</small></h4>
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Leave blank to keep current">
                    <small style="color: var(--gray-500);">Min. 6 characters</small>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Update User
                </button>
                
                <div style="text-align: center; margin-top: 15px;">
                    <a href="users.php" class="btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
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
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editUserModal');
            if (event.target == modal) {
                window.location.href = 'users.php';
            }
        }
    </script>
</body>
</html>