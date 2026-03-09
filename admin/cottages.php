<?php
/**
 * Veripool Reservation System - Admin Cottages Page
 * Manage all cottages
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
    <title>Cottages Management - Admin Dashboard</title>
    <!-- POP UP ICON -->
    <link rel="apple-touch-icon" sizes="180x180" href="/veripool/assets/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/veripool/assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/veripool/assets/favicon/favicon-16x16.png">
    <link rel="manifest" href="/veripool/assets/favicon/site.webmanifest">

   <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .cottage-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .cottage-stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
        }
        
        .cottage-stat-card .number {
            font-size: 2rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .cottage-stat-card .label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .cottage-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .cottage-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
            transition: transform 0.3s;
        }
        
        .cottage-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(22,121,171,0.1);
        }
        
        .cottage-card.available { border-left-color: #28a745; }
        .cottage-card.occupied { border-left-color: #dc3545; }
        .cottage-card.unavailable { border-left-color: #ffc107; }
        
        .cottage-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .cottage-name {
            font-size: 1.5rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .cottage-type-badge {
            background: #FFCBCB;
            color: #102C57;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
        }
        
        .cottage-price {
            font-size: 1.3rem;
            font-weight: bold;
            color: #1679AB;
            margin: 10px 0;
        }
        
        .cottage-detail {
            margin: 8px 0;
            color: #666;
        }
        
        .cottage-detail i {
            width: 20px;
            color: #1679AB;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-available { background: #d4edda; color: #155724; }
        .status-occupied { background: #f8d7da; color: #721c24; }
        .status-unavailable { background: #fff3cd; color: #856404; }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #FFCBCB;
        }
        
        .modal-header h3 {
            color: #102C57;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .modal-close:hover {
            color: #dc3545;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #102C57;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
        }
        
        .form-control:focus {
            border-color: #1679AB;
            outline: none;
        }
        
        .btn-submit {
            background: #1679AB;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            width: 100%;
        }
        
        .btn-submit:hover {
            background: #102C57;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            margin-top: 15px;
        }
        
        .btn-icon {
            padding: 5px 15px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        
        .btn-edit { background: #17a2b8; color: white; }
        .btn-delete { background: #dc3545; color: white; }
        
        .amenities-list {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            font-size: 0.9rem;
            margin: 10px 0;
        }
        
        @media (max-width: 768px) {
            .cottage-stats {
                grid-template-columns: 1fr 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
     <?php include '../includes/sidebar.php'; ?>
    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i> Menu
    </button>
    

    
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <h1>
                <i class="fas fa-home"></i>
                Cottages Management
            </h1>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Cottage Statistics -->
        <div class="cottage-stats">
            <div class="cottage-stat-card">
                <div class="number"><?php echo $total_cottages; ?></div>
                <div class="label">Total Cottages</div>
            </div>
            <div class="cottage-stat-card">
                <div class="number"><?php echo $available_cottages; ?></div>
                <div class="label">Available</div>
            </div>
            <div class="cottage-stat-card">
                <div class="number"><?php echo $occupied_cottages; ?></div>
                <div class="label">Occupied</div>
            </div>
            <div class="cottage-stat-card">
                <div class="number"><?php echo $maintenance_cottages; ?></div>
                <div class="label">Maintenance</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="#" onclick="openAddCottageModal()" class="quick-action">
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
                
                <div class="cottage-price">₱<?php echo number_format($cottage['price'], 2); ?>/day</div>
                
                <div class="cottage-detail">
                    <i class="fas fa-users"></i> Capacity: <?php echo $cottage['capacity']; ?> guests
                </div>
                <div class="cottage-detail">
                    <i class="fas fa-ruler-combined"></i> Size: <?php echo $cottage['size_sqm']; ?> m²
                </div>
                
                <div style="margin: 10px 0;">
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
                    <a href="cottages.php" class="btn btn-outline">Cancel</a>
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
            const modal = document.getElementById('addCottageModal');
            if (event.target == modal) {
                closeAddCottageModal();
            }
        }
    </script>
</body>
</html>