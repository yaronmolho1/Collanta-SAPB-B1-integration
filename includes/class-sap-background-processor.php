<?php
/**
 * SAP Background Processor Class
 * 
 * Handles SAP integration tasks in background using Action Scheduler
 * Prevents blocking the main WordPress site during heavy API operations
 *
 * @package SAP_Integration
 * @subpackage Includes
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SAP_Background_Processor {
    
    /**
     * Action hooks for background jobs
     */
    const HOOK_PRODUCT_IMPORT = 'sap_bg_product_import';
    const HOOK_ORDER_INTEGRATION = 'sap_bg_order_integration';
    const HOOK_STOCK_UPDATE = 'sap_bg_stock_update';
    
    /**
     * Telegram credentials for notifications
     */
    private static $telegram_token = '8309945060:AAHKHfGtTf6D_U_JnapGrTHxOLcuht9ULA4';
    private static $telegram_chat_id = '5418067438';
    
    /**
     * Initialize the background processor
     */
    public static function init() {
        // Prevent duplicate registration
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;
        
        // Register action hooks for background processing
        add_action(self::HOOK_PRODUCT_IMPORT, [__CLASS__, 'process_product_import']);
        add_action(self::HOOK_ORDER_INTEGRATION, [__CLASS__, 'process_order_integration']);
        add_action(self::HOOK_STOCK_UPDATE, [__CLASS__, 'process_stock_update']);
        
        // Test hook for debugging
        add_action('sap_test_job', [__CLASS__, 'process_test_job']);
        
        // Register admin notices for job status (only if in admin)
        if (is_admin()) {
            add_action('admin_notices', [__CLASS__, 'display_job_status_notices']);
        }
        
        // Debug hook registration
        // $context = defined('DOING_CRON') && DOING_CRON ? 'WP-CRON' : 'NORMAL';
        // error_log("SAP Background Processor: Hooks registered in {$context} context");
        
        // Verify hooks are actually registered
        // self::verify_hook_registration();
    }
    
    /**
     * Verify that our hooks are properly registered
     */
    private static function verify_hook_registration() {
        $hooks_to_check = [
            self::HOOK_PRODUCT_IMPORT,
            self::HOOK_ORDER_INTEGRATION,
            self::HOOK_STOCK_UPDATE
        ];
        
        foreach ($hooks_to_check as $hook) {
            $has_callback = has_action($hook, [__CLASS__, str_replace('sap_bg_', 'process_', $hook)]);
            if (!$has_callback) {
                // error_log("SAP Background Processor: WARNING - No callback registered for {$hook}");
            } else {
                // error_log("SAP Background Processor: ✓ Callback verified for {$hook}");
            }
        }
    }
    
    /**
     * Process test job for debugging
     */
    public static function process_test_job($args) {
        error_log('SAP Background Processor: Test job executed successfully with args: ' . print_r($args, true));
    }
    
    /**
     * Check if Action Scheduler is available
     * 
     * @return bool
     */
    public static function is_action_scheduler_available() {
        $available = function_exists('as_schedule_single_action') && class_exists('ActionScheduler');
        
        if (!$available) {
            error_log('SAP Background Processor: Action Scheduler not available - functions: ' . 
                (function_exists('as_schedule_single_action') ? 'YES' : 'NO') . 
                ', class: ' . (class_exists('ActionScheduler') ? 'YES' : 'NO'));
        }
        
        return $available;
    }
    
    /**
     * Test Action Scheduler functionality
     */
    public static function test_action_scheduler() {
        if (!self::is_action_scheduler_available()) {
            return 'Action Scheduler not available';
        }
        
        // Schedule a test job
        $test_job_id = as_schedule_single_action(time() + 5, 'sap_test_job', ['test' => true]);
        
        if ($test_job_id) {
            error_log("SAP Background Processor: Test job scheduled with ID: {$test_job_id}");
            
            // Try to force run the queue
            if (class_exists('ActionScheduler_QueueRunner')) {
                try {
                    ActionScheduler_QueueRunner::instance()->run();
                    return "Test job scheduled: {$test_job_id} and queue runner executed";
                } catch (Exception $e) {
                    return "Test job scheduled: {$test_job_id} but queue runner failed: " . $e->getMessage();
                }
            }
            
            return "Test job scheduled: {$test_job_id}";
        } else {
            error_log('SAP Background Processor: Failed to schedule test job');
            return 'Failed to schedule test job';
        }
    }
    
    /**
     * Force process Action Scheduler queue
     */
    public static function force_process_queue() {
        if (!self::is_action_scheduler_available()) {
            error_log('SAP Background Processor: Action Scheduler not available for queue processing');
            return false;
        }
        
        try {
            if (class_exists('ActionScheduler_QueueRunner')) {
                $runner = ActionScheduler_QueueRunner::instance();
                $processed = $runner->run();
                error_log("SAP Background Processor: Forced queue processing - processed {$processed} actions");
                return $processed > 0 ? $processed : true;
            }
        } catch (Exception $e) {
            error_log('SAP Background Processor: Queue processing failed: ' . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Alternative execution method - bypass Action Scheduler entirely
     */
    public static function execute_synchronous_fallback($job_type, $args = []) {
        error_log("SAP Background Processor: Executing synchronous fallback for {$job_type}");
        
        try {
            switch ($job_type) {
                case 'product_import':
                    if (function_exists('sap_create_products_from_api')) {
                        return sap_create_products_from_api();
                    }
                    break;
                    
                    
                case 'order_integration':
                    if (function_exists('sap_handle_order_integration') && isset($args['order_id'])) {
                        sap_handle_order_integration($args['order_id']);
                        return true;
                    }
                    break;
                    
                case 'stock_update':
                    if (function_exists('sap_update_variations_from_api')) {
                        $item_code_filter = isset($args['item_code_filter']) ? $args['item_code_filter'] : null;
                        return sap_update_variations_from_api($item_code_filter);
                    }
                    break;
            }
        } catch (Exception $e) {
            error_log("SAP Background Processor: Synchronous fallback failed for {$job_type}: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Queue product import job
     * 
     * @param array $args Optional arguments for the import
     * @return int|false Job ID or false on failure
     */
    public static function queue_product_import($args = []) {
        if (!self::is_action_scheduler_available()) {
            return false;
        }
        
        // Prevent duplicate jobs - check if similar job is already pending
        if (self::has_pending_job(self::HOOK_PRODUCT_IMPORT)) {
            error_log('SAP Background Processor: Product import job already pending, skipping duplicate');
            return false;
        }
        
        $job_args = array_merge([
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql'),
            'item_code_filter' => isset($args['item_code_filter']) ? $args['item_code_filter'] : ''
        ], $args);
        
        // Schedule with delay to ensure true async processing (30 seconds minimum)
        $schedule_time = time() + 30;
        $job_id = as_schedule_single_action($schedule_time, self::HOOK_PRODUCT_IMPORT, [$job_args]);
        
        // error_log("SAP Background Processor: Product import job scheduled with ID: {$job_id} for execution at " . date('Y-m-d H:i:s', $schedule_time));
        
        if ($job_id) {
            // Store job info for status tracking
            self::store_job_info($job_id, 'product_import', $job_args);
            
            // Send start notification
            self::send_telegram_notification(
                "🚀 Product Import Queued",
                "Product import job queued successfully.\nJob ID: {$job_id}\nUser: " . wp_get_current_user()->display_name . "\nExecution: " . date('H:i:s', $schedule_time)
            );
            
            // NO MORE force_process_queue() - let WP-Cron handle it asynchronously
        }
        
        return $job_id;
    }
    
    
    /**
     * Queue stock update job
     * 
     * @param string|null $item_code_filter Optional single item code to update
     * @return int|false Job ID or false on failure
     */
    public static function queue_stock_update($item_code_filter = null) {
        if (!self::is_action_scheduler_available()) {
            return false;
        }
        
        // Prevent duplicate jobs
        if (self::has_pending_job(self::HOOK_STOCK_UPDATE)) {
            error_log('SAP Background Processor: Stock update job already pending, skipping duplicate');
            return false;
        }
        
        $job_args = [
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql'),
            'item_code_filter' => $item_code_filter
        ];
        
        // Schedule with delay to ensure true async processing (30 seconds minimum)
        $schedule_time = time() + 30;
        $job_id = as_schedule_single_action($schedule_time, self::HOOK_STOCK_UPDATE, [$job_args]);
        
        if ($job_id) {
            // Store job info for status tracking
            self::store_job_info($job_id, 'stock_update', $job_args);
            
            // Send start notification
            $filter_text = !empty($item_code_filter) ? " (פריט: {$item_code_filter})" : " (כל הפריטים)";
            self::send_telegram_notification(
                "עדכון מלאי נכנס לתור",
                "עדכון מלאי נכנס לתור בהצלחה{$filter_text}.\nמזהה משימה: {$job_id}\nמשתמש: " . wp_get_current_user()->display_name . "\nביצוע: " . date('H:i:s', $schedule_time)
            );
            
            // NO MORE force_process_queue() - let WP-Cron handle it asynchronously
        }
        
        return $job_id;
    }
    
    /**
     * Queue order integration job - NO RETRIES
     * 
     * @param int $order_id WooCommerce order ID
     * @return int|false Job ID or false on failure
     */
    public static function queue_order_integration($order_id) {
        if (!self::is_action_scheduler_available()) {
            // Fallback to synchronous processing
            return self::fallback_order_integration($order_id);
        }
        
        // Check if order already has a pending job (prevent duplicates)
        if (self::has_pending_order_job($order_id)) {
            error_log("SAP Background Processor: Order {$order_id} already has pending job, skipping duplicate");
            return false;
        }
        
        $job_args = [
            'order_id' => $order_id,
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql')
        ];
        
        // Schedule immediately for instant processing
        $job_id = as_enqueue_async_action(self::HOOK_ORDER_INTEGRATION, [$job_args]);
        
        // error_log("SAP Background Processor: Order integration job scheduled with ID: {$job_id} for execution at " . date('Y-m-d H:i:s', $schedule_time));
        
        if ($job_id) {
            // Store job info for status tracking
            self::store_job_info($job_id, 'order_integration', $job_args);
            
            error_log("SAP Background Processor: Order {$order_id} queued for background integration (Job ID: {$job_id})");
            
            // Force immediate execution for critical order processing
            self::force_immediate_execution();
        }
        
        return $job_id;
    }
    
    /**
     * Process product import in background
     * 
     * @param array $args Job arguments
     */
    public static function process_product_import($args) {
        error_log('SAP Background Processor: Starting product import job - callback executed successfully');
        
        try {
            // Load all required files in case they're not loaded in cron context
            self::ensure_functions_loaded();
            
            // Ensure required functions are available
            if (!function_exists('sap_create_products_from_api')) {
                throw new Exception('Product creation function not available after loading files');
            }
            
            // Start output buffering to capture the result
            ob_start();
            $result = sap_create_products_from_api();
            $output = ob_get_clean();
            
            // Extract job completion info
            $success = !empty($result) && strpos($result, 'שגיאה') === false;
            
            // Send completion notification in Hebrew
            $status_text = $success ? "הושלם בהצלחה" : "הושלם עם שגיאות";
            $title = "יצירת מוצרים מ-SAP {$status_text}";
            
            $message = "\nמשתמש: " . get_user_by('id', $args['user_id'])->display_name;
            $message .= "\nזמן: " . current_time('Y-m-d H:i:s');
            
            if (!empty($args['item_code_filter'])) {
                $message .= "\nמסנן: {$args['item_code_filter']}";
            }
            
            // Add summary if available
            if (!empty($output)) {
                $clean_output = strip_tags($output);
                $summary = substr($clean_output, 0, 200);
                $message .= "\n\nתקציר: {$summary}...";
            }
            
            self::send_telegram_notification($title, $message);
            
            error_log('SAP Background Processor: Product import job completed successfully');
            
        } catch (Exception $e) {
            $error_msg = 'Product import failed: ' . $e->getMessage();
            error_log('SAP Background Processor: ' . $error_msg);
            
            // Send error notification
            self::send_telegram_notification(
                "יצירת מוצרים מ-SAP נכשלה",
                "שגיאה: {$error_msg}\nמשתמש: " . get_user_by('id', $args['user_id'])->display_name . "\nזמן: " . current_time('Y-m-d H:i:s')
            );
        }
    }
    
    
    /**
     * Process stock update in background
     * 
     * @param array $args Job arguments
     */
    public static function process_stock_update($args) {
        error_log('SAP Background Processor: Starting stock update job - callback executed successfully');
        
        try {
            // Load all required files in case they're not loaded in cron context
            self::ensure_functions_loaded();
            
            // Ensure required functions are available
            if (!function_exists('sap_update_variations_from_api')) {
                throw new Exception('Stock update function not available after loading files');
            }
            
            // Get item code filter if provided
            $item_code_filter = isset($args['item_code_filter']) ? $args['item_code_filter'] : null;
            
            // Define constant to get structured data from the function
            if (!defined('SAP_BACKGROUND_PROCESSING')) {
                define('SAP_BACKGROUND_PROCESSING', true);
            }
            
            // Call the stock update function
            $result = sap_update_variations_from_api($item_code_filter);
            
            // Check if we got structured data (new format) or just HTML (old format)
            if (is_array($result) && isset($result['stats']) && isset($result['failed_items'])) {
                // New structured format - use exact same notification logic as sap-products-import.php
                $stats = $result['stats'];
                $failed_items = $result['failed_items'];
                
                // Send completion notification using EXACT same format as sap-products-import.php
                if (empty($failed_items)) {
                    // Success - all items updated
                    $complete_message = "עדכון מלאי מ-SAP הושלם בהצלחה\n\n";
                    $complete_message .= "פריטים שעובדו: {$stats['processed']}\n";
                    $complete_message .= "מלאי עודכן: {$stats['updated']}\n";
                    $complete_message .= "זמן: " . current_time('Y-m-d H:i:s');
                } else {
                    // Partial failure - list failed items
                    $complete_message = "עדכון מלאי מ-SAP הושלם עם שגיאות\n\n";
                    $complete_message .= "פריטים שעובדו: {$stats['processed']}\n";
                    $complete_message .= "מלאי עודכן: {$stats['updated']}\n";
                    $complete_message .= "נכשלו: {$stats['not_found']}\n";
                    $complete_message .= "שגיאות: {$stats['errors']}\n\n";
                    
                    $complete_message .= "פריטים שנכשלו:\n";
                    foreach ($failed_items as $failed) {
                        $complete_message .= "- {$failed['item_code']} ({$failed['reason']})\n";
                    }
                    
                    $complete_message .= "\nזמן: " . current_time('Y-m-d H:i:s');
                }
                
                // Add user info for background processing
                $complete_message .= "\n\nמשתמש: " . get_user_by('id', $args['user_id'])->display_name;
                
                // Send using the same telegram function as sap-products-import.php
                if (function_exists('sap_send_telegram_message')) {
                    sap_send_telegram_message($complete_message);
                } else {
                    // Fallback to our notification method
                    self::send_telegram_notification("עדכון מלאי מ-SAP", $complete_message);
                }
                
            } else {
                // Fallback to old parsing method if structured data not available
                $success = !empty($result) && strpos($result, 'שגיאה') === false;
                $status_text = $success ? "הושלם בהצלחה" : "הושלם עם שגיאות";
                $title = "עדכון מלאי מ-SAP {$status_text}";
                
                $message = "\n\nמשתמש: " . get_user_by('id', $args['user_id'])->display_name;
                $message .= "\nזמן: " . current_time('Y-m-d H:i:s');
                
                if (!empty($item_code_filter)) {
                    $message .= "\nמסנן: {$item_code_filter}";
                }
                
                self::send_telegram_notification($title, $message);
            }
            
            error_log('SAP Background Processor: Stock update job completed successfully');
            
        } catch (Exception $e) {
            $error_msg = 'Stock update failed: ' . $e->getMessage();
            error_log('SAP Background Processor: ' . $error_msg);
            
            // Send error notification
            self::send_telegram_notification(
                "עדכון מלאי מ-SAP נכשל",
                "שגיאה: {$error_msg}\nמשתמש: " . get_user_by('id', $args['user_id'])->display_name . "\nזמן: " . current_time('Y-m-d H:i:s')
            );
        }
    }
    
    /**
     * Process order integration in background
     * 
     * @param array $args Job arguments containing order_id
     */
    public static function process_order_integration($args) {
        $order_id = $args['order_id'];
        error_log("SAP Background Processor: Starting order integration for order {$order_id} - callback executed successfully");
        
        try {
            // Load all required files in case they're not loaded in cron context
            self::ensure_functions_loaded();
            
            // Ensure required functions are available
            if (!function_exists('sap_handle_order_integration')) {
                throw new Exception('Order integration function not available after loading files');
            }
            
            // CRITICAL FIX: Double-check order status before processing in background
            $order = wc_get_order($order_id);
            if (!$order) {
                error_log("SAP Background Processor: Order {$order_id} not found, cancelling background job");
                return;
            }
            
            $current_status = $order->get_status();
            if ($current_status !== 'processing') {
                error_log("SAP Background Processor: Order {$order_id} status is '{$current_status}', not processing - cancelling background job");
                
                // Log this as a blocked attempt for monitoring
                if (class_exists('SAP_Sync_Logger')) {
                    SAP_Sync_Logger::log_sync_blocked($order_id, "Background job cancelled - Order status '{$current_status}' not allowed - only processing status accepted");
                }
                
                // Add order note for audit trail
                $order->add_order_note('SAP Background job cancelled: Only processing orders can be sent to SAP. Current status: "' . $current_status . '"');
                
                return;
            }
            
            // Call the original order integration function - unchanged
            sap_handle_order_integration($order_id);
            
            error_log("SAP Background Processor: Order {$order_id} integration completed successfully");
            
            // DON'T send notifications here - the original SAP_Sync_Logger handles all order notifications
            // with the correct chat ID and existing workflow
            
        } catch (Exception $e) {
            $error_msg = "Order {$order_id} integration failed: " . $e->getMessage();
            error_log('SAP Background Processor: ' . $error_msg);
            
            // Don't interfere with existing error handling - SAP_Sync_Logger handles all order errors
        }
    }
    
    /**
     * Force immediate execution of pending Action Scheduler jobs
     * This ensures jobs run instantly without waiting for WP-Cron
     */
    private static function force_immediate_execution() {
        if (!function_exists('as_run_queue')) {
            return false;
        }
        
        // Trigger Action Scheduler to process jobs immediately
        // This runs in a separate process to avoid blocking the main request
        wp_schedule_single_event(time(), 'action_scheduler_run_queue');
        
        // Also try to spawn the queue runner directly
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
        
        return true;
    }
    
    /**
     * Fallback to synchronous order integration when Action Scheduler unavailable
     * 
     * @param int $order_id
     * @return bool
     */
    private static function fallback_order_integration($order_id) {
        error_log("SAP Background Processor: Action Scheduler unavailable, falling back to synchronous processing for order {$order_id}");
        
        if (function_exists('sap_handle_order_integration')) {
            sap_handle_order_integration($order_id);
            return true;
        }
        
        return false;
    }
    
    /**
     * Store job information for status tracking
     * 
     * @param int $job_id Action Scheduler job ID
     * @param string $job_type Type of job
     * @param array $args Job arguments
     */
    private static function store_job_info($job_id, $job_type, $args) {
        $job_info = [
            'job_id' => $job_id,
            'job_type' => $job_type,
            'status' => 'queued',
            'args' => $args,
            'created_at' => current_time('mysql')
        ];
        
        // Store in WordPress options (could be moved to custom table later)
        $jobs = get_option('sap_background_jobs', []);
        $jobs[$job_id] = $job_info;
        update_option('sap_background_jobs', $jobs);
    }
    
    /**
     * Send Telegram notification
     * 
     * @param string $title
     * @param string $message
     */
    private static function send_telegram_notification($title, $message) {
        if (empty(self::$telegram_token) || empty(self::$telegram_chat_id)) {
            return;
        }
        
        $full_message = "*{$title}*\n\n{$message}";
        
        $url = "https://api.telegram.org/bot" . self::$telegram_token . "/sendMessage";
        $data = [
            'chat_id' => self::$telegram_chat_id,
            'text' => $full_message,
            'parse_mode' => 'Markdown'
        ];
        
        wp_remote_post($url, [
            'body' => $data,
            'timeout' => 10,
            'sslverify' => true
        ]);
    }
    
    /**
     * Display admin notices for job status
     */
    public static function display_job_status_notices() {
        // Check for recently completed jobs and display status
        if (isset($_GET['sap_job_queued'])) {
            $job_type = sanitize_text_field($_GET['sap_job_queued']);
            $job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
            
            $notices = [
                'product_import' => 'משימת יבוא מוצרים נכנסה לתור בהצלחה! תוכל להמשיך לעבוד באתר - תקבל התראת טלגרם כשהיא תסתיים.',
                'order_integration' => 'משימת אינטגרציית הזמנות נכנסה לתור בהצלחה!'
            ];
            
            if (isset($notices[$job_type])) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p><strong>' . $notices[$job_type] . '</strong></p>';
                if ($job_id) {
                    echo '<p><small>Job ID: ' . $job_id . '</small></p>';
                }
                echo '</div>';
            }
        }
    }
    
    /**
     * Get job status
     * 
     * @param int $job_id
     * @return string|null
     */
    public static function get_job_status($job_id) {
        if (!self::is_action_scheduler_available()) {
            return null;
        }
        
        $actions = as_get_scheduled_actions([
            'hook' => [self::HOOK_PRODUCT_IMPORT, self::HOOK_ORDER_INTEGRATION, self::HOOK_STOCK_UPDATE],
            'status' => ['pending', 'in-progress', 'complete', 'failed'],
            'per_page' => 1,
            'search' => $job_id
        ]);
        
        if (!empty($actions)) {
            return $actions[0]->get_status();
        }
        
        return null;
    }
    
    /**
     * Ensure all required functions are loaded during cron execution
     */
    private static function ensure_functions_loaded() {
        // Get the plugin directory
        $plugin_dir = dirname(dirname(__FILE__));
        
        // Required files for background processing
        $required_files = [
            $plugin_dir . '/includes/sap-product-create.php',
            $plugin_dir . '/includes/sap-source-codes-sync.php',
            $plugin_dir . '/includes/class-sap-order-integration.php',
            $plugin_dir . '/includes/sap-products-import.php'
        ];
        
        foreach ($required_files as $file) {
            if (file_exists($file)) {
                require_once $file;
                error_log("SAP Background Processor: Loaded required file: " . basename($file));
            } else {
                error_log("SAP Background Processor: Required file not found: " . $file);
            }
        }
    }
    
    /**
     * Check if there's already a pending job for this hook
     */
    private static function has_pending_job($hook) {
        if (!function_exists('as_get_scheduled_actions')) {
            return false;
        }
        
        $pending_actions = as_get_scheduled_actions([
            'hook' => $hook,
            'status' => 'pending',
            'per_page' => 1
        ]);
        
        return !empty($pending_actions);
    }
    
    /**
     * Check if order already has a pending integration job
     */
    private static function has_pending_order_job($order_id) {
        if (!function_exists('as_get_scheduled_actions')) {
            return false;
        }
        
        $pending_actions = as_get_scheduled_actions([
            'hook' => self::HOOK_ORDER_INTEGRATION,
            'status' => 'pending',
            'args' => [['order_id' => $order_id]],
            'per_page' => 1
        ]);
        
        return !empty($pending_actions);
    }
    
    /**
     * Get Action Scheduler queue health status
     */
    public static function get_queue_health() {
        if (!function_exists('as_get_scheduled_actions')) {
            return [
                'status' => 'unavailable',
                'message' => 'Action Scheduler not available',
                'total_pending' => 0,
                'sap_pending' => 0,
                'runner_status' => 'unavailable'
            ];
        }
        
        $pending_count = count(as_get_scheduled_actions([
            'status' => 'pending',
            'per_page' => 100
        ]));
        
        $sap_pending_count = count(as_get_scheduled_actions([
            'hook' => [self::HOOK_PRODUCT_IMPORT, self::HOOK_ORDER_INTEGRATION, self::HOOK_STOCK_UPDATE],
            'status' => 'pending',
            'per_page' => 50
        ]));
        
        // Check runner status
        $runner_status = self::check_runner_status();
        
        $status = 'healthy';
        if ($pending_count > 200 || $runner_status === 'blocked') {
            $status = 'critical';
        } elseif ($pending_count > 50 || $runner_status === 'slow') {
            $status = 'warning';
        }
        
        return [
            'status' => $status,
            'total_pending' => $pending_count,
            'sap_pending' => $sap_pending_count,
            'runner_status' => $runner_status,
            'timestamp' => current_time('mysql'),
            'message' => "Queue: {$status}, Runner: {$runner_status}"
        ];
    }
    
    /**
     * Check Action Scheduler runner status
     * 
     * @return string Runner status (active, slow, blocked, unknown)
     */
    private static function check_runner_status() {
        if (!function_exists('as_get_scheduled_actions')) {
            return 'unknown';
        }
        
        // Check if there are any recently completed actions (last 5 minutes)
        $recent_completed = as_get_scheduled_actions([
            'status' => 'complete',
            'date' => strtotime('-5 minutes'),
            'date_compare' => '>=',
            'per_page' => 1
        ]);
        
        if (!empty($recent_completed)) {
            return 'active';
        }
        
        // Check if there are pending actions older than 2 minutes
        $old_pending = as_get_scheduled_actions([
            'status' => 'pending',
            'date' => strtotime('-2 minutes'),
            'date_compare' => '<=',
            'per_page' => 1
        ]);
        
        if (!empty($old_pending)) {
            return 'blocked';
        }
        
        // Check if there are pending actions older than 30 seconds
        $slow_pending = as_get_scheduled_actions([
            'status' => 'pending',
            'date' => strtotime('-30 seconds'),
            'date_compare' => '<=',
            'per_page' => 1
        ]);
        
        if (!empty($slow_pending)) {
            return 'slow';
        }
        
        return 'active';
    }
}

// Initialize the background processor - register hooks IMMEDIATELY, not on plugins_loaded
// This ensures hooks are available during WP-Cron execution
SAP_Background_Processor::init();

// Also register on plugins_loaded as backup
add_action('plugins_loaded', [SAP_Background_Processor::class, 'init']);

/**
 * Force Action Scheduler to load if not already loaded
 * This runs on both normal requests and WP-Cron
 */
add_action('init', function() {
    // Try to manually load Action Scheduler if it exists but isn't loaded
    if (!function_exists('as_schedule_single_action')) {
        $possible_paths = [
            WP_PLUGIN_DIR . '/woocommerce/packages/action-scheduler/action-scheduler.php',
            WP_PLUGIN_DIR . '/action-scheduler/action-scheduler.php',
            WP_PLUGIN_DIR . '/woocommerce-memberships/lib/prospress/action-scheduler/action-scheduler.php'
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                error_log('SAP Background Processor: Manually loaded Action Scheduler from: ' . $path);
                break;
            }
        }
    }
    
    // Re-initialize hooks to ensure they're available during cron
    SAP_Background_Processor::init();
});

/**
 * Also register hooks on wp_loaded to ensure they're available for cron
 */
add_action('wp_loaded', function() {
    SAP_Background_Processor::init();
});
