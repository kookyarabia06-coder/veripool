<?php
/**
 * Veripool Reservation System - Admin Rooms Page
 * Manage all rooms and room types
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

// Get all room types
$room_types = $db->getRows("SELECT * FROM room_types ORDER BY id");

// Get all rooms with details
$rooms = $db->getRows("
    SELECT r.*, rt.name as room_type_name, rt.base_price, rt.max_occupancy,
           (SELECT COUNT(*) FROM reservations 
            WHERE room_id = r.id AND status IN ('confirmed', 'checked_in')) as is_occupied
    FROM rooms r
    JOIN room_types rt ON r.room_type_id = rt.id
    ORDER BY r.room_number
");

// Get statistics
$total_rooms = count($rooms);
$available_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'available'");
$occupied_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'occupied'");
$maintenance_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'maintenance'");
$reserved_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'reserved'");

// Handle actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Add new room type
        if ($_POST['action'] === 'add_room_type') {
            $name = sanitize($_POST['name']);
            $description = sanitize($_POST['description']);
            $max_occupancy = (int)$_POST['max_occupancy'];
            $base_price = (float)$_POST['base_price'];
            $amenities = sanitize($_POST['amenities']);
            
            $type_data = [
                'name' => $name,
                'description' => $description,
                'max_occupancy' => $max_occupancy,
                'base_price' => $base_price,
                'amenities' => $amenities,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $type_id = $db->insert('room_types', $type_data);
            
            if ($type_id) {
                $message = "Room type added successfully";
                $message_type = 'success';
            } else {
                $message = "Failed to add room type";
                $message_type = 'error';
            }
        }
        
        // Add new room
        if ($_POST['action'] === 'add_room') {
            $room_number = sanitize($_POST['room_number']);
            $room_type_id = (int)$_POST['room_type_id'];
            $floor = (int)$_POST['floor'];
            $status = sanitize($_POST['status']);
            $notes = sanitize($_POST['notes']) ?: null;
            
            // Check if room number exists
            $exists = $db->getValue("SELECT COUNT(*) FROM rooms WHERE room_number = ?", [$room_number]);
            if ($exists > 0) {
                $message = "Room number already exists";
                $message_type = 'error';
            } else {
                $room_data = [
                    'room_number' => $room_number,
                    'room_type_id' => $room_type_id,
                    'floor' => $floor,
                    'status' => $status,
                    'notes' => $notes,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $room_id = $db->insert('rooms', $room_data);
                
                if ($room_id) {
                    $message = "Room added successfully";
                    $message_type = 'success';
                } else {
                    $message = "Failed to add room";
                    $message_type = 'error';
                }
            }
        }
        
        // Update room status
        if ($_POST['action'] === 'update_room' && isset($_POST['room_id'])) {
            $room_id = (int)$_POST['room_id'];
            $status = sanitize($_POST['status']);
            $notes = sanitize($_POST['notes']) ?: null;
            
            $db->update('rooms', 
                ['status' => $status, 'notes' => $notes], 
                'id = :id', 
                ['id' => $room_id]
            );
            
            $message = "Room updated successfully";
            $message_type = 'success';
        }
        
        // Delete room
        if ($_POST['action'] === 'delete_room' && isset($_POST['room_id'])) {
            $room_id = (int)$_POST['room_id'];
            
            // Check if room has reservations
            $has_reservations = $db->getValue("SELECT COUNT(*) FROM reservations WHERE room_id = ?", [$room_id]);
            if ($has_reservations > 0) {
                $message = "Cannot delete room with existing reservations";
                $message_type = 'error';
            } else {
                $db->delete('rooms', 'id = :id', ['id' => $room_id]);
                $message = "Room deleted successfully";
                $message_type = 'success';
            }
        }
        
        // Delete room type
        if ($_POST['action'] === 'delete_room_type' && isset($_POST['type_id'])) {
            $type_id = (int)$_POST['type_id'];
            
            // Check if room type has rooms
            $has_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE room_type_id = ?", [$type_id]);
            if ($has_rooms > 0) {
                $message = "Cannot delete room type with existing rooms. Please reassign or delete the rooms first.";
                $message_type = 'error';
            } else {
                $db->delete('room_types', 'id = :id', ['id' => $type_id]);
                $message = "Room type deleted successfully";
                $message_type = 'success';
            }
        }
        
        // Refresh data
        $rooms = $db->getRows("
            SELECT r.*, rt.name as room_type_name, rt.base_price, rt.max_occupancy,
                   (SELECT COUNT(*) FROM reservations 
                    WHERE room_id = r.id AND status IN ('confirmed', 'checked_in')) as is_occupied
            FROM rooms r
            JOIN room_types rt ON r.room_type_id = rt.id
            ORDER BY r.room_number
        ");
        
        $room_types = $db->getRows("SELECT * FROM room_types ORDER BY id");
        
        $available_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'available'");
        $occupied_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'occupied'");
        $maintenance_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'maintenance'");
        $reserved_rooms = $db->getValue("SELECT COUNT(*) FROM rooms WHERE status = 'reserved'");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rooms Management - Admin Dashboard</title>
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
        .room-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .room-stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
        }
        
        .room-stat-card .number {
            font-size: 2rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .room-stat-card .label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .room-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .room-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #1679AB;
            transition: transform 0.3s;
        }
        
        .room-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(22,121,171,0.1);
        }
        
        .room-card.available { border-left-color: #28a745; }
        .room-card.occupied { border-left-color: #dc3545; }
        .room-card.maintenance { border-left-color: #ffc107; }
        .room-card.reserved { border-left-color: #17a2b8; }
        
        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .room-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #102C57;
        }
        
        .room-type-badge {
            background: #FFCBCB;
            color: #102C57;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
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
        .status-maintenance { background: #fff3cd; color: #856404; }
        .status-reserved { background: #cce5ff; color: #004085; }
        
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
            max-width: 500px;
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
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #FFCBCB;
            padding-bottom: 10px;
        }
        
        .tab {
            padding: 8px 20px;
            cursor: pointer;
            border-radius: 20px;
            background: white;
            color: #102C57;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .tab:hover {
            background: #FFCBCB;
        }
        
        .tab.active {
            background: #1679AB;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            margin-top: 15px;
        }
        
        .btn-icon {
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.3s;
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
            filter: brightness(0.95);
        }
        
        .btn-edit { background: #17a2b8; color: white; }
        .btn-delete { background: #dc3545; color: white; }
        
        .room-detail {
            margin: 10px 0;
            color: #666;
        }
        
        .room-detail i {
            width: 20px;
            color: #1679AB;
        }
        
        .delete-warning {
            background: #f8d7da;
            color: #721c24;
            padding: 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin: 10px 0;
            border-left: 4px solid #dc3545;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
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
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .quick-action {
            background: white;
            padding: 10px 20px;
            border-radius: 20px;
            text-decoration: none;
            color: #102C57;
            border: 1px solid #e0e0e0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .quick-action:hover {
            border-color: #1679AB;
            background: #f0f8ff;
            transform: translateY(-2px);
        }
        
        .quick-action i {
            color: #1679AB;
        }
        
        @media (max-width: 768px) {
            .room-stats {
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
                <i class="fas fa-bed"></i>
                Rooms Management
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
        
        <!-- Room Statistics -->
        <div class="room-stats">
            <div class="room-stat-card">
                <div class="number"><?php echo $total_rooms; ?></div>
                <div class="label">Total Rooms</div>
            </div>
            <div class="room-stat-card">
                <div class="number"><?php echo $available_rooms; ?></div>
                <div class="label">Available</div>
            </div>
            <div class="room-stat-card">
                <div class="number"><?php echo $occupied_rooms; ?></div>
                <div class="label">Occupied</div>
            </div>
            <div class="room-stat-card">
                <div class="number"><?php echo $maintenance_rooms; ?></div>
                <div class="label">Maintenance</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="#" onclick="openAddRoomModal()" class="quick-action">
                <i class="fas fa-plus-circle"></i>
                <span>Add New Room</span>
            </a>
            <a href="#" onclick="openAddRoomTypeModal()" class="quick-action">
                <i class="fas fa-tag"></i>
                <span>Add Room Type</span>
            </a>
            <a href="#" onclick="showTab('types')" class="quick-action">
                <i class="fas fa-list"></i>
                <span>View Room Types</span>
            </a>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('rooms')">Rooms</div>
            <div class="tab" onclick="showTab('types')">Room Types</div>
        </div>
        
        <!-- Rooms Tab -->
        <div id="rooms-tab" class="tab-content active">
            <div class="room-grid">
                <?php foreach ($rooms as $room): ?>
                <div class="room-card <?php echo $room['status']; ?>">
                    <div class="room-header">
                        <span class="room-number">Room <?php echo $room['room_number']; ?></span>
                        <span class="room-type-badge"><?php echo $room['room_type_name']; ?></span>
                    </div>
                    
                    <div class="room-detail">
                        <i class="fas fa-layer-group"></i> Floor <?php echo $room['floor']; ?>
                    </div>
                    <div class="room-detail">
                        <i class="fas fa-users"></i> Max <?php echo $room['max_occupancy']; ?> guests
                    </div>
                    <div class="room-detail">
                        <i class="fas fa-tag"></i> ₱<?php echo number_format($room['base_price'], 2); ?>/night
                    </div>
                    
                    <div style="margin: 10px 0;">
                        <span class="status-badge status-<?php echo $room['status']; ?>">
                            <?php echo ucfirst($room['status']); ?>
                        </span>
                        <?php if ($room['is_occupied'] > 0): ?>
                            <span class="status-badge status-occupied" style="margin-left: 5px;">
                                <i class="fas fa-user"></i> Occupied
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($room['notes']): ?>
                        <div style="font-size: 0.85rem; color: #666; background: #f8f9fa; padding: 8px; border-radius: 5px; margin: 10px 0;">
                            <i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars($room['notes']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="action-buttons">
                        <button onclick="openEditRoomModal(<?php echo $room['id']; ?>, '<?php echo $room['room_number']; ?>', '<?php echo $room['status']; ?>', '<?php echo addslashes($room['notes']); ?>')" class="btn-icon btn-edit">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <?php if ($room['is_occupied'] == 0): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this room? This action cannot be undone.');">
                            <input type="hidden" name="action" value="delete_room">
                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                            <button type="submit" class="btn-icon btn-delete">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                        <?php else: ?>
                        <button class="btn-icon btn-delete" style="opacity: 0.5; cursor: not-allowed;" title="Cannot delete room with active reservations" disabled>
                            <i class="fas fa-trash"></i> Delete
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Room Types Tab -->
        <div id="types-tab" class="tab-content">
            <div class="room-grid">
                <?php foreach ($room_types as $type): 
                    $room_count = $db->getValue("SELECT COUNT(*) FROM rooms WHERE room_type_id = ?", [$type['id']]);
                ?>
                <div class="room-card">
                    <div class="room-header">
                        <span class="room-number"><?php echo htmlspecialchars($type['name']); ?></span>
                    </div>
                    
                    <div class="room-detail">
                        <i class="fas fa-users"></i> Max <?php echo $type['max_occupancy']; ?> guests
                    </div>
                    <div class="room-detail">
                        <i class="fas fa-tag"></i> ₱<?php echo number_format($type['base_price'], 2); ?>/night
                    </div>
                    
                    <div style="margin: 10px 0; font-size: 0.9rem;">
                        <strong>Description:</strong> <?php echo htmlspecialchars($type['description']); ?>
                    </div>
                    
                    <?php if ($type['amenities']): ?>
                        <div style="margin: 10px 0; font-size: 0.9rem;">
                            <strong>Amenities:</strong> <?php echo htmlspecialchars($type['amenities']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 10px; color: #1679AB;">
                        <i class="fas fa-door-open"></i> <?php echo $room_count; ?> rooms
                    </div>
                    
                    <div class="action-buttons">
                        <?php if ($room_count == 0): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirmDeleteType('<?php echo htmlspecialchars($type['name']); ?>')">
                            <input type="hidden" name="action" value="delete_room_type">
                            <input type="hidden" name="type_id" value="<?php echo $type['id']; ?>">
                            <button type="submit" class="btn-icon btn-delete">
                                <i class="fas fa-trash"></i> Delete Type
                            </button>
                        </form>
                        <?php else: ?>
                        <button class="btn-icon btn-delete" style="opacity: 0.5; cursor: not-allowed;" title="Cannot delete room type with existing rooms" disabled>
                            <i class="fas fa-trash"></i> Delete Type
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($room_count > 0): ?>
                    <div class="delete-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <?php echo $room_count; ?> room(s) use this type. Delete rooms first.
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Add Room Modal -->
    <div class="modal" id="addRoomModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Room</h3>
                <button class="modal-close" onclick="closeAddRoomModal()">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_room">
                
                <div class="form-group">
                    <label for="room_number">Room Number *</label>
                    <input type="text" name="room_number" id="room_number" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="room_type_id">Room Type *</label>
                    <select name="room_type_id" id="room_type_id" class="form-control" required>
                        <option value="">Select Room Type</option>
                        <?php foreach ($room_types as $type): ?>
                        <option value="<?php echo $type['id']; ?>">
                            <?php echo htmlspecialchars($type['name']); ?> - ₱<?php echo number_format($type['base_price'], 2); ?>/night
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="floor">Floor *</label>
                        <input type="number" name="floor" id="floor" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status *</label>
                        <select name="status" id="status" class="form-control" required>
                            <option value="available">Available</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="reserved">Reserved</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Add Room
                </button>
            </form>
        </div>
    </div>
    
    <!-- Add Room Type Modal -->
    <div class="modal" id="addRoomTypeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-tag"></i> Add Room Type</h3>
                <button class="modal-close" onclick="closeAddRoomTypeModal()">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_room_type">
                
                <div class="form-group">
                    <label for="type_name">Room Type Name *</label>
                    <input type="text" name="name" id="type_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="type_description">Description *</label>
                    <textarea name="description" id="type_description" class="form-control" rows="3" required></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="max_occupancy">Max Occupancy *</label>
                        <input type="number" name="max_occupancy" id="max_occupancy" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="base_price">Base Price (₱) *</label>
                        <input type="number" name="base_price" id="base_price" class="form-control" step="0.01" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="amenities">Amenities</label>
                    <textarea name="amenities" id="amenities" class="form-control" rows="3" placeholder="e.g., Air Conditioning, Mini Fridge, WiFi"></textarea>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Add Room Type
                </button>
            </form>
        </div>
    </div>
    
    <!-- Edit Room Modal -->
    <div class="modal" id="editRoomModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Room</h3>
                <button class="modal-close" onclick="closeEditRoomModal()">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_room">
                <input type="hidden" name="room_id" id="edit_room_id">
                
                <div class="form-group">
                    <label for="edit_room_number">Room Number</label>
                    <input type="text" id="edit_room_number" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="edit_status">Status *</label>
                    <select name="status" id="edit_status" class="form-control" required>
                        <option value="available">Available</option>
                        <option value="occupied">Occupied</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="reserved">Reserved</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_notes">Notes</label>
                    <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Update Room
                </button>
            </form>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }
        
        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tab === 'rooms') {
                document.querySelector('.tab').classList.add('active');
                document.getElementById('rooms-tab').classList.add('active');
            } else {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('types-tab').classList.add('active');
            }
        }
        
        function openAddRoomModal() {
            document.getElementById('addRoomModal').classList.add('active');
        }
        
        function closeAddRoomModal() {
            document.getElementById('addRoomModal').classList.remove('active');
        }
        
        function openAddRoomTypeModal() {
            document.getElementById('addRoomTypeModal').classList.add('active');
        }
        
        function closeAddRoomTypeModal() {
            document.getElementById('addRoomTypeModal').classList.remove('active');
        }
        
        function openEditRoomModal(id, number, status, notes) {
            document.getElementById('edit_room_id').value = id;
            document.getElementById('edit_room_number').value = number;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_notes').value = notes;
            document.getElementById('editRoomModal').classList.add('active');
        }
        
        function closeEditRoomModal() {
            document.getElementById('editRoomModal').classList.remove('active');
        }
        
        function confirmDeleteType(typeName) {
            return confirm(`Are you sure you want to delete the room type "${typeName}"?\n\nThis action cannot be undone.`);
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const addRoomModal = document.getElementById('addRoomModal');
            const addTypeModal = document.getElementById('addRoomTypeModal');
            const editModal = document.getElementById('editRoomModal');
            
            if (event.target == addRoomModal) closeAddRoomModal();
            if (event.target == addTypeModal) closeAddRoomTypeModal();
            if (event.target == editModal) closeEditRoomModal();
        }
    </script>
</body>
</html>