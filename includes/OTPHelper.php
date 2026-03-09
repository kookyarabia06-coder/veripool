<?php
/**
 * OTP Helper for Veripool Reservation System
 * Handles OTP generation
 */

class OTPHelper {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Generate a random OTP
     */
    public function generateOTP($length = 6) {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= random_int(0, 9);
        }
        return $otp;
    }
}	