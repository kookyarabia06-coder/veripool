<?php
/**
 * Veripool Reservation System - Homepage
 * Resort website with availability checking
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session at the very top
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define base path
define('BASE_PATH', __DIR__);

// Detect base URL dynamically - FIXED VERSION
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];

// Get the directory without the filename
$script_dir = dirname($script_name);

// If we're in a subdirectory, use that, otherwise empty string
$base_dir = ($script_dir !== '/' && $script_dir !== '\\') ? $script_dir : '';

// Define BASE_URL without double subdirectory
define('BASE_URL', rtrim($protocol . $host . $base_dir, '/'));

// For debugging - you can remove this after testing
echo "<!-- DEBUG: BASE_URL = " . BASE_URL . " -->";

// Include required files with error handling
$required_files = [
    'includes/config.php',
    'includes/functions.php',
    'includes/Database.php'
];

foreach ($required_files as $file) {
    $file_path = BASE_PATH . '/' . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    } else {
        die("Error: Required file not found: $file. Please make sure all files are in place.");
    }
}

// Rest of your code continues...
// Initialize database
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get ALL rooms for display - ORDER BY ID to show 1,2,3,4 in order
$rooms = [];
try {
    $rooms = $db->getRows("
        SELECT rt.*, 
               (SELECT COUNT(*) FROM rooms WHERE room_type_id = rt.id AND status = 'available') as available_rooms
        FROM room_types rt 
        ORDER BY rt.id ASC
    ");
} catch (Exception $e) {
    error_log("Error fetching rooms: " . $e->getMessage());
}

// Get ALL cottages for display
$cottages = [];
try {
    $cottages = $db->getRows("
        SELECT * FROM cottages 
        WHERE status = 'available' 
        ORDER BY id ASC
    ");
} catch (Exception $e) {
    error_log("Error fetching cottages: " . $e->getMessage());
}

// Get pools for display
$pools = [];
try {
    $pools = getPools();
} catch (Exception $e) {
    error_log("Error fetching pools: " . $e->getMessage());
}

// Handle availability check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_availability'])) {
    $check_in = isset($_POST['check_in']) ? sanitize($_POST['check_in']) : '';
    $check_out = isset($_POST['check_out']) ? sanitize($_POST['check_out']) : '';
    $guests = isset($_POST['guests']) ? (int)sanitize($_POST['guests']) : 1;
    
    if ($check_in && $check_out) {
        // Store in session for reservation page
        $_SESSION['availability'] = [
            'check_in' => $check_in,
            'check_out' => $check_out,
            'guests' => $guests
        ];
        
        // Redirect to reservation page
        header("Location: " . BASE_URL . "/reservation.php");
        exit;
    }
}

// Simple login check function
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('hasRole')) {
    function hasRole($role) {
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veripool Resort - Luxury Resort</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">
    
    <!-- FIXED: Use root-relative paths for CSS -->
    <link rel="stylesheet" href="/veripool/assets/css/stylers.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Alternative: If you want to use BASE_URL, it will now work correctly -->
    <!-- <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"> -->
</head>
<body>
    <!-- Header Section -->
    <header class="header">
        <nav class="navbar">
            <a href="/veripool/" class="logo">Veripool Resort</a>
            <ul class="nav-links">
                <li><a href="#home" class="active">Home</a></li>
                <li><a href="#rooms">Rooms</a></li>
                <li><a href="#cottages">Cottages</a></li>
                <li><a href="#contact">Contact</a></li>
                <?php if (isLoggedIn()): ?>
                    <li>
                        <a href="<?php 
                            if (hasRole('super_admin') || hasRole('admin')) {
                                echo '/veripool/admin/dashboard.php';
                            } elseif (hasRole('staff')) {
                                echo '/veripool/staff/dashboard.php';
                            } else {
                                echo '/veripool/guest/dashboard.php';
                            }
                        ?>">
                            <i class="fas fa-user"></i> Dashboard
                        </a>
                    </li>
                    <li><a href="/veripool/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                <?php else: ?>
                    <li><a href="/veripool/login.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                    <li><a href="/veripool/register.php"><i class="fas fa-user-plus"></i> Register</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="hero-content">
            <h1>A Charming 5* Luxury Resort</h1>
            <p>Experience unparalleled luxury and adventure in our beautiful resort. 
               With stunning views, world-class amenities, and exceptional service, 
               your dream vacation awaits.</p>
            <a href="#availability" class="btn btn-primary">Check Availability</a>
        </div>
    </section>

    <!-- Availability Section -->
    <section id="availability" class="availability-section">
        <div class="container">
            <h2 class="section-title">Check The Availability</h2>
            
            <div class="availability-grid">
                <div class="availability-card">
                    <h3>Reservation Info</h3>
                    <p><i class="fas fa-clock"></i> Check-In: 2:00 PM - 7:00 PM</p>
                    <p><i class="fas fa-clock"></i> Check-Out: 8:00 AM - 12:00 PM</p>
                </div>
                <div class="availability-card">
                    <h3>Pool Hours</h3>
                    <?php if (!empty($pools)): ?>
                        <?php foreach ($pools as $pool): ?>
                            <p><i class="fas fa-swimmer"></i> <?php echo htmlspecialchars($pool['name'] ?? ''); ?> (<?php echo htmlspecialchars($pool['type'] ?? ''); ?>): <?php echo htmlspecialchars($pool['operating_hours'] ?? ''); ?></p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Pool information coming soon.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="availability-form">
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="check_in">Check-In Date</label>
                            <input type="date" id="check_in" name="check_in" class="form-control" 
                                   min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="check_out">Check-Out Date</label>
                            <input type="date" id="check_out" name="check_out" class="form-control" 
                                   min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="guests">Guests</label>
                            <select id="guests" name="guests" class="form-control" required>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php echo $i === 1 ? 'Guest' : 'Guests'; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" name="check_availability" class="btn btn-primary" style="width: 100%;">
                                Check Availability
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- ROOMS SECTION - ALL 4 ROOMS INLINE WITH BEAUTIFUL CARDS -->
    <section id="rooms" class="rooms-section">
        <div class="container">
            <h2 class="section-title">Our Rooms</h2>
            
            <!-- 4-column grid for rooms -->
            <div class="rooms-grid">
                <?php if (!empty($rooms)): ?>
                    <?php 
                    $room_count = 0;
                    foreach ($rooms as $room): 
                        $room_count++;
                    ?>
                    <div class="room-card">
                        <div class="room-image">
                            <?php if (!empty($room['image_path']) && file_exists($room['image_path'])): ?>
                                <img src="<?php echo htmlspecialchars($room['image_path']); ?>" alt="<?php echo htmlspecialchars($room['name']); ?>">
                            <?php else: ?>
                                <!-- Gradient background alternating colors -->
                                <div style="width: 100%; height: 100%; background: linear-gradient(135deg, <?php echo ($room_count % 2 == 0) ? '#1679AB, #102C57' : '#FFB1B1, #FFCBCB'; ?>);"></div>
                            <?php endif; ?>
                            
                            <!-- Available badge -->
                            <?php if (($room['available_rooms'] ?? 0) > 0): ?>
                                <span class="room-badge">
                                    <i class="fas fa-check-circle"></i> Available
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="room-content">
                            <h3 class="room-title"><?php echo htmlspecialchars($room['name'] ?? ''); ?></h3>
                            
                            <div class="room-meta">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($room['max_occupancy'] ?? 0); ?> Guests</span>
                            </div>
                            
                            <p class="room-description">
                                <?php echo htmlspecialchars(substr($room['description'] ?? 'Comfortable room with basic amenities', 0, 60)); ?>...
                            </p>
                            
                            <div class="room-price">
                                ₱<?php echo number_format($room['base_price'] ?? 0, 2); ?> <small>per night</small>
                            </div>
                            
                            <a href="/veripool/reservation.php?type=room&id=<?php echo $room['id']; ?>" class="btn btn-outline">
                                Book Now <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Show room count -->
                    <div style="width: 100%; text-align: center; margin-top: 20px; color: #666; grid-column: 1 / -1;">
                        Showing <?php echo $room_count; ?> rooms
                    </div>
                    
                <?php else: ?>
                    <p style="text-align: center; color: #666; grid-column: 1 / -1; padding: 40px;">
                        No rooms available at the moment.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- COTTAGES SECTION - ALL COTTAGES INLINE WITH BEAUTIFUL CARDS -->
    <section id="cottages" class="cottages-section">
        <div class="container">
            <h2 class="section-title">Our Cottages</h2>
            
            <!-- 4-column grid for cottages -->
            <div class="cottages-grid">
                <?php if (!empty($cottages)): ?>
                    <?php 
                    $cottage_count = 0;
                    foreach ($cottages as $cottage): 
                        $cottage_count++;
                    ?>
                    <div class="cottage-card">
                        <div class="cottage-image">
                            <!-- Cottage image background -->
                            <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #FFB1B1, #FFCBCB);"></div>
                            
                            <!-- Cottage type badge -->
                            <span class="cottage-badge">
                                <i class="fas fa-tag"></i> <?php echo ucfirst(htmlspecialchars($cottage['cottage_type'] ?? '')); ?>
                            </span>
                        </div>
                        
                        <div class="cottage-content">
                            <h3 class="cottage-title"><?php echo htmlspecialchars($cottage['cottage_name'] ?? ''); ?></h3>
                            
                            <div class="cottage-meta">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($cottage['capacity'] ?? 0); ?> Guests</span>
                                <span><i class="fas fa-ruler-combined"></i> <?php echo htmlspecialchars($cottage['size_sqm'] ?? 0); ?> m²</span>
                            </div>
                            
                            <p class="cottage-description">
                                <?php echo htmlspecialchars(substr($cottage['description'] ?? '', 0, 60)); ?>...
                            </p>
                            
                            <div class="cottage-price">
                                ₱<?php echo number_format($cottage['price'] ?? 0, 2); ?> <small>per day</small>
                            </div>
                            
                            <a href="/veripool/reservation.php?type=cottage&id=<?php echo $cottage['id']; ?>" class="btn btn-outline">
                                Book Now <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Show cottage count -->
                    <div style="width: 100%; text-align: center; margin-top: 20px; color: #666; grid-column: 1 / -1;">
                        Showing <?php echo $cottage_count; ?> cottages
                    </div>
                    
                <?php else: ?>
                    <p style="text-align: center; color: #666; grid-column: 1 / -1; padding: 40px;">
                        No cottages available at the moment.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="contact-section">
        <div class="container">
            <div class="contact-grid">
                <div>
                    <h2 class="section-title" style="text-align: left;">Get In Touch</h2>
                    <p style="margin-bottom: 2rem; color: #666;">
                        Have questions about our resort or want to make a special request? 
                        We're here to help make your stay perfect. Reach out to us anytime.
                    </p>
                    
                    <form id="contactForm" method="POST" action="/veripool/contact.php">
                        <div class="form-group">
                            <input type="text" name="name" class="form-control" placeholder="Your Name" required>
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" class="form-control" placeholder="Your Email" required>
                        </div>
                        <div class="form-group">
                            <textarea name="message" class="form-control" rows="5" placeholder="Your Message" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </form>
                </div>
                
                <div class="contact-info">
                    <h3>Contact Information</h3>
                    
                    <div class="contact-info-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <div>
                            <strong>Address:</strong><br>
                            350 5th Ave, New York, NY 10118, USA
                        </div>
                    </div>
                    
                    <div class="contact-info-item">
                        <i class="fas fa-phone"></i>
                        <div>
                            <strong>Phone:</strong><br>
                            +1 234 567 890
                        </div>
                    </div>
                    
                    <div class="contact-info-item">
                        <i class="fas fa-envelope"></i>
                        <div>
                            <strong>Email:</strong><br>
                            reservations@veripool.com
                        </div>
                    </div>
                    
                    <div class="contact-info-item">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong>Office Hours:</strong><br>
                            Mon - Sun: 8:00 AM - 10:00 PM
                        </div>
                    </div>
                    
                    <div class="contact-map">
                        <!-- Google Maps embed would go here -->
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <h4>Veripool Resort</h4>
                    <p>Experience luxury and adventure in our beautiful resort. Your dream vacation starts here.</p>
                </div>
                
                <div class="footer-col">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="#home">Home</a></li>
                        <li><a href="#rooms">Rooms</a></li>
                        <li><a href="#cottages">Cottages</a></li>
                        <li><a href="#activities">Activities</a></li>
                        <li><a href="#contact">Contact</a></li>
                    </ul>
                </div>
                
                <div class="footer-col">
                    <h4>Contact Info</h4>
                    <ul>
                        <li><i class="fas fa-phone"></i> +1 234 567 890</li>
                        <li><i class="fas fa-envelope"></i> info@veripool.com</li>
                        <li><i class="fas fa-map-marker-alt"></i> 350 5th Ave, NY</li>
                    </ul>
                </div>
                
                <div class="footer-col">
                    <h4>Follow Us</h4>
                    <ul>
                        <li><a href="#"><i class="fab fa-facebook"></i> Facebook</a></li>
                        <li><a href="#"><i class="fab fa-instagram"></i> Instagram</a></li>
                        <li><a href="#"><i class="fab fa-twitter"></i> Twitter</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> Veripool Resort. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Debug Panel -->
    <div class="debug-panel" id="debugPanel">
        <h4>Debug Information</h4>
        <pre>
<?php
echo "Session:\n";
print_r($_SESSION);
echo "\nPOST:\n";
print_r($_POST);
echo "\nUser Logged In: " . (isLoggedIn() ? 'Yes' : 'No');
echo "\nUser Role: " . (isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'None');
echo "\n\nRooms Found: " . count($rooms);
echo "\nCottages Found: " . count($cottages);
echo "\n\nBASE_URL: " . BASE_URL;
echo "\nSCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'];
?>
        </pre>
    </div>

    <script src="/veripool/assets/js/main.js"></script>
    <script>
        // Toggle debug panel with Ctrl+Shift+D
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && e.key === 'D') {
                document.getElementById('debugPanel').classList.toggle('show');
            }
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Auto-hide debug panel after 10 seconds
        setTimeout(function() {
            document.getElementById('debugPanel').classList.remove('show');
        }, 10000);
    </script>
</body>
</html>