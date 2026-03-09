<?php
/**
 * Cron Job for sending reservation reminders
 * Run this script daily at 8:00 AM
 * Add to crontab: 0 8 * * * php /path/to/veripool/cron/send-reminders.php
 */

require_once '../config/database.php';
require_once '../includes/EntryPassManager.php';

$db = new Database();
$entryPassManager = new EntryPassManager($db);

// Log start
error_log("[" . date('Y-m-d H:i:s') . "] Starting reminder processor");

// Process pending reminders
$processed = $entryPassManager->processPendingReminders();

// Log results
error_log("[" . date('Y-m-d H:i:s') . "] Processed $processed reminders");

echo "Processed $processed reminders\n";