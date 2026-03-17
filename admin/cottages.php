<?php
/**
 * Veripool Reservation System - Admin Cottages Page
 * Manage all cottages
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

// Get all cottages
$cottages = $db->getRows("
    SELECT c.*,
           (SELECT COUNT(*) FROM reservation_cottages rc 
            JOIN reservations r ON rc.reservation_id = r.id 
            WHERE rc.cottage_id = c.id AND r.status = 'checked_in') as is_occupied
    FROM cottages c
    ORDER BY c.id
");

// Get statistics
$total_cottages = count($cottages);
$available_cottages = $db->getValue("SELECT COUNT(*) FROM cottages WHERE status = 'available'");
$occupied_cottages = 0;
foreach ($cottages as $c) {
    if ($c['is_occupied'] > 0) $occupied_cottages++;
}
$maintenance_cottages = $db->getValue("SELECT COUNT(*) FROM cottages WHERE status = 'unavailable'");

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new cottage
        if ($_POST['action'] === 'add_cottage') {
            $cottage_name = sanitize($_POST['cottage_name']);
            $description = sanitize($_POST['description']);
            $capacity = (int)$_POST['capacity'];
            $size_sqm = (float)$_POST['size_sqm'];
            $price = (float)$_POST['price'];
            $cottage_type = sanitize($_POST['cottage_type']);
            $amenities = sanitize($_POST['amenities']);
            $status = sanitize($_POST['status']);
            
            $cottage_data = [
                'cottage_name' => $cottage_name,
                'description' => $description,
                'capacity' => $capacity,
                'size_sqm' => $size_sqm,
                'price' => $price,
                'cottage_type' => $cottage_type,
                'amenities' => $amenities,
                'status' => $status,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $cottage_id = $db->insert('cottages', $cottage_data);
            
            if ($cottage_id) {
                $message = "Cottage added successfully";
                $message_type = 'success';
                logAudit($_SESSION['user_id'], 'ADD_COTTAGE', 'cottages', $cottage_id, $cottage_data);
            } else {
                $message = "Failed to add cottage";
                $message_type = 'error';
            }
        }
        
        // Update cottage
        if ($_POST['action'] === 'update_cottage' && isset($_POST['cottage_id'])) {
            $cottage_id = (int)$_POST['cottage_id'];
            
            $update_data = [
                'cottage_name' => sanitize($_POST['cottage_name']),
                'description' => sanitize($_POST['description']),
                'capacity' => (int)$_POST['capacity'],
                'size_sqm' => (float)$_POST['size_sqm'],
                'price' => (float)$_POST['price'],
                'cottage_type' => sanitize($_POST['cottage_type']),
                'amenities' => sanitize($_POST['amenities']),
                'status' => sanitize($_POST['status'])
            ];
            
            $db->update('cottages', $update_data, 'id = :id', ['id' => $cottage_id]);
            
            $message = "Cottage updated successfully";
            $message_type = 'success';
            logAudit($_SESSION['user_id'], 'UPDATE_COTTAGE', 'cottages', $cottage_id, $update_data);
        }
        
        // Delete cottage
        if ($_POST['action'] === 'delete_cottage' && isset($_POST['cottage_id'])) {
            $cottage_id = (int)$_POST['cottage_id'];
            
            // Check if cottage has bookings
            $has_bookings = $db->getValue("SELECT COUNT(*) FROM reservation_cottages WHERE cottage_id = ?", [$cottage_id]);
            if ($has_bookings > 0) {
                $message = "Cannot delete cottage with existing bookings";
                $message_type = 'error';
            } else {
                $db->delete('cottages', 'id = :id', ['id' => $cottage_id]);
                $message = "Cottage deleted successfully";
                $message_type = 'success';
                logAudit($_SESSION['user_id'], 'DELETE_COTTAGE', 'cottages', $cottage_id);
            }
        }
        
        // Refresh data
        $cottages = $db->getRows("
            SELECT c.*,
                   (SELECT COUNT(*) FROM reservation_cottages rc 
                    JOIN reservations r ON rc.reservation_id = r.id 
                    WHERE rc.cottage_id = c.id AND r.status = 'checked_in') as is_occupied
            FROM cottages c
            ORDER BY c.id
        ");
        
        $available_cottages = $db->getValue("SELECT COUNT(*) FROM cottages WHERE status = 'available'");
        $maintenance_cottages = $db->getValue("SELECT COUNT(*) FROM cottages WHERE status = 'unavailable'");
        $occupied_cottages = 0;
        foreach ($cottages as $c) {
            if ($c['is_occupied'] > 0) $occupied_cottages++;
        }
    }
}

// Get cottage for editing if requested
$edit_cottage = null;
if (isset($_GET['edit'])) {
    $edit_cottage = $db->getRow("SELECT * FROM cottages WHERE id = ?", [$_GET['edit']]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cottages Management - Veripool Admin</title>
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
        /* ===== COASTAL HARMONY THEME - COTTAGES PAGE ===== */
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
            border-left-color: #C53030;
            color: #C53030;
            background: #FFF5F5;
            border-color: #FED7D7;
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
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }
        
        .quick-action {
            background: var(--white);
            padding: 12px 25px;
            border-radius: 40px;
            text-decoration: none;
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
        }
        
        .quick-action:hover {
            border-color: var(--blue-500);
            color: var(--blue-500);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .quick-action i {
            color: var(--blue-500);
        }
        
        .quick-action.primary {
            background: var(--blue-500);
            color: white;
            border-color: var(--blue-500);
        }
        
        .quick-action.primary i {
            color: white;
        }
        
        .quick-action.primary:hover {
            background: var(--blue-600);
        }
        
        /* Cottages Grid */
        .cottage-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
            position: relative;
            z-index: 2;
        }
        
        .cottage-card {
            background: var(--white);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            border-left: 4px solid var(--blue-500);
            transition: all 0.3s ease;
        }
        
        .cottage-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .cottage-card.available { border-left-color: var(--green-500); }
        .cottage-card.occupied { border-left-color: #C53030; }
        .cottage-card.unavailable { border-left-color: #ED8936; }
        
        .cottage-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .cottage-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            font-family: 'Montserrat', sans-serif;
        }
        
        .cottage-type-badge {
            background: var(--gray-100);
            color: var(--gray-700);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            border: 1px solid var(--gray-200);
        }
        
        .cottage-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--blue-500);
            margin: 10px 0;
            font-family: 'Montserrat', sans-serif;
        }
        
        .cottage-price small {
            font-size: 0.85rem;
            font-weight: 400;
            color: var(--gray-500);
        }
        
        .cottage-detail {
            margin: 8px 0;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        .cottage-detail i {
            width: 20px;
            color: var(--blue-500);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-available { 
            background: #DEF7EC; 
            color: var(--green-700); 
        }
        .status-occupied { 
            background: #FEE2E2; 
            color: #B91C1C; 
        }
        .status-unavailable { 
            background: #FEF3C7; 
            color: #92400E; 
        }
        
        .amenities-list {
            background: var(--gray-100);
            padding: 10px;
            border-radius: 8px;
            font-size: 0.85rem;
            color: var(--gray-700);
            margin: 15px 0;
            border: 1px solid var(--gray-200);
        }
        
        .amenities-list i {
            color: var(--blue-500);
            margin-right: 5px;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }
        
        .btn-icon {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
            text-decoration: none;
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-edit { 
            background: var(--blue-500); 
            color: white; 
        }
        .btn-edit:hover {
            background: var(--blue-600);
        }
        
        .btn-delete { 
            background: #C53030; 
            color: white; 
        }
        .btn-delete:hover {
            background: #9B2C2C;
        }
        
        .btn-delete:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
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
            max-width: 700px;
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
            color: #C53030;
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
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--blue-500);
            box-shadow: 0 0 0 3px rgba(43, 111, 139, 0.1);
        }
        
        .btn-submit {
            background: var(--blue-500);
            color: white;
            padding: 14px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
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
        
        .btn-outline {
            background: transparent;
            color: var(--gray-700);
            border: 2px solid var(--gray-300);
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .btn-outline:hover {
            border-color: var(--blue-500);
            color: var(--blue-500);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
            grid-column: 1 / -1;
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
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .cottage-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .quick-actions {
                flex-direction: column;
            }
            
            .quick-action {
                width: 100%;
                justify-content: center;
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
                <i class="fas fa-home"></i>
                Cottages Management
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
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $total_cottages; ?></div>
                <div class="label">Total Cottages</div>
            </div>
            <div class="stat-card" style="border-top-color: var(--green-500);">
                <div class="number"><?php echo $available_cottages; ?></div>
                <div class="label">Available</div>
            </div>
            <div class="stat-card" style="border-top-color: #C53030;">
                <div class="number"><?php echo $occupied_cottages; ?></div>
                <div class="label">Occupied</div>
            </div>
            <div class="stat-card" style="border-top-color: #ED8936;">
                <div class="number"><?php echo $maintenance_cottages; ?></div>
                <div class="label">Maintenance</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="#" onclick="openAddCottageModal()" class="quick-action primary">
                <i class="fas fa-plus-circle"></i>
                <span>Add New Cottage</span>
            </a>
            <a href="?status=available" class="quick-action">
                <i class="fas fa-check-circle"></i>
                <span>Available</span>
            </a>
            <a href="cottages.php" class="quick-action">
                <i class="fas fa-list"></i>
                <span>View All</span>
            </a>
        </div>
        
        <!-- Cottages Grid -->
        <div class="cottage-grid">
            <?php foreach ($cottages as $cottage): 
                $status_class = $cottage['status'];
                if ($cottage['is_occupied'] > 0) $status_class = 'occupied';
            ?>
            <div class="cottage-card <?php echo $status_class; ?>">
                <div class="cottage-header">
                    <span class="cottage-name"><?php echo htmlspecialchars($cottage['cottage_name']); ?></span>
                    <span class="cottage-type-badge"><?php echo ucfirst($cottage['cottage_type']); ?></span>
                </div>
                
                <div class="cottage-price">₱<?php echo number_format($cottage['price'], 2); ?> <small>/day</small></div>
                
                <div class="cottage-detail">
                    <i class="fas fa-users"></i> Capacity: <?php echo $cottage['capacity']; ?> guests
                </div>
                <div class="cottage-detail">
                    <i class="fas fa-ruler-combined"></i> Size: <?php echo $cottage['size_sqm']; ?> m²
                </div>
                
                <div style="margin: 15px 0;">
                    <?php if ($cottage['is_occupied'] > 0): ?>
                        <span class="status-badge status-occupied">
                            <i class="fas fa-user"></i> Currently Occupied
                        </span>
                    <?php else: ?>
                        <span class="status-badge status-<?php echo $cottage['status']; ?>">
                            <?php echo ucfirst($cottage['status']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="cottage-detail">
                    <i class="fas fa-align-left"></i> <?php echo htmlspecialchars(substr($cottage['description'], 0, 100)) . '...'; ?>
                </div>
                
                <?php if ($cottage['amenities']): ?>
                <div class="amenities-list">
                    <i class="fas fa-star"></i> <strong>Amenities:</strong> <?php echo htmlspecialchars($cottage['amenities']); ?>
                </div>
                <?php endif; ?>
                
                <div class="action-buttons">
                    <a href="?edit=<?php echo $cottage['id']; ?>" class="btn-icon btn-edit">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <?php if ($cottage['is_occupied'] == 0): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this cottage?');">
                        <input type="hidden" name="action" value="delete_cottage">
                        <input type="hidden" name="cottage_id" value="<?php echo $cottage['id']; ?>">
                        <button type="submit" class="btn-icon btn-delete">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($cottages)): ?>
            <div class="empty-state">
                <i class="fas fa-home"></i>
                <p>No cottages found. Add your first cottage!</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add Cottage Modal -->
    <div class="modal" id="addCottageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Cottage</h3>
                <button class="modal-close" onclick="closeAddCottageModal()">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_cottage">
                
                <div class="form-group">
                    <label for="cottage_name">Cottage Name *</label>
                    <input type="text" name="cottage_name" id="cottage_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description *</label>
                    <textarea name="description" id="description" class="form-control" rows="3" required></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="capacity">Capacity (guests) *</label>
                        <input type="number" name="capacity" id="capacity" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="size_sqm">Size (m²) *</label>
                        <input type="number" name="size_sqm" id="size_sqm" class="form-control" step="0.01" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="price">Price (₱) *</label>
                        <input type="number" name="price" id="price" class="form-control" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="cottage_type">Cottage Type *</label>
                        <select name="cottage_type" id="cottage_type" class="form-control" required>
                            <option value="open">Open</option>
                            <option value="closed">Closed</option>
                            <option value="nipa">Nipa</option>
                            <option value="family">Family</option>
                            <option value="vip">VIP</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="amenities">Amenities</label>
                        <textarea name="amenities" id="amenities" class="form-control" rows="2" placeholder="e.g., Table and Chairs, Karaoke, Grilling Station"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status *</label>
                        <select name="status" id="status" class="form-control" required>
                            <option value="available">Available</option>
                            <option value="unavailable">Unavailable</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Add Cottage
                </button>
            </form>
        </div>
    </div>
    
    <!-- Edit Cottage Modal -->
    <?php if ($edit_cottage): ?>
    <div class="modal active" id="editCottageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Cottage</h3>
                <a href="cottages.php" class="modal-close">&times;</a>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_cottage">
                <input type="hidden" name="cottage_id" value="<?php echo $edit_cottage['id']; ?>">
                
                <div class="form-group">
                    <label for="edit_cottage_name">Cottage Name *</label>
                    <input type="text" name="cottage_name" id="edit_cottage_name" class="form-control" 
                           value="<?php echo htmlspecialchars($edit_cottage['cottage_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_description">Description *</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="3" required><?php echo htmlspecialchars($edit_cottage['description']); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_capacity">Capacity (guests) *</label>
                        <input type="number" name="capacity" id="edit_capacity" class="form-control" 
                               value="<?php echo $edit_cottage['capacity']; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_size_sqm">Size (m²) *</label>
                        <input type="number" name="size_sqm" id="edit_size_sqm" class="form-control" step="0.01" 
                               value="<?php echo $edit_cottage['size_sqm']; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_price">Price (₱) *</label>
                        <input type="number" name="price" id="edit_price" class="form-control" step="0.01" 
                               value="<?php echo $edit_cottage['price']; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_cottage_type">Cottage Type *</label>
                        <select name="cottage_type" id="edit_cottage_type" class="form-control" required>
                            <option value="open" <?php echo $edit_cottage['cottage_type'] == 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="closed" <?php echo $edit_cottage['cottage_type'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                            <option value="nipa" <?php echo $edit_cottage['cottage_type'] == 'nipa' ? 'selected' : ''; ?>>Nipa</option>
                            <option value="family" <?php echo $edit_cottage['cottage_type'] == 'family' ? 'selected' : ''; ?>>Family</option>
                            <option value="vip" <?php echo $edit_cottage['cottage_type'] == 'vip' ? 'selected' : ''; ?>>VIP</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_amenities">Amenities</label>
                        <textarea name="amenities" id="edit_amenities" class="form-control" rows="2"><?php echo htmlspecialchars($edit_cottage['amenities']); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_status">Status *</label>
                        <select name="status" id="edit_status" class="form-control" required>
                            <option value="available" <?php echo $edit_cottage['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="unavailable" <?php echo $edit_cottage['status'] == 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Update Cottage
                </button>
                
                <div style="text-align: center; margin-top: 15px;">
                    <a href="cottages.php" class="btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }
        
        function openAddCottageModal() {
            document.getElementById('addCottageModal').classList.add('active');
        }
        
        function closeAddCottageModal() {
            document.getElementById('addCottageModal').classList.remove('active');
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
            const addModal = document.getElementById('addCottageModal');
            const editModal = document.getElementById('editCottageModal');
            
            if (event.target == addModal) {
                closeAddCottageModal();
            }
        }
    </script>
</body>
</html>