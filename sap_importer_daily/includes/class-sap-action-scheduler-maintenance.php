<?php
/**
 * SAP Action Scheduler Maintenance Class
 * 
 * Prevents Action Scheduler from getting clogged up with old logs and actions
 * Implements cleanup, monitoring, and maintenance tasks
 *
 * @package SAP_Integration
 * @subpackage Includes
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SAP_Action_Scheduler_Maintenance {
    
    /**
     * Cleanup settings
     */
    const MAX_COMPLETED_ACTIONS_AGE_DAYS = 7;  // Keep completed actions for 7 days
    const MAX_FAILED_ACTIONS_AGE_DAYS = 30;    // Keep failed actions for 30 days
    const MAX_LOGS_AGE_DAYS = 14;              // Keep logs for 14 days
    const MAX_ACTIONS_PER_CLEANUP = 500;       // Process max 500 actions per cleanup run
    const CLEANUP_INTERVAL_HOURS = 6;          // Run cleanup every 6 hours
    
    /**
     * Initialize maintenance system
     */
    public static function init() {
        // Schedule regular cleanup
        add_action('wp', [__CLASS__, 'schedule_cleanup']);
        
        // Register cleanup hooks
        add_action('sap_action_scheduler_cleanup', [__CLASS__, 'run_cleanup']);
        
        // Monitor Action Scheduler health
        add_action('sap_monitor_action_scheduler', [__CLASS__, 'monitor_health']);
        
        // Schedule health monitoring (daily)
        add_action('wp', [__CLASS__, 'schedule_health_monitoring']);
        
        error_log('SAP Action Scheduler Maintenance: Initialized');
    }
    
    /**
     * Schedule cleanup tasks
     */
    public static function schedule_cleanup() {
        if (!wp_next_scheduled('sap_action_scheduler_cleanup')) {
            wp_schedule_event(time(), 'every_6_hours', 'sap_action_scheduler_cleanup');
            error_log('SAP Action Scheduler Maintenance: Cleanup scheduled');
        }
    }
    
    /**
     * Schedule health monitoring
     */
    public static function schedule_health_monitoring() {
        if (!wp_next_scheduled('sap_monitor_action_scheduler')) {
            wp_schedule_event(time() + 3600, 'daily', 'sap_monitor_action_scheduler'); // Start after 1 hour
            error_log('SAP Action Scheduler Maintenance: Health monitoring scheduled');
        }
    }
    
    /**
     * Run comprehensive cleanup
     */
    public static function run_cleanup() {
        if (!class_exists('ActionScheduler')) {
            error_log('SAP Action Scheduler Maintenance: ActionScheduler not available for cleanup');
            return;
        }
        
        error_log('SAP Action Scheduler Maintenance: Starting cleanup process');
        
        $cleanup_stats = [
            'completed_actions_cleaned' => 0,
            'failed_actions_cleaned' => 0,
            'logs_cleaned' => 0,
            'old_claims_cleaned' => 0,
            'start_time' => time()
        ];
        
        try {
            // 1. Clean old completed actions
            $cleanup_stats['completed_actions_cleaned'] = self::cleanup_old_actions('complete', self::MAX_COMPLETED_ACTIONS_AGE_DAYS);
            
            // 2. Clean old failed actions
            $cleanup_stats['failed_actions_cleaned'] = self::cleanup_old_actions('failed', self::MAX_FAILED_ACTIONS_AGE_DAYS);
            
            // 3. Clean old logs
            $cleanup_stats['logs_cleaned'] = self::cleanup_old_logs();
            
            // 4. Clean orphaned claims
            $cleanup_stats['old_claims_cleaned'] = self::cleanup_old_claims();
            
            // 5. Optimize database tables
            self::optimize_tables();
            
            $cleanup_stats['end_time'] = time();
            $cleanup_stats['duration'] = $cleanup_stats['end_time'] - $cleanup_stats['start_time'];
            
            // Log cleanup results
            $total_cleaned = $cleanup_stats['completed_actions_cleaned'] + 
                           $cleanup_stats['failed_actions_cleaned'] + 
                           $cleanup_stats['logs_cleaned'] + 
                           $cleanup_stats['old_claims_cleaned'];
            
            error_log(sprintf(
                'SAP Action Scheduler Maintenance: Cleanup completed - %d total items cleaned in %d seconds',
                $total_cleaned,
                $cleanup_stats['duration']
            ));
            
            // Store cleanup stats
            update_option('sap_as_last_cleanup', $cleanup_stats);
            
        } catch (Exception $e) {
            error_log('SAP Action Scheduler Maintenance: Cleanup failed - ' . $e->getMessage());
        }
    }
    
    /**
     * Clean old actions by status
     */
    private static function cleanup_old_actions($status, $age_days) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'actionscheduler_actions';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$age_days} days"));
        
        // Count actions to be deleted
        $count_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE status = %s 
             AND last_attempt_gmt < %s 
             LIMIT %d",
            $status,
            $cutoff_date,
            self::MAX_ACTIONS_PER_CLEANUP
        );
        
        $count = $wpdb->get_var($count_query);
        
        if ($count > 0) {
            // Delete old actions
            $delete_query = $wpdb->prepare(
                "DELETE FROM {$table_name} 
                 WHERE status = %s 
                 AND last_attempt_gmt < %s 
                 LIMIT %d",
                $status,
                $cutoff_date,
                self::MAX_ACTIONS_PER_CLEANUP
            );
            
            $deleted = $wpdb->query($delete_query);
            
            if ($deleted !== false) {
                error_log("SAP Action Scheduler Maintenance: Cleaned {$deleted} {$status} actions older than {$age_days} days");
                return $deleted;
            }
        }
        
        return 0;
    }
    
    /**
     * Clean old logs
     */
    private static function cleanup_old_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'actionscheduler_logs';
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . self::MAX_LOGS_AGE_DAYS . ' days'));
        
        $delete_query = $wpdb->prepare(
            "DELETE FROM {$table_name} 
             WHERE log_date_gmt < %s 
             LIMIT %d",
            $cutoff_date,
            self::MAX_ACTIONS_PER_CLEANUP
        );
        
        $deleted = $wpdb->query($delete_query);
        
        if ($deleted !== false && $deleted > 0) {
            error_log("SAP Action Scheduler Maintenance: Cleaned {$deleted} logs older than " . self::MAX_LOGS_AGE_DAYS . " days");
            return $deleted;
        }
        
        return 0;
    }
    
    /**
     * Clean old claims
     */
    private static function cleanup_old_claims() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'actionscheduler_claims';
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-1 hour')); // Claims older than 1 hour
        
        $delete_query = $wpdb->prepare(
            "DELETE FROM {$table_name} 
             WHERE date_created_gmt < %s",
            $cutoff_date
        );
        
        $deleted = $wpdb->query($delete_query);
        
        if ($deleted !== false && $deleted > 0) {
            error_log("SAP Action Scheduler Maintenance: Cleaned {$deleted} old claims");
            return $deleted;
        }
        
        return 0;
    }
    
    /**
     * Optimize Action Scheduler database tables
     */
    private static function optimize_tables() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'actionscheduler_actions',
            $wpdb->prefix . 'actionscheduler_logs',
            $wpdb->prefix . 'actionscheduler_claims',
            $wpdb->prefix . 'actionscheduler_groups'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE {$table}");
        }
        
        error_log('SAP Action Scheduler Maintenance: Database tables optimized');
    }
    
    /**
     * Monitor Action Scheduler health
     */
    public static function monitor_health() {
        if (!class_exists('ActionScheduler')) {
            return;
        }
        
        $health_data = self::get_health_stats();
        
        // Check for concerning conditions
        $warnings = [];
        
        if ($health_data['pending_actions'] > 100) {
            $warnings[] = "High number of pending actions: {$health_data['pending_actions']}";
        }
        
        if ($health_data['failed_actions'] > 50) {
            $warnings[] = "High number of failed actions: {$health_data['failed_actions']}";
        }
        
        if ($health_data['old_logs'] > 1000) {
            $warnings[] = "Large number of old logs: {$health_data['old_logs']}";
        }
        
        if (!empty($warnings)) {
            $message = "Action Scheduler Health Alert\n\n" . implode("\n", $warnings);
            error_log('SAP Action Scheduler Maintenance: ' . $message);
            
            // Send telegram notification if conditions are severe
            if ($health_data['pending_actions'] > 200 || $health_data['failed_actions'] > 100) {
                self::send_health_alert($message);
            }
        }
        
        // Store health data
        update_option('sap_as_health_stats', $health_data);
    }
    
    /**
     * Get Action Scheduler health statistics
     */
    public static function get_health_stats() {
        global $wpdb;
        
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $logs_table = $wpdb->prefix . 'actionscheduler_logs';
        
        $stats = [
            'timestamp' => current_time('mysql'),
            'pending_actions' => 0,
            'in_progress_actions' => 0,
            'completed_actions' => 0,
            'failed_actions' => 0,
            'total_logs' => 0,
            'old_logs' => 0,
            'sap_pending_actions' => 0
        ];
        
        // Count actions by status
        $action_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$actions_table} GROUP BY status",
            ARRAY_A
        );
        
        foreach ($action_counts as $row) {
            switch ($row['status']) {
                case 'pending':
                    $stats['pending_actions'] = (int) $row['count'];
                    break;
                case 'in-progress':
                    $stats['in_progress_actions'] = (int) $row['count'];
                    break;
                case 'complete':
                    $stats['completed_actions'] = (int) $row['count'];
                    break;
                case 'failed':
                    $stats['failed_actions'] = (int) $row['count'];
                    break;
            }
        }
        
        // Count total logs
        $stats['total_logs'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$logs_table}");
        
        // Count old logs
        $old_log_cutoff = date('Y-m-d H:i:s', strtotime('-' . self::MAX_LOGS_AGE_DAYS . ' days'));
        $stats['old_logs'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$logs_table} WHERE log_date_gmt < %s",
            $old_log_cutoff
        ));
        
        // Count SAP-specific pending actions
        $stats['sap_pending_actions'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$actions_table} 
             WHERE status = 'pending' 
             AND hook LIKE 'sap_%'"
        );
        
        return $stats;
    }
    
    /**
     * Send health alert via Telegram
     */
    private static function send_health_alert($message) {
        // Use the existing Telegram notification system
        if (class_exists('SAP_Background_Processor')) {
            // Access the private telegram method via reflection or create public wrapper
            $token = '8456245551:AAFv07KtOAA4OFTp1y1oGru8Q2egh9CWEJo';
            $chat_id = '5418067438';
            
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $data = [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ];
            
            wp_remote_post($url, [
                'body' => $data,
                'timeout' => 10
            ]);
        }
    }
    
    /**
     * Add custom cron intervals
     */
    public static function add_custom_intervals($schedules) {
        $schedules['every_6_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours')
        ];
        
        return $schedules;
    }
    

}

// Initialize maintenance system
add_action('plugins_loaded', [SAP_Action_Scheduler_Maintenance::class, 'init']);

// Add custom cron intervals
add_filter('cron_schedules', [SAP_Action_Scheduler_Maintenance::class, 'add_custom_intervals']);
