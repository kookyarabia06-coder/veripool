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

<!-- Mobile Menu Toggle -->
<button class="menu-toggle" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i> Menu
</button>

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
/* Dropdown Styles */
.has-dropdown {
    position: relative;
}

.has-dropdown > a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
}

.dropdown-arrow {
    transition: transform 0.3s ease;
    font-size: 0.8rem;
    margin-left: auto;
    margin-right: 5px;
}

.has-dropdown.open .dropdown-arrow {
    transform: rotate(90deg);
}

.dropdown-menu {
    list-style: none;
    padding-left: 0;
    display: none;
    background: rgba(0, 0, 0, 0.15);
    margin: 0;
}

.dropdown-menu li {
    margin: 0;
}

.dropdown-menu li a {
    padding: 10px 25px 10px 55px;
    font-size: 0.9rem;
    border-left: 3px solid transparent;
}

.dropdown-menu li a:hover {
    background: rgba(255,177,177,0.15);
    border-left-color: #FFB1B1;
}

.dropdown-menu li a.active {
    background: rgba(255,177,177,0.2);
    border-left-color: #FFB1B1;
    color: #FFB1B1;
}

.dropdown-menu li a i {
    font-size: 1rem;
    width: 20px;
    margin-right: 10px;
}

/* Active states for dropdown */
.has-dropdown.open {
    background: rgba(255,177,177,0.05);
}

.has-dropdown.open > a {
    color: #FFB1B1;
}

/* Super Admin dropdown styles */
.super-admin .has-dropdown.open > a {
    color: #FFD700;
}

.super-admin .dropdown-menu a:hover {
    background: rgba(255,215,0,0.1);
    color: #FFD700;
}

.super-admin .dropdown-menu a.active {
    background: rgba(255,215,0,0.15);
    color: #FFD700;
    border-left-color: #FFD700;
}

/* Staff dropdown styles */
.staff .has-dropdown.open > a {
    color: #FFB1B1;
}

/* Menu badge */
.menu-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-left: 8px;
}

.menu-badge.warning {
    background: #ffc107;
    color: #102C57;
}

.menu-badge.success {
    background: #28a745;
    color: white;
}

.menu-badge.danger {
    background: #dc3545;
    color: white;
}

/* Menu Section Headers */
.menu-section-header {
    padding: 15px 20px 5px 20px;
    margin-top: 5px;
    list-style: none;
    pointer-events: none;
}

.menu-section-title {
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.4);
    display: block;
}

/* Super Admin section header colors */
.super-admin .menu-section-title {
    color: rgba(255, 215, 0, 0.4);
}

/* Staff section header colors */
.staff .menu-section-title {
    color: rgba(255, 177, 177, 0.4);
}

/* Guest section header colors */
.guest .menu-section-title {
    color: rgba(255, 177, 177, 0.4);
}

/* Menu divider */
.menu-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.1);
    margin: 15px 20px;
    list-style: none;
}

/* Role-based divider colors */
.super-admin .menu-divider {
    background: rgba(255, 215, 0, 0.2);
}

.staff .menu-divider {
    background: rgba(255, 177, 177, 0.2);
}

.guest .menu-divider {
    background: rgba(255, 177, 177, 0.2);
}

/* Adjust spacing for menu items */
.sidebar-menu li:not(.menu-section-header):not(.menu-divider) {
    margin: 2px 0;
}

/* Hover effects for section headers (disabled) */
.menu-section-header:hover {
    background: none;
    cursor: default;
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