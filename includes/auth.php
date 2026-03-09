<?php
/**
 * Veripool Reservation System - Authentication Class
 * Fixed version with improved logout handling
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/functions.php';

class Auth {
    private $db;
    private $debug = true;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * User login
     */
    public function login($username, $password) {
        try {
            if ($this->debug) {
                error_log("Login attempt for username: " . $username);
            }
            
            // Get user by username or email
            $user = $this->db->getRow(
                "SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'",
                [$username, $username]
            );
            
            if (!$user) {
                if ($this->debug) {
                    error_log("User not found: " . $username);
                }
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Verify password
            if (!password_verify($password, $user['password'])) {
                if ($this->debug) {
                    error_log("Password verification failed for user: " . $username);
                }
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_username'] = $user['username'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            
            // Update last login
            $this->db->update('users', 
                ['last_login' => date('Y-m-d H:i:s')], 
                'id = ?', 
                [$user['id']]
            );
            
            // Log audit trail
            $this->logAudit($user['id'], 'LOGIN', 'users', $user['id'], ['action' => 'login']);
            
            if ($this->debug) {
                error_log("Login successful for user: " . $username . " with role: " . $user['role']);
                error_log("Session data: " . print_r($_SESSION, true));
            }
            
            return ['success' => true, 'user' => $user];
            
        } catch (Exception $e) {
            if ($this->debug) {
                error_log("Login error: " . $e->getMessage());
            }
            return ['success' => false, 'message' => 'An error occurred during login'];
        }
    }
    
    /**
     * User registration
     */
    public function register($data) {
        try {
            // Check if username exists
            $exists = $this->db->getValue(
                "SELECT COUNT(*) FROM users WHERE username = ?",
                [$data['username']]
            );
            
            if ($exists > 0) {
                return ['success' => false, 'message' => 'Username already exists'];
            }
            
            // Check if email exists
            $exists = $this->db->getValue(
                "SELECT COUNT(*) FROM users WHERE email = ?",
                [$data['email']]
            );
            
            if ($exists > 0) {
                return ['success' => false, 'message' => 'Email already exists'];
            }
            
            // Hash password
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            $data['role'] = 'guest';
            $data['status'] = 'active';
            
            // Insert user
            $user_id = $this->db->insert('users', $data);
            
            if ($user_id) {
                // Log audit trail
                $this->logAudit($user_id, 'REGISTER', 'users', $user_id, $data);
                
                return ['success' => true, 'user_id' => $user_id];
            }
            
            return ['success' => false, 'message' => 'Registration failed'];
            
        } catch (Exception $e) {
            if ($this->debug) {
                error_log("Registration error: " . $e->getMessage());
            }
            return ['success' => false, 'message' => 'An error occurred during registration'];
        }
    }
    
    /**
     * Logout - FIXED VERSION
     */
    public function logout() {
        try {
            if ($this->debug) {
                error_log("Auth::logout() called");
            }
            
            // Log audit trail if user is logged in
            if (isset($_SESSION['user_id'])) {
                $this->logAudit($_SESSION['user_id'], 'LOGOUT', 'users', $_SESSION['user_id']);
                
                if ($this->debug) {
                    error_log("Logged logout for user_id: " . $_SESSION['user_id']);
                }
            }
            
            // Clear session data
            $_SESSION = array();
            
            // Destroy session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            // Destroy session
            session_destroy();
            
            if ($this->debug) {
                error_log("Session destroyed successfully");
            }
            
            return true;
            
        } catch (Exception $e) {
            if ($this->debug) {
                error_log("Logout error: " . $e->getMessage());
            }
            
            // Even if there's an error, try to destroy session
            @session_destroy();
            return false;
        }
    }
    
    /**
     * Log audit trail
     */
    private function logAudit($user_id, $action, $table_name = null, $record_id = null, $new_data = null, $old_data = null) {
        try {
            $data = [
                'user_id' => $user_id,
                'action' => $action,
                'table_name' => $table_name,
                'record_id' => $record_id,
                'new_data' => $new_data ? json_encode($new_data) : null,
                'old_data' => $old_data ? json_encode($old_data) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            return $this->db->insert('audit_trails', $data);
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']);
    }
    
    /**
     * Get current user role
     */
    public function getCurrentRole() {
        return $_SESSION['user_role'] ?? null;
    }
    
    /**
     * Check if user has permission
     */
    public function hasPermission($required_role) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $role_hierarchy = [
            'guest' => 1,
            'staff' => 2,
            'admin' => 3,
            'super_admin' => 4
        ];
        
        $user_level = $role_hierarchy[$_SESSION['user_role']] ?? 0;
        $required_level = $role_hierarchy[$required_role] ?? 0;
        
        return $user_level >= $required_level;
    }
    
    /**
     * Require authentication
     */
    public function requireAuth() {
        if (!$this->isLoggedIn()) {
            header("Location: " . APP_URL . "/login.php");
            exit;
        }
    }
    
    /**
     * Require specific role
     */
    public function requireRole($role) {
        $this->requireAuth();
        
        if (!$this->hasPermission($role)) {
            // Redirect based on current role
            if ($_SESSION['user_role'] === 'guest') {
                header("Location: " . APP_URL . "/guest/dashboard.php");
            } elseif ($_SESSION['user_role'] === 'staff') {
                header("Location: " . APP_URL . "/staff/index.php");
            } else {
                header("Location: " . APP_URL . "/admin/dashboard.php");
            }
            exit;
        }
    }
}
?>