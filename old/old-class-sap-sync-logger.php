<?php
/**
 * SAP Sync Logging and Tracking System
 *
 * @package SAP_Integration
 * @subpackage Includes
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SAP_Sync_Logger {
    
    private static $table_name = 'sap_order_sync_log';
    
    /**
     * Initialize the sync logger
     */
    public static function init() {
        add_action('init', [__CLASS__, 'create_table_if_not_exists']);
    }
    
    /**
     * Create the sync log table if it doesn't exist
     */
    public static function create_table_if_not_exists() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            sync_status enum('pending','in_progress','success','failed','retry_pending','permanently_failed','blocked') DEFAULT 'pending',
            attempt_number int(11) DEFAULT 1,
            last_attempt_time datetime DEFAULT NULL,
            next_retry_time datetime DEFAULT NULL,
            error_message text DEFAULT NULL,
            sap_response longtext DEFAULT NULL,
            customer_doc_entry varchar(50) DEFAULT NULL,
            order_doc_entry varchar(50) DEFAULT NULL,
            invoice_doc_entry varchar(50) DEFAULT NULL,
            payment_doc_entry varchar(50) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order_id (order_id),
            KEY idx_sync_status (sync_status),
            KEY idx_next_retry (next_retry_time),
            UNIQUE KEY unique_order (order_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Log sync attempt start
     */
    public static function log_sync_start($order_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        // Check if record exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d",
            $order_id
        ));
        
        if ($existing) {
            // Update existing record
            $wpdb->update(
                $table_name,
                [
                    'sync_status' => 'in_progress',
                    'last_attempt_time' => current_time('mysql'),
                    'attempt_number' => $existing->attempt_number + 1
                ],
                ['order_id' => $order_id],
                ['%s', '%s', '%d'],
                ['%d']
            );
        } else {
            // Insert new record
            $wpdb->insert(
                $table_name,
                [
                    'order_id' => $order_id,
                    'sync_status' => 'in_progress',
                    'last_attempt_time' => current_time('mysql'),
                    'attempt_number' => 1
                ],
                ['%d', '%s', '%s', '%d']
            );
        }
        
        return $wpdb->insert_id ?: $existing->id;
    }
    
    /**
     * Log sync success
     */
    public static function log_sync_success($order_id, $sap_response = null, $doc_entries = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        $update_data = [
            'sync_status' => 'success',
            'last_attempt_time' => current_time('mysql'),
            'sap_response' => $sap_response ? json_encode($sap_response) : null,
            'next_retry_time' => null,
            'error_message' => null
        ];
        
        // Add SAP document entries if provided
        if (!empty($doc_entries['customer_doc_entry'])) {
            $update_data['customer_doc_entry'] = $doc_entries['customer_doc_entry'];
        }
        if (!empty($doc_entries['order_doc_entry'])) {
            $update_data['order_doc_entry'] = $doc_entries['order_doc_entry'];
        }
        if (!empty($doc_entries['invoice_doc_entry'])) {
            $update_data['invoice_doc_entry'] = $doc_entries['invoice_doc_entry'];
        }
        if (!empty($doc_entries['payment_doc_entry'])) {
            $update_data['payment_doc_entry'] = $doc_entries['payment_doc_entry'];
        }
        
        $wpdb->update(
            $table_name,
            $update_data,
            ['order_id' => $order_id],
            array_fill(0, count($update_data), '%s'),
            ['%d']
        );
        
        error_log("SAP Sync Logger: Order $order_id marked as successful");
    }
    
    /**
     * Log sync failure - NO RETRIES
     */
    public static function log_sync_failure($order_id, $error_message, $sap_response = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        // Mark as permanently failed immediately - no retries
        $wpdb->update(
            $table_name,
            [
                'sync_status' => 'permanently_failed',
                'last_attempt_time' => current_time('mysql'),
                'error_message' => $error_message,
                'sap_response' => $sap_response ? json_encode($sap_response) : null,
                'next_retry_time' => null
            ],
            ['order_id' => $order_id],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        error_log("SAP Sync Logger: Order $order_id failed permanently - no retries");
        
        // Send immediate Telegram notification
        self::send_telegram_failure_alert($order_id, $error_message, 1, 1);
        
        return 'permanently_failed';
    }
    
    /**
     * Check if order should be synced (prevents duplicates) - NO RETRIES
     */
    public static function should_sync_order($order_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        $sync_record = $wpdb->get_row($wpdb->prepare(
            "SELECT sync_status FROM $table_name WHERE order_id = %d",
            $order_id
        ));
        
        if (!$sync_record) {
            return true; // New order, should sync
        }
        
        // Don't sync if already successful, in progress, or failed
        if (in_array($sync_record->sync_status, ['success', 'in_progress', 'permanently_failed', 'blocked'])) {
            return false;
        }
        
        return true; // Should sync
    }
    
    /**
     * Log blocked sync attempt
     */
    public static function log_sync_blocked($order_id, $reason) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        // Check if record exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d",
            $order_id
        ));
        
        if ($existing) {
            // Update existing record
            $wpdb->update(
                $table_name,
                [
                    'sync_status' => 'blocked',
                    'last_attempt_time' => current_time('mysql'),
                    'error_message' => $reason
                ],
                ['order_id' => $order_id],
                ['%s', '%s', '%s'],
                ['%d']
            );
        } else {
            // Insert new record
            $wpdb->insert(
                $table_name,
                [
                    'order_id' => $order_id,
                    'sync_status' => 'blocked',
                    'last_attempt_time' => current_time('mysql'),
                    'error_message' => $reason,
                    'attempt_number' => 1
                ],
                ['%d', '%s', '%s', '%s', '%d']
            );
        }
        
        error_log("SAP Sync Logger: Order $order_id blocked - $reason");
    }
    
    /**
     * Get failed orders for admin dashboard
     */
    public static function get_failed_orders($limit = 50, $offset = 0) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT sl.*, p.post_date 
             FROM $table_name sl
             LEFT JOIN {$wpdb->posts} p ON sl.order_id = p.ID
             WHERE sl.sync_status IN ('permanently_failed', 'blocked')
             ORDER BY sl.last_attempt_time DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ));
        
        return $results;
    }
    
    /**
     * Get sync statistics
     */
    public static function get_sync_stats($days = 7) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        $date_from = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN sync_status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN sync_status = 'permanently_failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN sync_status = 'blocked' THEN 1 ELSE 0 END) as blocked,
                SUM(CASE WHEN sync_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress
             FROM $table_name 
             WHERE created_at >= %s",
            $date_from
        ));
        
        return $stats;
    }
    
    /**
     * Send Telegram failure alert
     */
    private static function send_telegram_failure_alert($order_id, $error_message, $attempt_number, $max_attempts) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $customer_phone = $order->get_billing_phone();
        $customer_email = $order->get_billing_email();
        $order_total = $order->get_total();
        
        $message = "❌ SAP Sync PERMANENTLY FAILED\n\n";
        $message .= "Order #$order_id\n";
        $message .= "Customer: $customer_name\n";
        $message .= "Phone: $customer_phone\n";
        $message .= "Email: $customer_email\n";
        $message .= "Amount: ₪" . number_format($order_total, 2) . "\n\n";
        $message .= "❌ Error: " . substr($error_message, 0, 200) . "\n\n";
        $message .= "Manual intervention required - no retries";
        
        self::send_telegram_message($message);
    }
    
    /**
     * Send message to Telegram
     */
    private static function send_telegram_message($message) {
        $bot_token = '8208088410:AAHNX-YBdnPNsyBdMjITH-kGyzTP268PPUI';
        $chat_id = '5418067438';
        
        $url = "https://api.telegram.org/bot$bot_token/sendMessage";
        
        $data = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];
        
        $args = [
            'method' => 'POST',
            'body' => $data,
            'timeout' => 10,
            'sslverify' => true
        ];
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            error_log('Telegram notification failed: ' . $response->get_error_message());
        } else {
            error_log('Telegram notification sent successfully for message: ' . substr($message, 0, 100));
        }
    }
    
    /**
     * Send daily email summary to admin
     */
    public static function send_daily_email_summary() {
        $stats = self::get_sync_stats(1); // Last 24 hours
        
        if (!$stats || $stats->total_orders == 0) {
            return; // No orders to report
        }
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = "[$site_name] Daily SAP Sync Report - " . date('Y-m-d');
        
        $message = "<h2>Daily SAP Sync Summary</h2>\n";
        $message .= "<p><strong>Report Date:</strong> " . date('Y-m-d H:i:s') . "</p>\n\n";
        
        $message .= "<h3>Statistics (Last 24 Hours)</h3>\n";
        $message .= "<ul>\n";
        $message .= "<li>✅ <strong>Successful Orders:</strong> {$stats->successful}</li>\n";
        $message .= "<li>❌ <strong>Failed Orders:</strong> {$stats->failed}</li>\n";
        $message .= "<li>🚫 <strong>Blocked Orders:</strong> {$stats->blocked}</li>\n";
        $message .= "<li><strong>In Progress:</strong> {$stats->in_progress}</li>\n";
        $message .= "<li><strong>Total Orders:</strong> {$stats->total_orders}</li>\n";
        $message .= "</ul>\n\n";
        
        // Add failed orders details if any
        if ($stats->failed > 0) {
            $failed_orders = self::get_failed_orders(10, 0);
            
            $message .= "<h3>❌ Failed Orders Details</h3>\n";
            $message .= "<table border='1' cellpadding='5' cellspacing='0'>\n";
            $message .= "<tr><th>Order</th><th>Customer</th><th>Phone</th><th>Email</th><th>Error</th><th>Attempts</th></tr>\n";
            
            foreach ($failed_orders as $failed) {
                $order = wc_get_order($failed->order_id);
                if ($order) {
                    $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                    $customer_phone = $order->get_billing_phone();
                    $customer_email = $order->get_billing_email();
                    $error_short = substr($failed->error_message, 0, 100) . '...';
                    
                    $message .= "<tr>\n";
                    $message .= "<td>#{$failed->order_id}</td>\n";
                    $message .= "<td>{$customer_name}</td>\n";
                    $message .= "<td>{$customer_phone}</td>\n";
                    $message .= "<td>{$customer_email}</td>\n";
                    $message .= "<td>{$error_short}</td>\n";
                    $message .= "<td>{$failed->attempt_number}</td>\n";
                    $message .= "</tr>\n";
                }
            }
            
            $message .= "</table>\n\n";
            $message .= "<p><a href='" . admin_url('admin.php?page=sap-importer-settings') . "'>View Full Details in Admin Dashboard</a></p>\n";
        }
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        wp_mail($admin_email, $subject, $message, $headers);
        
        error_log("Daily SAP sync summary sent to $admin_email");
    }
    
    /**
     * Schedule daily email summary
     */
    public static function schedule_daily_summary() {
        if (!wp_next_scheduled('sap_daily_summary_email')) {
            wp_schedule_event(strtotime('tomorrow 09:00'), 'daily', 'sap_daily_summary_email');
        }
    }
    
    /**
     * Retry functionality removed - no retries for orders
     */
}

// Initialize the sync logger
SAP_Sync_Logger::init();

// Schedule daily summary email
// add_action('wp', [SAP_Sync_Logger::class, 'schedule_daily_summary']);

// Hook for daily summary email
// add_action('sap_daily_summary_email', [SAP_Sync_Logger::class, 'send_daily_email_summary']);

// Retry functionality removed - no retries for orders
?>
