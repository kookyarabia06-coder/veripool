<?php
/**
 * Entry Pass Manager for Veripool Reservation System
 * Handles OTP generation for entry passes and reminders
 */

class EntryPassManager {
    private $db;
    private $mail_enabled = TRUE;
    
    public function __construct($db) {
        $this->db = $db;
        // Try to initialize mail, but don't fail if PHPMailer is missing
        $this->initMailer();
    }
    
    /**
     * Initialize mailer - try PHPMailer first, fallback to basic mail()
     */
    private function initMailer() {
        // Try to include PHPMailer if it exists
        $possible_paths = [
            __DIR__ . '/PHPMailer/PHPMailer.php',
            __DIR__ . '/../PHPMailer/PHPMailer.php',
            __DIR__ . '/../../PHPMailer/PHPMailer.php',
            __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
            __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php',
            'C:/xampp/htdocs/veripool/vendor/phpmailer/phpmailer/src/PHPMailer.php'
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                require_once dirname($path) . '/SMTP.php';
                require_once dirname($path) . '/Exception.php';
                $this->mail_enabled = true;
                error_log("PHPMailer found at: " . $path);
                break;
            }
        }
        
        if (!$this->mail_enabled) {
            error_log("PHPMailer not found. Using basic mail() function as fallback.");
        }
    }
    
    /**
     * Generate a random OTP
     */
    private function generateOTP($length = 6) {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= random_int(0, 9);
        }
        return $otp;
    }
    
    /**
     * Send email using available method
     */
    private function sendEmail($to, $subject, $html_message, $text_message = '') {
        if (empty($text_message)) {
            $text_message = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_message));
        }
        
        // For XAMPP development, log the email
        error_log("========== EMAIL NOTIFICATION ==========");
        error_log("To: $to");
        error_log("Subject: $subject");
        error_log("========================================");
        
        // Try to use PHPMailer if available
        if ($this->mail_enabled && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                
                // Server settings
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'veripoolresort@gmail.com'; // Update this
                $mail->Password   = 'vkcb pinp tnnc xrft';    // Update this
                $mail->SMTPSecure = 'tls';
                $mail->Port       = 587;
                
                // Recipients
                $mail->setFrom('noreply@veripool.com', 'Veripool Reservation System');
                $mail->addAddress($to);
                
                // Content
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $html_message;
                $mail->AltBody = $text_message;
                
                $mail->send();
                error_log("Email sent successfully to: $to");
                return true;
            } catch (Exception $e) {
                error_log("PHPMailer Error: " . $e->getMessage());
                // Fallback to mail() on error
                return $this->sendMailFallback($to, $subject, $html_message, $text_message);
            }
        } else {
            // Use basic mail() as fallback
            return $this->sendMailFallback($to, $subject, $html_message, $text_message);
        }
    }
    
    /**
     * Fallback mail function using PHP's mail()
     */
    private function sendMailFallback($to, $subject, $html_message, $text_message) {
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= 'From: Veripool Reservation System <noreply@veripool.com>' . "\r\n";
        
        $success = mail($to, $subject, $html_message, $headers);
        
        if (!$success) {
            error_log("Failed to send email to: " . $to);
            // Log the email for debugging
            error_log("Email would have been: Subject: $subject, To: $to");
        }
        
        return $success;
    }
    
    /**
     * ===== PUBLIC EMAIL WRAPPER =====
     * Public wrapper for sending emails
     */
    public function sendEmailPublic($to, $subject, $html_message, $text_message = '') {
        return $this->sendEmail($to, $subject, $html_message, $text_message);
    }
    
    /**
     * Generate Entry Pass OTP when payment is confirmed
     */
    public function generateEntryPass($reservation_id, $admin_id) {
        // Get reservation details
        $reservation = $this->db->getRow("
            SELECT r.*, u.email, u.full_name, u.phone
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?",
            [$reservation_id]
        );
        
        if (!$reservation) {
            return ['success' => false, 'message' => 'Reservation not found'];
        }
        
        // Get facility name based on reservation type
        $facility_name = 'Unknown';
        $facility_type = 'unknown';
        
        if (!empty($reservation['room_id'])) {
            $room = $this->db->getRow("SELECT room_number FROM rooms WHERE id = ?", [$reservation['room_id']]);
            $facility_name = $room ? $room['room_number'] : 'Room #' . $reservation['room_id'];
            $facility_type = 'room';
        } elseif (!empty($reservation['cottage_id'])) {
            $cottage = $this->db->getRow("SELECT name FROM cottages WHERE id = ?", [$reservation['cottage_id']]);
            $facility_name = $cottage ? $cottage['name'] : 'Cottage #' . $reservation['cottage_id'];
            $facility_type = 'cottage';
        } elseif (!empty($reservation['pool_id'])) {
            $pool = $this->db->getRow("SELECT name FROM pools WHERE id = ?", [$reservation['pool_id']]);
            $facility_name = $pool ? $pool['name'] : 'Pool #' . $reservation['pool_id'];
            $facility_type = 'pool';
        }
        
        $reservation['facility_name'] = $facility_name;
        $reservation['facility_type'] = $facility_type;
        
        // Check if entry pass already exists
        $existing = $this->db->getRow(
            "SELECT * FROM entry_passes WHERE reservation_id = ? AND status = 'active'",
            [$reservation_id]
        );
        
        if ($existing) {
            return ['success' => false, 'message' => 'Entry pass already exists for this reservation'];
        }
        
        // Generate OTP
        $otp = $this->generateOTP(6);
        
        // Set validity (from check-in date to check-out date)
        $valid_from = $reservation['check_in_date'] . ' 14:00:00'; // 2 PM check-in
        $valid_until = $reservation['check_out_date'] . ' 12:00:00'; // 12 PM check-out
        
        // Save entry pass with flexibility fields
        $result = $this->db->query(
            "INSERT INTO entry_passes 
             (reservation_id, user_id, otp_code, valid_from, valid_until, generated_by, is_flexible, original_check_in, original_check_out, date_adjustments) 
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0)",
            [
                $reservation_id,
                $reservation['user_id'],
                $otp,
                $valid_from,
                $valid_until,
                $admin_id,
                $reservation['check_in_date'],
                $reservation['check_out_date']
            ]
        );
        
        if (!$result) {
            return ['success' => false, 'message' => 'Failed to save entry pass'];
        }
        
        $entry_pass_id = $this->db->lastInsertId();
        
        // Update reservation
        $this->db->query(
            "UPDATE reservations SET entry_pass_generated = 1 WHERE id = ?",
            [$reservation_id]
        );
        
        // Schedule reminders
        $this->scheduleReminders($reservation_id, $reservation['user_id'], $reservation['check_in_date']);
        
        // Send entry pass to guest
        $email_sent = $this->sendEntryPassEmail($reservation, $otp, $entry_pass_id);
        
        // Send SMS if phone number exists
        if (!empty($reservation['phone'])) {
            $this->sendEntryPassSMS($reservation['phone'], $otp, $reservation);
        }
        
        return [
            'success' => true,
            'message' => 'Entry pass generated successfully' . ($email_sent ? '' : ' (Email delivery failed)'),
            'otp' => $otp,
            'entry_pass_id' => $entry_pass_id
        ];
    }
    
    /**
     * ===== STAFF CHECK-IN WITH FLEXIBILITY =====
     * Staff check-in with flexible date handling
     */
    public function staffCheckInWithFlexibility($otp, $staff_id, $actual_date = null) {
        if (!$actual_date) {
            $actual_date = date('Y-m-d');
        }
        
        // Verify with flexibility
        $verification = $this->verifyEntryPassWithFlexibility($otp, $actual_date);
        
        if (!$verification['success']) {
            // Check if it requires action (like date adjustment)
            if (isset($verification['requires_action']) && $verification['requires_action']) {
                return [
                    'success' => false,
                    'requires_action' => true,
                    'action_type' => $verification['action_type'],
                    'message' => $verification['message'],
                    'pass_data' => $verification['pass'] ?? null
                ];
            }
            return $verification;
        }
        
        $pass = $verification['data'];
        
        // Create check_in_logs table if it doesn't exist
        $this->createCheckInLogsTable();
        
        // Log the check-in
        $this->logCheckIn($pass['id'], $pass['reservation_id'], $pass['user_id'], $staff_id, $actual_date, $verification['type']);
        
        // If early arrival, mark as special case but still allow
        if ($verification['type'] == 'early') {
            $this->db->update('reservations', 
                ['early_arrival' => 1, 'actual_check_in' => $actual_date],
                'id = :id',
                ['id' => $pass['reservation_id']]
            );
        }
        
        // Mark entry pass as used
        $this->useEntryPass($pass['id']);
        
        // Update reservation status
        $this->db->update('reservations',
            ['status' => 'checked_in', 'actual_check_in' => $actual_date],
            'id = :id',
            ['id' => $pass['reservation_id']]
        );
        
        // Update room status if applicable
        if (!empty($pass['room_id'])) {
            $this->db->update('rooms',
                ['status' => 'occupied'],
                'id = :id',
                ['id' => $pass['room_id']]
            );
        }
        
        return [
            'success' => true,
            'message' => 'Check-in successful',
            'guest_name' => $pass['full_name'],
            'check_in_type' => $verification['type'],
            'original_dates' => [
                'check_in' => $pass['original_check_in'],
                'check_out' => $pass['original_check_out']
            ]
        ];
    }
    
    /**
     * ===== VERIFY ENTRY PASS WITH FLEXIBILITY =====
     * Verify entry pass with flexibility for date variations
     */
    private function verifyEntryPassWithFlexibility($otp, $current_date = null) {
        if (!$current_date) {
            $current_date = date('Y-m-d');
        }
        
        $pass = $this->db->getRow("
            SELECT ep.*, r.check_in_date as original_check_in, r.check_out_date as original_check_out,
                   r.id as reservation_id, r.status as reservation_status,
                   u.full_name, u.email, u.phone,
                   r.room_id, r.cottage_id, r.adults, r.children, r.guests
            FROM entry_passes ep
            JOIN reservations r ON ep.reservation_id = r.id
            JOIN users u ON ep.user_id = u.id
            WHERE ep.otp_code = ? AND ep.status = 'active'
        ", [$otp]);
        
        if (!$pass) {
            return [
                'success' => false,
                'message' => 'Invalid or inactive OTP'
            ];
        }
        
        // Check if reservation is still valid
        if ($pass['reservation_status'] == 'cancelled') {
            return [
                'success' => false,
                'message' => 'This reservation has been cancelled'
            ];
        }
        
        // Get original dates
        $original_check_in = new DateTime($pass['original_check_in']);
        $original_check_out = new DateTime($pass['original_check_out']);
        $current = new DateTime($current_date);
        
        // Case 1: Guest arrives on original date
        if ($current_date >= $pass['original_check_in'] && $current_date < $pass['original_check_out']) {
            return [
                'success' => true,
                'message' => 'Valid entry pass for original dates',
                'data' => $pass,
                'type' => 'original'
            ];
        }
        
        // Case 2: Guest arrives before check-in
        if ($current_date < $pass['original_check_in']) {
            $days_early = $original_check_in->diff($current)->days;
            
            // Allow 1 day early
            if ($days_early <= 1) {
                // Log this as an exception
                $this->logEarlyArrival($pass['id'], $current_date);
                
                return [
                    'success' => true,
                    'message' => 'Early arrival accepted',
                    'data' => $pass,
                    'type' => 'early',
                    'days_early' => $days_early
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "You are {$days_early} days early. Please contact the resort for assistance.",
                    'requires_action' => true,
                    'action_type' => 'early_arrival',
                    'pass' => $pass
                ];
            }
        }
        
        // Case 3: Guest arrives after check-out
        if ($current_date >= $pass['original_check_out']) {
            $days_late = $current->diff($original_check_out)->days;
            
            // Check if the pass has been adjusted before
            if (($pass['date_adjustments'] ?? 0) < 2) { // Allow up to 2 adjustments
                return [
                    'success' => false,
                    'message' => "Your reservation has expired. Would you like to request a date adjustment?",
                    'requires_action' => true,
                    'action_type' => 'date_adjustment',
                    'pass' => $pass,
                    'days_late' => $days_late
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "This OTP has expired and cannot be adjusted further. Please make a new reservation."
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'Invalid entry pass for current date'
        ];
    }
    
    /**
     * ===== APPROVE DATE ADJUSTMENT =====
     * Approve date adjustment request (for staff/admin)
     */
    public function approveDateAdjustment($request_id, $staff_id) {
        try {
            // Get request details
            $request = $this->db->getRow("
                SELECT dar.*, ep.otp_code, ep.id as entry_pass_id, ep.date_adjustments,
                       r.id as reservation_id, r.reservation_number,
                       u.email, u.full_name, u.id as user_id
                FROM date_adjustment_requests dar
                JOIN entry_passes ep ON dar.entry_pass_id = ep.id
                JOIN reservations r ON dar.reservation_id = r.id
                JOIN users u ON dar.user_id = u.id
                WHERE dar.id = ? AND dar.status = 'pending'
            ", [$request_id]);
            
            if (!$request) {
                return ['success' => false, 'message' => 'Adjustment request not found or already processed'];
            }
            
            // Begin transaction
            $this->db->beginTransaction();
            
            // Get current pass data for history
            $current_pass = $this->db->getRow("SELECT * FROM entry_passes WHERE id = ?", [$request['entry_pass_id']]);
            
            // Store original dates in history
            $adjustment_history = json_decode($current_pass['adjustment_history'] ?: '[]', true);
            $adjustment_history[] = [
                'date' => date('Y-m-d H:i:s'),
                'old_check_in' => $current_pass['valid_from'],
                'old_check_out' => $current_pass['valid_until'],
                'new_check_in' => $request['requested_check_in'] . ' 14:00:00',
                'new_check_out' => $request['requested_check_out'] . ' 12:00:00',
                'reason' => $request['reason'],
                'approved_by' => $staff_id
            ];
            
            // Update entry pass with new dates
            $this->db->update('entry_passes', 
                [
                    'valid_from' => $request['requested_check_in'] . ' 14:00:00',
                    'valid_until' => $request['requested_check_out'] . ' 12:00:00',
                    'date_adjustments' => ($current_pass['date_adjustments'] ?? 0) + 1,
                    'last_adjustment_date' => date('Y-m-d H:i:s'),
                    'adjustment_history' => json_encode($adjustment_history)
                ],
                'id = :id',
                ['id' => $request['entry_pass_id']]
            );
            
            // Update reservation dates
            $this->db->update('reservations',
                [
                    'check_in_date' => $request['requested_check_in'],
                    'check_out_date' => $request['requested_check_out']
                ],
                'id = :id',
                ['id' => $request['reservation_id']]
            );
            
            // Update request status
            $this->db->update('date_adjustment_requests',
                [
                    'status' => 'approved',
                    'reviewed_by' => $staff_id,
                    'reviewed_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                ['id' => $request_id]
            );
            
            $this->db->commit();
            
            // Send notification email to guest
            $this->sendAdjustmentEmail($request['email'], $request['full_name'], $request, 'approved');
            
            return [
                'success' => true,
                'message' => 'Date adjustment approved successfully',
                'new_otp' => $request['otp_code']
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error approving date adjustment: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to approve adjustment: ' . $e->getMessage()];
        }
    }
    
    /**
     * ===== REQUEST DATE ADJUSTMENT (FOR GUESTS) =====
     * Request date adjustment (for guests)
     */
    public function requestDateAdjustment($entry_pass_id, $user_id, $new_check_in, $new_check_out, $reason) {
        // Get entry pass details
        $pass = $this->db->getRow("SELECT * FROM entry_passes WHERE id = ?", [$entry_pass_id]);
        
        if (!$pass) {
            return ['success' => false, 'message' => 'Entry pass not found'];
        }
        
        // Check if user owns this pass
        if ($pass['user_id'] != $user_id) {
            return ['success' => false, 'message' => 'Unauthorized'];
        }
        
        // Check adjustment limit
        if ($pass['date_adjustments'] >= 2) {
            return ['success' => false, 'message' => 'Maximum date adjustments (2) reached for this reservation'];
        }
        
        // Check if there's already a pending request
        $pending = $this->db->getRow("
            SELECT * FROM date_adjustment_requests 
            WHERE entry_pass_id = ? AND status = 'pending'
        ", [$entry_pass_id]);
        
        if ($pending) {
            return ['success' => false, 'message' => 'You already have a pending adjustment request'];
        }
        
        // Create date_adjustment_requests table if it doesn't exist
        $this->createAdjustmentRequestsTable();
        
        // Create adjustment request
        $request_id = $this->db->insert('date_adjustment_requests', [
            'entry_pass_id' => $entry_pass_id,
            'reservation_id' => $pass['reservation_id'],
            'user_id' => $user_id,
            'requested_check_in' => $new_check_in,
            'requested_check_out' => $new_check_out,
            'reason' => $reason,
            'status' => 'pending'
        ]);
        
        if ($request_id) {
            // Notify admin
            $this->notifyAdminOfAdjustmentRequest($request_id);
            
            return [
                'success' => true,
                'message' => 'Date adjustment request submitted successfully. Please wait for admin approval.',
                'request_id' => $request_id
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to submit request'];
    }
    
    /**
     * ===== SEND ADJUSTMENT EMAIL =====
     * Send adjustment email to guest
     */
    private function sendAdjustmentEmail($email, $name, $request, $status) {
        $subject = $status == 'approved' 
            ? "Your Date Adjustment Request has been Approved" 
            : "Your Date Adjustment Request has been Rejected";
        
        $html_message = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { 
                    background: ' . ($status == 'approved' ? '#28a745' : '#dc3545') . '; 
                    color: white; 
                    padding: 20px; 
                    text-align: center;
                    border-radius: 10px 10px 0 0;
                }
                .content { padding: 30px; background: #f9f9f9; }
                .dates {
                    background: white;
                    padding: 20px;
                    border-radius: 10px;
                    margin: 20px 0;
                }
                .old-date { color: #dc3545; text-decoration: line-through; }
                .new-date { color: #28a745; font-weight: bold; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Date Adjustment ' . ucfirst($status) . '</h2>
                </div>
                <div class="content">
                    <p>Dear ' . htmlspecialchars($name) . ',</p>
                    <p>Your request to adjust your reservation dates has been ' . $status . '.</p>
                    
                    <div class="dates">
                        <h3>📅 Date Changes:</h3>
                        <p><strong>Original Dates:</strong><br>
                        <span class="old-date">' . date('M d, Y', strtotime($request['old_check_in'] ?? $request['created_at'])) . ' to ' . date('M d, Y', strtotime($request['old_check_out'] ?? $request['created_at'])) . '</span></p>
                        
                        <p><strong>Requested Dates:</strong><br>
                        <span class="new-date">' . date('M d, Y', strtotime($request['requested_check_in'])) . ' to ' . date('M d, Y', strtotime($request['requested_check_out'])) . '</span></p>
                    </div>
                    
                    ' . ($status == 'approved' ? '
                    <p><strong>Your existing OTP code remains the same and is now valid for the new dates.</strong></p>
                    <p>Please present the same OTP at the entrance on your new check-in date.</p>
                    ' : '
                    <p>If you have any questions, please contact our support team.</p>
                    ') . '
                    
                    <p>Thank you for choosing Veripool Resort!</p>
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' Veripool Resort. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>';
        
        $text_message = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_message));
        
        return $this->sendEmail($email, $subject, $html_message, $text_message);
    }
    
    /**
     * ===== NOTIFY ADMIN OF ADJUSTMENT REQUEST =====
     * Notify admin of adjustment request
     */
    private function notifyAdminOfAdjustmentRequest($request_id) {
        // Get admin emails
        $admins = $this->db->getRows("SELECT email, full_name FROM users WHERE role IN ('admin', 'super_admin')");
        
        $subject = "New Date Adjustment Request #$request_id";
        
        $html_message = "
        <html>
        <body>
            <h2>New Date Adjustment Request</h2>
            <p>A guest has submitted a new date adjustment request.</p>
            <p><strong>Request ID:</strong> $request_id</p>
            <p>Please log in to the admin panel to review this request.</p>
        </body>
        </html>";
        
        foreach ($admins as $admin) {
            $this->sendEmail($admin['email'], $subject, $html_message);
        }
    }
    
    /**
     * ===== LOG EARLY ARRIVAL =====
     * Log early arrival
     */
    private function logEarlyArrival($entry_pass_id, $arrival_date) {
        // Create log table if it doesn't exist
        $this->createEntryPassLogsTable();
        
        $this->db->query("
            INSERT INTO entry_pass_logs (entry_pass_id, action, details, created_at)
            VALUES (?, 'early_arrival', ?, NOW())
        ", [$entry_pass_id, "Guest arrived early on: " . $arrival_date]);
    }
    
    /**
     * ===== LOG CHECK-IN =====
     * Log check-in
     */
    private function logCheckIn($entry_pass_id, $reservation_id, $user_id, $staff_id, $actual_date, $check_in_type) {
        $this->db->query("
            INSERT INTO check_in_logs 
            (entry_pass_id, reservation_id, user_id, staff_id, actual_date, check_in_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ", [$entry_pass_id, $reservation_id, $user_id, $staff_id, $actual_date, $check_in_type]);
    }
    
    /**
     * ===== CREATE ENTRY PASS LOGS TABLE =====
     * Create entry_pass_logs table
     */
    private function createEntryPassLogsTable() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS entry_pass_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entry_pass_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (entry_pass_id)
            )
        ");
    }
    
    /**
     * ===== CREATE CHECK-IN LOGS TABLE =====
     * Create check_in_logs table
     */
    private function createCheckInLogsTable() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS check_in_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entry_pass_id INT NOT NULL,
                reservation_id INT NOT NULL,
                user_id INT NOT NULL,
                staff_id INT NOT NULL,
                actual_date DATE NOT NULL,
                check_in_type VARCHAR(20) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (entry_pass_id),
                INDEX (reservation_id)
            )
        ");
    }
    
    /**
     * ===== CREATE ADJUSTMENT REQUESTS TABLE =====
     * Create date_adjustment_requests table
     */
    private function createAdjustmentRequestsTable() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS date_adjustment_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entry_pass_id INT NOT NULL,
                reservation_id INT NOT NULL,
                user_id INT NOT NULL,
                requested_check_in DATE NOT NULL,
                requested_check_out DATE NOT NULL,
                reason TEXT,
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                reviewed_by INT NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (entry_pass_id),
                INDEX (reservation_id),
                INDEX (user_id),
                INDEX (status)
            )
        ");
    }
    
    /**
     * Schedule reminders for reservation
     */
    public function scheduleReminders($reservation_id, $user_id, $check_in_date) {
        $check_in = new DateTime($check_in_date);
        $today = new DateTime();
        
        // Calculate reminder dates
        $three_days_before = clone $check_in;
        $three_days_before->modify('-3 days');
        
        $one_day_before = clone $check_in;
        $one_day_before->modify('-1 day');
        
        $on_day = clone $check_in;
        $on_day->setTime(8, 0, 0); // 8 AM on check-in day
        
        // Schedule 3 days before reminder if it's in the future
        if ($three_days_before > $today) {
            $this->db->query(
                "INSERT INTO reservation_reminders 
                 (reservation_id, user_id, reminder_type, reminder_date, status) 
                 VALUES (?, ?, '3_days_before', ?, 'pending')",
                [$reservation_id, $user_id, $three_days_before->format('Y-m-d H:i:s')]
            );
        }
        
        // Schedule 1 day before reminder
        if ($one_day_before > $today) {
            $this->db->query(
                "INSERT INTO reservation_reminders 
                 (reservation_id, user_id, reminder_type, reminder_date, status) 
                 VALUES (?, ?, '1_day_before', ?, 'pending')",
                [$reservation_id, $user_id, $one_day_before->format('Y-m-d H:i:s')]
            );
        }
        
        // Schedule day of reminder
        if ($on_day > $today) {
            $this->db->query(
                "INSERT INTO reservation_reminders 
                 (reservation_id, user_id, reminder_type, reminder_date, status) 
                 VALUES (?, ?, 'on_day', ?, 'pending')",
                [$reservation_id, $user_id, $on_day->format('Y-m-d H:i:s')]
            );
        }
    }
    
    /**
     * Send entry pass email to guest
     */
    public function sendEntryPassEmail($reservation, $otp, $entry_pass_id) {
        // Define BASE_URL if not defined
        if (!defined('BASE_URL')) {
            define('BASE_URL', 'http://localhost/veripool');
        }
        
        $subject = "Your Veripool Entry Pass - Reservation #" . $reservation['id'];
        
        $html_message = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { 
                    background: linear-gradient(135deg, #102C57, #1e3a6b); 
                    color: white; 
                    padding: 30px; 
                    text-align: center;
                    border-radius: 10px 10px 0 0;
                }
                .content { 
                    padding: 30px; 
                    background: #f9f9f9;
                    border: 1px solid #ddd;
                    border-top: none;
                }
                .otp-section {
                    text-align: center;
                    margin: 30px 0;
                }
                .otp-code { 
                    font-size: 48px; 
                    font-weight: bold; 
                    color: #102C57; 
                    padding: 20px 40px; 
                    background: white; 
                    border-radius: 10px; 
                    display: inline-block;
                    letter-spacing: 10px;
                    border: 2px dashed #102C57;
                }
                .pass-details {
                    background: white;
                    padding: 20px;
                    border-radius: 10px;
                    margin: 20px 0;
                }
                .detail-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 10px 0;
                    border-bottom: 1px solid #eee;
                }
                .detail-label {
                    font-weight: bold;
                    color: #666;
                }
                .detail-value {
                    color: #102C57;
                    font-weight: bold;
                }
                .instructions {
                    background: #e8f4fd;
                    padding: 15px;
                    border-radius: 5px;
                    margin: 20px 0;
                }
                .footer { 
                    text-align: center; 
                    padding: 20px; 
                    color: #666; 
                    font-size: 12px;
                    background: #f0f0f0;
                    border-radius: 0 0 10px 10px;
                }
                .button {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #102C57;
                    color: white;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 10px 0;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🎟️ Your Entry Pass is Ready!</h1>
                    <p>Show this code at the entrance</p>
                </div>
                <div class="content">
                    <p>Dear ' . htmlspecialchars($reservation['full_name']) . ',</p>
                    <p>Your reservation has been confirmed and your entry pass is ready. Please present the OTP code below at the entrance:</p>
                    
                    <div class="otp-section">
                        <div class="otp-code">' . $otp . '</div>
                    </div>
                    
                    <div class="pass-details">
                        <h3>📋 Reservation Details</h3>
                        <div class="detail-row">
                            <span class="detail-label">Reservation #:</span>
                            <span class="detail-value">' . $reservation['id'] . '</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Facility:</span>
                            <span class="detail-value">' . htmlspecialchars($reservation['facility_name']) . ' (' . ucfirst($reservation['facility_type']) . ')</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Check-in:</span>
                            <span class="detail-value">' . date('F d, Y', strtotime($reservation['check_in_date'])) . ' at 2:00 PM</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Check-out:</span>
                            <span class="detail-value">' . date('F d, Y', strtotime($reservation['check_out_date'])) . ' at 12:00 PM</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Guests:</span>
                            <span class="detail-value">' . ($reservation['adults'] + $reservation['children']) . '</span>
                        </div>
                    </div>
                    
                    <div class="instructions">
                        <h4>📌 Important Instructions:</h4>
                        <ul>
                            <li>Present this OTP at the entrance gate</li>
                            <li>The OTP is valid from ' . date('M d, Y', strtotime($reservation['check_in_date'])) . ' 2:00 PM until ' . date('M d, Y', strtotime($reservation['check_out_date'])) . ' 12:00 PM</li>
                            <li>Each OTP can only be used once per entry</li>
                            <li>Keep this code confidential - do not share with others</li>
                            <li>You will receive reminders before your reservation date</li>
                        </ul>
                    </div>
                    
                    <p style="text-align: center;">
                        <a href="' . BASE_URL . '/guest/view-pass.php?id=' . $entry_pass_id . '" class="button">View Digital Pass</a>
                    </p>
                    
                    <p>We look forward to hosting you at Veripool!</p>
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' Veripool Reservation System. All rights reserved.</p>
                    <p>This is an automated message, please do not reply.</p>
                </div>
            </div>
        </body>
        </html>';
        
        $text_message = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_message));
        
        return $this->sendEmail($reservation['email'], $subject, $html_message, $text_message);
    }
    
    /**
     * Resend entry pass email after guest data adjustment
     */
    public function resendEntryPassEmail($reservation_id) {
        try {
            // Get reservation details with user info
            $reservation = $this->db->getRow("
                SELECT r.*, u.email, u.full_name, u.phone
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                WHERE r.id = ?",
                [$reservation_id]
            );
            
            if (!$reservation) {
                return ['success' => false, 'message' => 'Reservation not found'];
            }
            
            // Get active entry pass
            $entry_pass = $this->db->getRow("
                SELECT * FROM entry_passes 
                WHERE reservation_id = ? AND status = 'active'
                ORDER BY created_at DESC LIMIT 1",
                [$reservation_id]
            );
            
            if (!$entry_pass) {
                return ['success' => false, 'message' => 'No active entry pass found for this reservation'];
            }
            
            // Get facility name based on reservation type
            $facility_name = 'Unknown';
            $facility_type = 'unknown';
            
            if (!empty($reservation['room_id'])) {
                $room = $this->db->getRow("SELECT room_number FROM rooms WHERE id = ?", [$reservation['room_id']]);
                $facility_name = $room ? $room['room_number'] : 'Room #' . $reservation['room_id'];
                $facility_type = 'room';
            } elseif (!empty($reservation['cottage_id'])) {
                $cottage = $this->db->getRow("SELECT name FROM cottages WHERE id = ?", [$reservation['cottage_id']]);
                $facility_name = $cottage ? $cottage['name'] : 'Cottage #' . $reservation['cottage_id'];
                $facility_type = 'cottage';
            } elseif (!empty($reservation['pool_id'])) {
                $pool = $this->db->getRow("SELECT name FROM pools WHERE id = ?", [$reservation['pool_id']]);
                $facility_name = $pool ? $pool['name'] : 'Pool #' . $reservation['pool_id'];
                $facility_type = 'pool';
            }
            
            $reservation['facility_name'] = $facility_name;
            $reservation['facility_type'] = $facility_type;
            
            // Send the email
            $email_sent = $this->sendEntryPassEmail($reservation, $entry_pass['otp_code'], $entry_pass['id']);
            
            if ($email_sent) {
                // Log the resend
                $this->db->query(
                    "INSERT INTO email_logs (reservation_id, email_type, sent_at) VALUES (?, 'entry_pass_resend', NOW())",
                    [$reservation_id]
                );
                
                return [
                    'success' => true,
                    'message' => 'Entry pass email resent successfully',
                    'otp' => $entry_pass['otp_code']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to send email'
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error resending entry pass email: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Update entry pass dates when reservation dates change
     */
    public function updateEntryPassDates($reservation_id, $new_check_in, $new_check_out) {
        try {
            // Get active entry pass
            $entry_pass = $this->db->getRow("
                SELECT * FROM entry_passes 
                WHERE reservation_id = ? AND status = 'active'
                ORDER BY created_at DESC LIMIT 1",
                [$reservation_id]
            );
            
            if (!$entry_pass) {
                return ['success' => false, 'message' => 'No active entry pass found'];
            }
            
            // Update validity dates
            $valid_from = $new_check_in . ' 14:00:00';
            $valid_until = $new_check_out . ' 12:00:00';
            
            $this->db->query(
                "UPDATE entry_passes SET valid_from = ?, valid_until = ? WHERE id = ?",
                [$valid_from, $valid_until, $entry_pass['id']]
            );
            
            // Reschedule reminders
            $this->scheduleReminders($reservation_id, $entry_pass['user_id'], $new_check_in);
            
            return [
                'success' => true,
                'message' => 'Entry pass dates updated successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Error updating entry pass dates: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Send entry pass via SMS (simulated)
     */
    private function sendEntryPassSMS($phone, $otp, $reservation) {
        $message = "Veripool Entry Pass: Your OTP for reservation #{$reservation['id']} is: {$otp}. Valid from " . date('M d', strtotime($reservation['check_in_date'])) . " 2PM. Show this at entrance.";
        
        // Log SMS for now
        error_log("SMS would be sent to $phone: $message");
        return true;
    }
    
    /**
     * Verify entry pass OTP at entrance
     */
    public function verifyEntryPass($otp, $reservation_id = null) {
        $query = "SELECT ep.*, r.*, u.full_name, u.email 
                  FROM entry_passes ep
                  JOIN reservations r ON ep.reservation_id = r.id
                  JOIN users u ON ep.user_id = u.id
                  WHERE ep.otp_code = ?";
        
        $params = [$otp];
        
        if ($reservation_id) {
            $query .= " AND ep.reservation_id = ?";
            $params[] = $reservation_id;
        }
        
        $pass = $this->db->getRow($query, $params);
        
        if (!$pass) {
            return ['success' => false, 'message' => 'Invalid OTP'];
        }
        
        // Check status
        if ($pass['status'] != 'active') {
            return ['success' => false, 'message' => 'This pass is no longer valid (Status: ' . ucfirst($pass['status']) . ')'];
        }
        
        // Check validity period
        $now = new DateTime();
        $valid_from = new DateTime($pass['valid_from']);
        $valid_until = new DateTime($pass['valid_until']);
        
        if ($now < $valid_from) {
            return [
                'success' => false, 
                'message' => 'This pass is not yet valid. Valid from: ' . $valid_from->format('M d, Y h:i A')
            ];
        }
        
        if ($now > $valid_until) {
            return [
                'success' => false, 
                'message' => 'This pass has expired. Valid until: ' . $valid_until->format('M d, Y h:i A')
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Valid entry pass',
            'data' => $pass
        ];
    }
    
    /**
     * Verify OTP and automatically check in the guest
     */
    public function verifyOTP($otp_code, $reservation_id) {
        try {
            // First verify the OTP using existing method
            $verification = $this->verifyEntryPass($otp_code, $reservation_id);
            
            if (!$verification['success']) {
                return $verification;
            }
            
            $pass = $verification['data'];
            
            // Check if already used
            if ($pass['status'] == 'used') {
                return [
                    'success' => false,
                    'message' => 'This OTP was already used at: ' . date('M d, Y h:i A', strtotime($pass['used_at']))
                ];
            }
            
            // Check if already has active entry pass
            $existing_active = $this->db->getRow(
                "SELECT * FROM entry_passes WHERE reservation_id = ? AND status = 'active'",
                [$reservation_id]
            );
            
            if ($existing_active && $existing_active['id'] != $pass['id']) {
                return [
                    'success' => false,
                    'message' => 'Guest already has an active entry pass'
                ];
            }
            
            // Mark entry pass as used
            $this->db->query(
                "UPDATE entry_passes SET status = 'used', used_at = NOW() WHERE id = ?",
                [$pass['id']]
            );
            
            // Update reservation status to checked_in
            $this->db->query(
                "UPDATE reservations SET status = 'checked_in', updated_at = NOW() WHERE id = ?",
                [$reservation_id]
            );
            
            // Update room status if applicable
            if (!empty($pass['room_id'])) {
                $this->db->query(
                    "UPDATE rooms SET status = 'occupied' WHERE id = ?",
                    [$pass['room_id']]
                );
            }
            
            return [
                'success' => true,
                'message' => 'OTP verified successfully. Guest checked in.',
                'data' => [
                    'guest_name' => $pass['full_name'],
                    'reservation_id' => $reservation_id,
                    'check_in_date' => $pass['check_in_date']
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Error in verifyOTP: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error verifying OTP: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Mark entry pass as used
     */
    public function useEntryPass($pass_id) {
        $this->db->query(
            "UPDATE entry_passes SET status = 'used', used_at = NOW() WHERE id = ?",
            [$pass_id]
        );
        
        return ['success' => true, 'message' => 'Entry pass used successfully'];
    }
    
    /**
     * Send reservation reminder
     */
    public function sendReminder($reminder_id) {
        $reminder = $this->db->getRow("
            SELECT r.*, u.email, u.full_name, u.phone, 
                   res.check_in_date, res.guests
            FROM reservation_reminders r
            JOIN users u ON r.user_id = u.id
            JOIN reservations res ON r.reservation_id = res.id
            WHERE r.id = ?",
            [$reminder_id]
        );
        
        if (!$reminder || $reminder['status'] != 'pending') {
            return false;
        }
        
        $days_left = $this->getDaysLeftMessage($reminder['reminder_type']);
        $subject = "🔔 Veripool Reminder: Your reservation is $days_left";
        
        $html_message = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { 
                    background: #28a745; 
                    color: white; 
                    padding: 20px; 
                    text-align: center;
                    border-radius: 10px 10px 0 0;
                }
                .content { padding: 30px; background: #f9f9f9; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>🔔 Reservation Reminder</h2>
                </div>
                <div class="content">
                    <p>Dear ' . htmlspecialchars($reminder['full_name']) . ',</p>
                    <p>This is a friendly reminder that your Veripool reservation is ' . $days_left . '.</p>
                    
                    <p><strong>Reservation Details:</strong></p>
                    <ul>
                        <li>Check-in Date: ' . date('F d, Y', strtotime($reminder['check_in_date'])) . '</li>
                        <li>Number of Guests: ' . $reminder['guests'] . '</li>
                    </ul>
                    
                    <p>Your entry pass OTP will be ready on the day of your reservation.</p>
                    
                    <p>We look forward to seeing you!</p>
                </div>
                <div class="footer">
                    <p>Veripool Reservation System</p>
                </div>
            </div>
        </body>
        </html>';
        
        $text_message = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_message));
        
        $email_sent = $this->sendEmail($reminder['email'], $subject, $html_message, $text_message);
        
        if ($email_sent) {
            // Update reminder status
            $this->db->query(
                "UPDATE reservation_reminders SET status = 'sent', sent_at = NOW() WHERE id = ?",
                [$reminder_id]
            );
            
            // Update reservation tracking
            switch ($reminder['reminder_type']) {
                case '3_days_before':
                    $this->db->query("UPDATE reservations SET reminder_sent_3days = 1 WHERE id = ?", [$reminder['reservation_id']]);
                    break;
                case '1_day_before':
                    $this->db->query("UPDATE reservations SET reminder_sent_1day = 1 WHERE id = ?", [$reminder['reservation_id']]);
                    break;
                case 'on_day':
                    $this->db->query("UPDATE reservations SET reminder_sent_today = 1 WHERE id = ?", [$reminder['reservation_id']]);
                    break;
            }
            
            return true;
        }
        
        $this->db->query(
            "UPDATE reservation_reminders SET status = 'failed' WHERE id = ?",
            [$reminder_id]
        );
        return false;
    }
    
    /**
     * Get days left message based on reminder type
     */
    private function getDaysLeftMessage($type) {
        switch ($type) {
            case '3_days_before':
                return 'in 3 days';
            case '1_day_before':
                return 'tomorrow';
            case 'on_day':
                return 'today';
            default:
                return 'coming up soon';
        }
    }
    
    /**
     * Process pending reminders (to be run by cron job)
     */
    public function processPendingReminders() {
        $now = date('Y-m-d H:i:s');
        
        // Use getRows instead of getAll
        $pending = $this->db->getRows("
            SELECT * FROM reservation_reminders 
            WHERE status = 'pending' 
            AND reminder_date <= ?",
            [$now]
        );
        
        $processed = 0;
        if (is_array($pending)) {
            foreach ($pending as $reminder) {
                if ($this->sendReminder($reminder['id'])) {
                    $processed++;
                }
            }
        }
        
        return $processed;
    }
    
    /**
     * Get entry pass for a reservation
     */
    public function getEntryPass($reservation_id) {
        return $this->db->getRow("
            SELECT ep.*, u.full_name, u.email, r.check_in_date, r.check_out_date
            FROM entry_passes ep
            JOIN users u ON ep.user_id = u.id
            JOIN reservations r ON ep.reservation_id = r.id
            WHERE ep.reservation_id = ?",
            [$reservation_id]
        );
    }
    
    /**
     * Validate OTP at entrance (for staff)
     */
    public function validateEntryOTP($otp) {
        // First verify the OTP
        $verification = $this->verifyEntryPass($otp);
        
        if (!$verification['success']) {
            return $verification;
        }
        
        $pass = $verification['data'];
        
        // Check if already used
        if ($pass['status'] == 'used') {
            return [
                'success' => false,
                'message' => 'This entry pass was already used at: ' . date('M d, Y h:i A', strtotime($pass['used_at']))
            ];
        }
        
        // Mark as used
        $this->useEntryPass($pass['id']);
        
        return [
            'success' => true,
            'message' => 'Entry verified successfully. Welcome to Veripool!',
            'guest_name' => $pass['full_name'],
            'reservation_details' => [
                'id' => $pass['reservation_id'],
                'check_in' => $pass['check_in_date'],
                'check_out' => $pass['check_out_date'],
                'guests' => $pass['guests']
            ]
        ];
    }
}