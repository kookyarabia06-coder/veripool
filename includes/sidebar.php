<?php
/**
 * Veripool Reservation System - Reusable Sidebar
 * Include this file in all dashboard pages
 * Enhanced ngrok compatibility
 */

// Get current user role if not defined
if (!isset($current_user)) {
    global $db, $_SESSION;
    if (isset($_SESSION['user_id'])) {
        $current_user = $db->getRow("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
    }
}

$user_role = $current_user['role'] ?? 'guest';
$user_name = $current_user['full_name'] ?? $current_user['username'] ?? 'User';

// Enhanced Dynamic BASE_URL detection for ngrok compatibility
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$request_uri = $_SERVER['REQUEST_URI'];

// Get the directory without the filename
$script_dir = dirname($script_name);

// Handle different path scenarios
$path_parts = explode('/', trim($script_dir, '/'));

// Check if we're in a subdirectory like admin/staff/guest
$is_in_subdirectory = !empty($path_parts) && in_array(end($path_parts), ['admin', 'staff', 'guest']);

if ($is_in_subdirectory) {
    // Remove the last directory (admin/staff/guest) to get to root
    array_pop($path_parts);
}

// Build the base directory path
$base_dir = '/' . implode('/', $path_parts);
if ($base_dir == '/') {
    $base_dir = '';
}

// Define BASE_URL if not already defined
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim($protocol . $host . $base_dir, '/'));
}

// Also define a constant for assets
if (!defined('ASSETS_URL')) {
    define('ASSETS_URL', BASE_URL . '/assets');
}

// Get current page information
$current_page = basename($_SERVER['PHP_SELF']);
$current_directory = basename(dirname($_SERVER['PHP_SELF']));

// Check if any facilities page is active
$facilities_active = in_array($current_page, ['rooms.php', 'cottages.php', 'pools.php']);

// For debugging - log the values
error_log("Sidebar - BASE_URL: " . BASE_URL);
error_log("Sidebar - Current directory: " . $current_directory);
error_log("Sidebar - Script dir: " . $script_dir);
?>

<!-- Sidebar -->
<div class="sidebar <?php echo $user_role; ?>-sidebar" id="sidebar">
    <div class="sidebar-header <?php echo $user_role; ?>-header">
        <h2>
            <?php if ($user_role == 'super_admin'): ?>
                <i class="fas fa-crown"></i>
            <?php elseif ($user_role == 'admin'): ?>
                <i class="fas fa-user-shield"></i>
            <?php elseif ($user_role == 'staff'): ?>
                <i class="fas fa-user-tie"></i>
            <?php else: ?>
                <i class="fas fa-user"></i>
            <?php endif; ?>
            <span>
                <?php 
                if ($user_role == 'super_admin') echo 'Super Admin';
                elseif ($user_role == 'admin') echo 'Admin';
                elseif ($user_role == 'staff') echo 'Staff';
                else echo 'Guest Portal';
                ?>
            </span>
        </h2>
        <p><?php echo htmlspecialchars($user_name); ?></p>
        <small>
            <?php 
            if ($user_role == 'super_admin') echo 'Full Access';
            elseif ($user_role == 'admin') echo 'Management';
            elseif ($user_role == 'staff') echo 'Front Desk';
            else echo 'Welcome Back!';
            ?>
        </small>
        <?php if ($user_role == 'super_admin'): ?>
            <div class="role-badge">SUPER ADMIN</div>
        <?php endif; ?>
    </div>
    
    <ul class="sidebar-menu <?php echo $user_role; ?>" id="sidebarMenu">
        <?php if ($user_role == 'super_admin' || $user_role == 'admin'): ?>
            <!-- ===== ADMIN MENU ===== -->
            
            <!-- DASHBOARD SECTION -->
            <li class="menu-section-header">
                <span class="menu-section-title">DASHBOARD</span>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="<?php echo ($current_directory == 'admin' && $current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> <span>Main Dashboard</span>
                </a>
            </li>
        
            
            <!-- OPERATIONS SECTION -->
            <li class="menu-section-header">
                <span class="menu-section-title">OPERATIONS</span>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/walkin.php" class="<?php echo ($current_directory == 'admin' && $current_page == 'walkin.php') ? 'active' : ''; ?>">
                    <i class="fas fa-user-plus"></i> <span>Walk-in</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/reservations.php" class="<?php echo ($current_directory == 'admin' && $current_page == 'reservations.php') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i> <span>Reservations</span>
                    <?php
                    // Get pending reservations count for badge
                    if ($user_role == 'super_admin' || $user_role == 'admin') {
                        global $db;
                        $pending_count = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
                        if ($pending_count > 0) {
                            echo '<span class="menu-badge warning">' . $pending_count . '</span>';
                        }
                    }
                    ?>
                </a>
            </li>
            
            <!-- FACILITIES SECTION -->
            <li class="menu-section-header">
                <span class="menu-section-title">Manage Facilities</span>
            </li>
    
                
                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/rooms.php" class="<?php echo ($current_directory == 'admin' && $current_page == 'rooms.php') ? 'active' : ''; ?>">
                            <i class="fas fa-bed"></i> <span>Rooms</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/cottages.php" class="<?php echo ($current_directory == 'admin' && $current_page == 'cottages.php') ? 'active' : ''; ?>">
                            <i class="fas fa-home"></i> <span>Cottages</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/pools.php" class="<?php echo ($current_directory == 'admin' && $current_page == 'pools.php') ? 'active' : ''; ?>">
                            <i class="fas fa-swimmer"></i> <span>Pools</span>
                        </a>
                    </li>
                
            </li>
            
            <!-- USER MANAGEMENT SECTION -->
            <li class="menu-section-header">
                <span class="menu-section-title">USER MANAGEMENT</span>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/users.php" class="<?php echo ($current_directory == 'admin' && $current_page == 'users.php') ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> <span>Users</span>
                </a>
            </li>
            
            <!-- SYSTEM SECTION -->
            <li class="menu-section-header">
                <span class="menu-section-title">SYSTEM</span>
            </li>
            
            <li>
                <a href="<?php echo BASE_URL; ?>/admin/reports.php" class="<?php echo ($current_directory == 'admin' && $current_page == 'reports.php') ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i> <span>Reports</span>
                </a>
            </li>

        <?php elseif ($user_role == 'staff'): ?>
            <!-- ===== STAFF MENU ===== -->
            
            <!-- DASHBOARD SECTION -->
            <li class="menu-section-header">
                <span class="menu-section-title">DASHBOARD</span>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/staff/dashboard.php" class="<?php echo ($current_directory == 'staff' && $current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <!-- OPERATIONS SECTION -->
            <li class="menu-section-header">
                <span class="menu-section-title">OPERATIONS</span>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/staff/walkin.php" class="<?php echo ($current_directory == 'staff' && $current_page == 'walkin.php') ? 'active' : ''; ?>">
                    <i class="fas fa-user-plus"></i> <span>Walk-in</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/staff/guest_management.php" class="<?php echo ($current_directory == 'staff' && $current_page == 'guest_management.php') ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i> <span>Guest Management</span>
                    <?php
                    // Get current guests count for badge
                    global $db;
                    $current_guests = $db->getValue("SELECT COUNT(*) FROM reservations WHERE status = 'checked_in'");
                    if ($current_guests > 0) {
                        echo '<span class="menu-badge success">' . $current_guests . '</span>';
                    }
                    ?>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/staff/reservations.php" class="<?php echo ($current_directory == 'staff' && $current_page == 'reservations.php') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i> <span>Reservations</span>
                </a>
            </li>
            
           
            
        <?php else: ?>
            <!-- ===== GUEST MENU ===== -->
            
            <!-- OVERVIEW SECTION -->
            <li class="menu-section-header">
                <span class="menu-section-title">OVERVIEW</span>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/guest/dashboard.php" class="<?php echo ($current_directory == 'guest' && $current_page == 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
            </li>

             <li class="menu-section-header">
                <span class="menu-section-title">ACCOUNT</span>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/guest/profile.php" class="<?php echo ($current_directory == 'guest' && $current_page == 'profile.php') ? 'active' : ''; ?>">
                    <i class="fas fa-user-circle"></i> <span>Profile</span>
                </a>
            </li>
            
            <!-- RESERVATIONS SECTION -->
            <li class="menu-section-header">
                <span class="menu-section-title">RESERVATIONS</span>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/guest/new-reservation.php" class="<?php echo ($current_directory == 'guest' && $current_page == 'new-reservation.php') ? 'active' : ''; ?>">
                    <i class="fas fa-plus-circle"></i> <span>New Reservation</span>
                </a>
            </li>
            <li>
                <a href="<?php echo BASE_URL; ?>/guest/reservations.php" class="<?php echo ($current_directory == 'guest' && $current_page == 'reservations.php') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i> <span>My Reservations</span>
                    <?php
                    // Get user's pending reservations count
                    if (isset($_SESSION['user_id'])) {
                        global $db;
                        $user_reservations = $db->getValue("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'pending'", [$_SESSION['user_id']]);
                        if ($user_reservations > 0) {
                            echo '<span class="menu-badge warning">' . $user_reservations . '</span>';
                        }
                    }
                    ?>
                </a>
            </li>
    
            
        
        <?php endif; ?>
        
        <!-- Common menu items for all roles -->
        <li class="menu-divider"></li>
        <li class="menu-section-header">
            <span class="menu-section-title">GENERAL</span>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>/index.php">
                <i class="fas fa-arrow-left"></i> <span>Back to Home</span>
            </a>
        </li>
        <li class="logout">
            <a href="<?php echo BASE_URL; ?>/logout.php">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a>
        </li>
    </ul>
    
    <!-- NGROK DEBUG INFO - REMOVE IN PRODUCTION -->
    <div style="padding: 10px; font-size: 10px; color: rgba(255,255,255,0.3); text-align: center; border-top: 1px solid rgba(255,255,255,0.1); margin-top: 10px;">
        <small>BASE_URL: <?php echo BASE_URL; ?></small><br>
        <small>Host: <?php echo $_SERVER['HTTP_HOST']; ?></small><br>
        <small>Env: <?php echo (strpos($_SERVER['HTTP_HOST'], 'ngrok') !== false) ? 'ngrok' : 'localhost'; ?></small>
    </div>
</div>

<style>
/* ===== COASTAL HARMONY THEME - SIDEBAR ===== */
/* FORCE OVERRIDE - This will override any external CSS */
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

/* Force override all sidebar elements with !important */
.sidebar,
.sidebar *,
.sidebar-header,
.sidebar-header *,
.sidebar-menu,
.sidebar-menu *,
.sidebar-menu a,
.sidebar-menu a i,
.sidebar-menu a span,
.menu-section-header,
.menu-section-title,
.menu-badge,
.menu-divider,
.has-dropdown,
.dropdown-menu,
.dropdown-menu a,
.dropdown-menu a i,
.role-badge,
.logout a {
    /* Remove any pink/peach colors */
    background-color: transparent !important;
}

/* Dropdown Styles */
.has-dropdown {
    position: relative;
}

.has-dropdown > a {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    cursor: pointer !important;
    color: var(--gray-400) !important;
    padding: 10px 20px !important;
    font-size: 0.85rem !important;
    background-color: transparent !important;
    border-left: 3px solid transparent !important;
}

.has-dropdown > a i {
    color: var(--gray-500) !important;
}

.dropdown-arrow {
    transition: transform 0.3s ease;
    font-size: 0.7rem !important;
    margin-left: auto !important;
    margin-right: 5px !important;
    color: var(--gray-500) !important;
}

.has-dropdown.open .dropdown-arrow {
    transform: rotate(90deg);
    color: var(--blue-500) !important;
}

.has-dropdown.open > a {
    background-color: rgba(43, 111, 139, 0.08) !important;
    color: var(--white) !important;
}

.has-dropdown.open > a i {
    color: var(--blue-500) !important;
}

.dropdown-menu {
    list-style: none !important;
    padding-left: 0 !important;
    display: none;
    background-color: var(--gray-800) !important;
    margin: 0 !important;
    border-left: 2px solid var(--gray-700) !important;
}

.dropdown-menu li {
    margin: 0 !important;
}

.dropdown-menu li a {
    padding: 8px 20px 8px 45px !important;
    font-size: 0.8rem !important;
    border-left: 3px solid transparent !important;
    color: var(--gray-400) !important;
    background-color: transparent !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

.dropdown-menu li a:hover {
    background-color: rgba(43, 111, 139, 0.15) !important;
    border-left-color: var(--blue-500) !important;
    color: var(--white) !important;
}

.dropdown-menu li a:hover i {
    color: var(--blue-500) !important;
}

.dropdown-menu li a.active {
    background-color: rgba(43, 111, 139, 0.2) !important;
    border-left-color: var(--blue-500) !important;
    color: var(--white) !important;
}

.dropdown-menu li a.active i {
    color: var(--blue-500) !important;
}

.dropdown-menu li a i {
    font-size: 0.85rem !important;
    width: 18px !important;
    margin-right: 8px !important;
    color: var(--gray-500) !important;
    transition: all 0.3s ease;
}

/* Super Admin dropdown styles */
.super-admin .has-dropdown.open > a {
    color: #FBBF24 !important;
}

.super-admin .has-dropdown.open > a i {
    color: #FBBF24 !important;
}

.super-admin .dropdown-menu a:hover {
    background-color: rgba(251, 191, 36, 0.1) !important;
    color: #FBBF24 !important;
}

.super-admin .dropdown-menu a:hover i {
    color: #FBBF24 !important;
}

.super-admin .dropdown-menu a.active {
    background-color: rgba(251, 191, 36, 0.15) !important;
    color: #FBBF24 !important;
    border-left-color: #FBBF24 !important;
}

.super-admin .dropdown-menu a.active i {
    color: #FBBF24 !important;
}

/* Staff dropdown styles */
.staff .has-dropdown.open > a {
    color: var(--white) !important;
}

.staff .has-dropdown.open > a i {
    color: var(--blue-500) !important;
}

/* Menu badge */
.menu-badge {
    display: inline-block !important;
    padding: 2px 6px !important;
    border-radius: 20px !important;
    font-size: 0.6rem !important;
    font-weight: 600 !important;
    margin-left: 6px !important;
    border: 1px solid transparent !important;
    line-height: 1.2 !important;
}

.menu-badge.warning {
    background-color: #FEF3C7 !important;
    color: #92400E !important;
    border-color: #FDE68A !important;
}

.menu-badge.success {
    background-color: #DEF7EC !important;
    color: var(--green-700) !important;
    border-color: #B9F5D8 !important;
}

.menu-badge.danger {
    background-color: #FEE2E2 !important;
    color: #B91C1C !important;
    border-color: #FECACA !important;
}

/* Menu Section Headers */
.menu-section-header {
    padding: 8px 20px 2px 20px !important;
    margin-top: 2px !important;
    list-style: none !important;
    pointer-events: none !important;
    background-color: transparent !important;
}

.menu-section-title {
    font-size: 0.6rem !important;
    font-weight: 600 !important;
    letter-spacing: 0.8px !important;
    text-transform: uppercase !important;
    color: var(--gray-500) !important;
    display: block !important;
}

/* Super Admin section header colors */
.super-admin .menu-section-title {
    color: rgba(251, 191, 36, 0.6) !important;
}

/* Menu divider */
.menu-divider {
    height: 1px !important;
    background: linear-gradient(90deg, transparent, var(--gray-700), transparent) !important;
    margin: 8px 20px !important;
    list-style: none !important;
    border: none !important;
}

/* Role-based divider colors */
.super-admin .menu-divider {
    background: linear-gradient(90deg, transparent, rgba(251, 191, 36, 0.3), transparent) !important;
}

/* Sidebar menu items */
.sidebar-menu a {
    transition: all 0.2s ease !important;
    position: relative !important;
    overflow: hidden !important;
    padding: 10px 20px !important;
    font-size: 0.85rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    color: var(--gray-400) !important;
    text-decoration: none !important;
    border-left: 3px solid transparent !important;
    background-color: transparent !important;
}

.sidebar-menu a::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(43, 111, 139, 0.1), transparent);
    transition: left 0.5s ease;
    z-index: -1;
}

.sidebar-menu a:hover {
    background-color: var(--gray-800) !important;
    border-left-color: var(--blue-500) !important;
    color: var(--white) !important;
}

.sidebar-menu a:hover i {
    transform: translateX(3px) scale(1.1);
    color: var(--blue-500) !important;
}

.sidebar-menu a.active {
    background-color: var(--gray-800) !important;
    border-left-color: var(--green-500) !important;
    color: var(--white) !important;
    position: relative;
}

.sidebar-menu a.active i {
    color: var(--green-500) !important;
}

.sidebar-menu a.active::after {
    content: '';
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    width: 5px;
    height: 5px;
    background: var(--green-500) !important;
    border-radius: 50%;
    box-shadow: 0 0 8px var(--green-500);
}

/* Icon colors - Force override any pink */
.sidebar-menu a i.fa-tachometer-alt,
.sidebar-menu a i.fa-user-plus,
.sidebar-menu a i.fa-users,
.sidebar-menu a i.fa-bed,
.sidebar-menu a i.fa-calendar-check,
.sidebar-menu a i.fa-home,
.sidebar-menu a i.fa-swimmer,
.sidebar-menu a i.fa-credit-card,
.sidebar-menu a i.fa-chart-bar,
.sidebar-menu a i.fa-history,
.sidebar-menu a i.fa-check-circle,
.sidebar-menu a i.fa-sign-in-alt,
.sidebar-menu a i.fa-sign-out-alt,
.sidebar-menu a i.fa-arrow-left,
.sidebar-menu a i.fa-user-circle,
.sidebar-menu a i.fa-plus-circle,
.sidebar-menu a i.fa-users-cog,
.sidebar-menu a i.fa-crown,
.sidebar-menu a i.fa-user-shield,
.sidebar-menu a i.fa-user-tie {
    color: var(--gray-500) !important;
}

.sidebar-menu a i.fa-tachometer-alt { color: var(--blue-500) !important; }
.sidebar-menu a i.fa-user-plus { color: var(--green-500) !important; }
.sidebar-menu a i.fa-calendar-check { color: var(--green-500) !important; }
.sidebar-menu a i.fa-credit-card { color: var(--green-500) !important; }
.sidebar-menu a i.fa-check-circle { color: var(--green-500) !important; }
.sidebar-menu a i.fa-plus-circle { color: var(--green-500) !important; }
.sidebar-menu a i.fa-crown { color: #FBBF24 !important; }
.sidebar-menu a i.fa-sign-out-alt { color: #FC8181 !important; }

/* Role badge in header */
.role-badge {
    background-color: var(--gray-800) !important;
    color: var(--gray-300) !important;
    border: 1px solid var(--gray-700) !important;
    padding: 2px 8px !important;
    font-size: 0.6rem !important;
    border-radius: 20px !important;
    display: inline-block !important;
}

/* Sidebar header */
.sidebar-header {
    padding: 15px 15px !important;
    background-color: var(--gray-900) !important;
    border-bottom: 1px solid var(--gray-800) !important;
}

.sidebar-header h2 {
    font-size: 1.3rem !important;
    margin-bottom: 3px !important;
    color: var(--white) !important;
}

.sidebar-header h2 i {
    font-size: 1.2rem !important;
    color: var(--blue-500) !important;
}

.sidebar-header p {
    font-size: 0.8rem !important;
    margin-bottom: 2px !important;
    color: var(--gray-400) !important;
}

.sidebar-header small {
    font-size: 0.65rem !important;
    color: var(--gray-500) !important;
}

/* Sidebar base */
.sidebar {
    background-color: var(--gray-900) !important;
    color: var(--gray-300) !important;
    width: 250px !important;
    height: 100vh;
    position: fixed;
    overflow-y: hidden !important;
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--gray-800) !important;
}

.sidebar-menu {
    flex: 1;
    overflow-y: visible !important;
    padding: 5px 0 !important;
    list-style: none !important;
    background-color: var(--gray-900) !important;
}

/* Logout section */
.sidebar-menu .logout {
    margin-top: 5px !important;
    padding-top: 5px !important;
    border-top: 1px solid var(--gray-800) !important;
}

.sidebar-menu .logout a {
    color: var(--gray-400) !important;
}

.sidebar-menu .logout a:hover {
    background-color: rgba(220, 53, 69, 0.1) !important;
    border-left-color: #dc3545 !important;
    color: #ff6b6b !important;
}

.sidebar-menu .logout a:hover i {
    color: #dc3545 !important;
}

/* Remove any pink from anywhere */
[class*="FFB1B1"],
[class*="FFCBCB"],
[class*="102C57"],
[style*="FFB1B1"],
[style*="FFCBCB"],
[style*="102C57"] {
    background-color: transparent !important;
    color: inherit !important;
    border-color: inherit !important;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .sidebar-menu a {
        padding: 8px 15px !important;
        font-size: 0.8rem !important;
    }
    
    .sidebar {
        overflow-y: auto !important;
    }
}
</style>

<script>
// Toggle sidebar on mobile
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('show');
}

// Toggle facilities dropdown
function toggleFacilitiesDropdown(event) {
    event.preventDefault();
    event.stopPropagation();
    
    // Find the parent dropdown element
    const dropdownItem = event.currentTarget.closest('.has-dropdown');
    if (!dropdownItem) return;
    
    // Find the dropdown menu within this item
    const dropdownMenu = dropdownItem.querySelector('.dropdown-menu');
    if (!dropdownMenu) return;
    
    // Toggle the dropdown
    if (dropdownMenu.style.display === 'block') {
        dropdownMenu.style.display = 'none';
        dropdownItem.classList.remove('open');
    } else {
        dropdownMenu.style.display = 'block';
        dropdownItem.classList.add('open');
    }
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    
    if (window.innerWidth <= 768) {
        if (sidebar && !sidebar.contains(event.target) && menuToggle && !menuToggle.contains(event.target)) {
            sidebar.classList.remove('show');
        }
    }
});

// Initialize dropdowns on page load
document.addEventListener('DOMContentLoaded', function() {
    // Find all dropdowns that should be open based on active children
    document.querySelectorAll('.has-dropdown').forEach(function(dropdown) {
        const hasActiveChild = dropdown.querySelector('.dropdown-menu a.active');
        if (hasActiveChild) {
            const dropdownMenu = dropdown.querySelector('.dropdown-menu');
            if (dropdownMenu) {
                dropdownMenu.style.display = 'block';
                dropdown.classList.add('open');
            }
        }
    });
    
    // Log for debugging
    console.log('Sidebar initialized with dropdowns and section headers');
    console.log('Current BASE_URL:', '<?php echo BASE_URL; ?>');
    console.log('Environment:', '<?php echo (strpos($_SERVER['HTTP_HOST'], 'ngrok') !== false) ? 'ngrok' : 'localhost'; ?>');
});

// For debugging - check if function is defined
console.log('toggleFacilitiesDropdown function defined:', typeof toggleFacilitiesDropdown);
</script>