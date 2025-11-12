<?php
/**
 * Plugin Name: SAP B1 Integration
 * Description: סנכרון עם מערכת SAP-B1 של קולנטה. שליפות, דחיפות ועדכונים
 * Version: 1.3
 * Author: Yaron Molho
 */

defined('ABSPATH') or die('No script kiddies please!');

// הגדרות API
define('SAP_API_BASE', 'https://cilapi.emuse.co.il:444/api');
define('SAP_API_USERNAME', 'Respect');
define('SAP_API_PASSWORD', 'Res@135!');





function my_sap_importer_load_files() {
    // וודא ש-WooCommerce פעיל לפני טעינת קבצים שתלויים בו
    if ( class_exists( 'WooCommerce' ) ) {
        // טען את הקוד הקיים של ה-API ועדכון וריאציות
        require_once plugin_dir_path( __FILE__ ) . 'includes/sap-products-import.php';
        
        // טען את אינטגרציית ההזמנו
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-sap-order-integration.php';
        
        // טען את הקוד החדש של יצירת מוצרים מ-SAP
        require_once plugin_dir_path( __FILE__ ) . 'includes/sap-product-create.php';
        
        // טען את מעבד הרקע של SAP
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-sap-background-processor.php';
        
        // טען מערכת תחזוקת Action Scheduler
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-sap-action-scheduler-maintenance.php';
        
        
    } else {
        add_action( 'admin_notices', 'my_sap_importer_woocommerce_fallback_notice' );
    }
}
add_action( 'plugins_loaded', 'my_sap_importer_load_files' );

function my_sap_importer_add_admin_menu() {
    add_menu_page(
        'הגדרות יבוא SAP',        // כותרת עמוד
        'יבוא SAP',              // כותרת תפריט
        'manage_options',         // יכולת נדרשת
        'sap-importer-settings',  // slug של העמוד
        'my_sap_importer_settings_page', // פונקציית הקולבק שתציג את תוכן העמוד
        'dashicons-cloud',        // אייקון (אופציונלי)
        60                        // מיקום בתפריט (אופציונלי)
    );
}
add_action('admin_menu', 'my_sap_importer_add_admin_menu');



/**
 * פונקציה שמציגה את תוכן עמוד ההגדרות
 */
function my_sap_importer_settings_page() {
    ?>
    <div class="wrap">
        <h1>הגדרות יבוא SAP</h1>
        
        <?php
        // Debug Action Scheduler status
        if (class_exists('SAP_Background_Processor')) {
            $is_available = SAP_Background_Processor::is_action_scheduler_available();
            echo '<div style="background: ' . ($is_available ? '#d4edda' : '#f8d7da') . '; padding: 10px; margin: 10px 0; border-radius: 5px;">';
            echo '<strong>Background Processing Status:</strong> ' . ($is_available ? '✅ Available' : '❌ Not Available');
            
            if (isset($_GET['test_scheduler'])) {
                echo '<br><strong>Test Result:</strong> ' . SAP_Background_Processor::test_action_scheduler();
            } else {
                echo ' <a href="' . add_query_arg('test_scheduler', '1') . '">[Test Now]</a>';
            }
            
            if (isset($_GET['force_queue'])) {
                $result = SAP_Background_Processor::force_process_queue();
                echo '<br><strong>Queue Processing:</strong> ' . ($result ? '✅ Executed' : '❌ Failed');
            } else {
                echo ' <a href="' . add_query_arg('force_queue', '1') . '">[Force Process Queue]</a>';
            }
            echo '</div>';
        }
        
        // Show Action Scheduler queue health
        if (class_exists('SAP_Background_Processor')) {
            $queue_health = SAP_Background_Processor::get_queue_health();
            $health_color = $queue_health['status'] === 'healthy' ? '#d4edda' : 
                           ($queue_health['status'] === 'warning' ? '#fff3cd' : '#f8d7da');
            
            echo '<div style="background: ' . $health_color . '; padding: 10px; margin: 10px 0; border-radius: 5px;">';
            echo '<strong>Queue Health:</strong> ' . ucfirst($queue_health['status']);
            echo ' (Pending: ' . $queue_health['total_pending'] . ', SAP: ' . $queue_health['sap_pending'] . ')';
            if (isset($queue_health['runner_status'])) {
                echo '<br><strong>Runner Status:</strong> ' . ucfirst($queue_health['runner_status']);
            }
            echo '</div>';
        }
        
        // Emergency Mode Controls
        $emergency_mode = get_option('sap_emergency_instant_mode', false);
        $emergency_color = $emergency_mode ? '#f8d7da' : '#d4edda';
        echo '<div style="background: ' . $emergency_color . '; padding: 10px; margin: 10px 0; border-radius: 5px;">';
        echo '<strong>Emergency Instant Mode:</strong> ' . ($emergency_mode ? '🚨 ENABLED' : '✅ DISABLED');
        
        if (isset($_GET['toggle_emergency'])) {
            $new_mode = !$emergency_mode;
            update_option('sap_emergency_instant_mode', $new_mode);
            echo '<br><strong>Mode Changed:</strong> ' . ($new_mode ? 'ENABLED' : 'DISABLED');
            echo ' <a href="' . remove_query_arg('toggle_emergency') . '">[Refresh]</a>';
        } else {
            $toggle_text = $emergency_mode ? 'Disable Emergency Mode' : 'Enable Emergency Mode';
            echo ' <a href="' . add_query_arg('toggle_emergency', '1') . '">[' . $toggle_text . ']</a>';
        }
        
        if ($emergency_mode) {
            echo '<br><small>⚠️ Orders will process instantly without Action Scheduler (may slow frontend)</small>';
        } else {
            echo '<br><small>✅ Orders use background processing via Action Scheduler</small>';
        }
        echo '</div>';
        ?>

        <h2>יבוא ועדכון וריאציות מוצרים מ-SAP</h2>
        <form method="post" action="">
            <?php wp_nonce_field('run_sap_variation_import', 'sap_variation_import_nonce'); ?>
            <p>
                לחץ על הכפתור למטה כדי להפעיל יבוא ועדכון וריאציות מוצרים מ-SAP.<br>
                <strong>📱 חדש:</strong> המשימה תרוץ ברקע ותקבל הודעת טלגרם כשתסתיים!
            </p>
            <label for="item_code_filter">קוד פריט ספציפי (אופציונלי):</label>
            <input type="text" id="item_code_filter" name="item_code_filter" placeholder="לדוגמה: 60010">
            <p>
                <input type="submit" name="run_variation_import" class="button button-primary" value="הפעל יבוא וריאציות">
            </p>
        </form>

        <?php
        // טיפול בהפעלת יבוא וריאציות - רקע
        if (isset($_POST['run_variation_import']) && current_user_can('manage_options') && check_admin_referer('run_sap_variation_import', 'sap_variation_import_nonce')) {
            // Check if background processing is available
            if (class_exists('SAP_Background_Processor') && SAP_Background_Processor::is_action_scheduler_available()) {
                $item_code_filter = sanitize_text_field($_POST['item_code_filter']);
                $item_code_filter = !empty($item_code_filter) ? $item_code_filter : null;
                
                // Queue background job
                $job_id = SAP_Background_Processor::queue_stock_update($item_code_filter);
                
                if ($job_id) {
                    // Wait a moment and check if job actually executed
                    sleep(2);
                    
                    // Try to force execution if it didn't run
                    $executed = false;
                    for ($i = 0; $i < 3; $i++) {
                        $result = SAP_Background_Processor::force_process_queue();
                        if ($result) {
                            $executed = true;
                            break;
                        }
                        sleep(1);
                    }
                    
                    if (!$executed) {
                        // Fallback to synchronous if background failed
                        echo '<div class="notice notice-warning"><p>⚠️ Background processing failed, executing synchronously...</p></div>';
                        echo SAP_Background_Processor::execute_synchronous_fallback('stock_update', ['item_code_filter' => $item_code_filter]);
                    } else {
                        // Success - redirect to show success message
                        $redirect_url = add_query_arg([
                            'sap_job_queued' => 'stock_update',
                            'job_id' => $job_id
                        ], $_SERVER['REQUEST_URI']);
                        wp_redirect($redirect_url);
                        exit;
                    }
                } else {
                    echo '<div class="notice notice-error"><p>❌ שגיאה: לא ניתן לתזמן את המשימה. מעבר לביצוע סנכרוני...</p></div>';
                    echo SAP_Background_Processor::execute_synchronous_fallback('stock_update', ['item_code_filter' => $item_code_filter]);
                }
            } else {
                // Fallback to synchronous processing
                echo '<div class="notice notice-warning"><p>⚠️ מעבד הרקע אינו זמין. מריץ סנכרון רגיל (עלול לחסום את האתר)...</p></div>';
                $item_code_to_import = sanitize_text_field($_POST['item_code_filter']);
                if (!empty($item_code_to_import)) {
                    echo sap_update_variations_from_api($item_code_to_import);
                } else {
                    echo sap_run_daily_import_task(); // מפעיל את הטווח הקבוע של הייבוא הלילי
                }
            }
        }
        ?>

        <hr>

        <h2>יבוא מוצרים חדשים מ-SAP (ידני)</h2>
        <form method="post" action="">
            <?php wp_nonce_field('run_sap_manual_product_import', 'sap_manual_product_import_nonce'); ?>
            <p>
                לחץ על הכפתור למטה כדי להפעיל יצירת מוצרים חדשים מ-SAP.<br>
                פעולה זו תיצור מוצרים חדשים בלבד (מקובצים לפי SWW) שעדיין לא קיימים ב-WooCommerce.<br>
                <strong>📱 הודעות:</strong> תקבל הודעת טלגרם עם סיכום התוצאות!
            </p>
            <p>
                <input type="submit" name="run_manual_product_import" class="button button-primary" value="הפעל יבוא מוצרים חדשים">
            </p>
        </form>

        <?php
        // טיפול בהפעלת יבוא מוצרים ידני - DISABLED ACTION SCHEDULER FOR TESTING
        if (isset($_POST['run_manual_product_import']) && current_user_can('manage_options') && check_admin_referer('run_sap_manual_product_import', 'sap_manual_product_import_nonce')) {
            
            // Use Action Scheduler for background processing
            if (function_exists('sap_enqueue_create_products') && function_exists('as_enqueue_async_action')) {
                // Use Action Scheduler directly
                $job_enqueued = sap_enqueue_create_products();
                
                if ($job_enqueued) {
                    echo '<div class="notice notice-success"><p>✅ <strong>משימת יצירת מוצרים הועברה לעיבוד ברקע</strong><br>';
                    echo '📱 תקבל הודעת טלגרם כשהמשימה תסתיים.<br>';
                    echo '⏱️ המשימה תתחיל לרוץ תוך 30 שניות.</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>⚠️ <strong>לא ניתן היה לתזמן משימה ברקע (Action Scheduler לא זמין)</strong><br>';
                    echo 'מפעיל ישירות במקום...</p></div>';
                    echo '<div class="notice notice-info"><p>📦 <strong>מפעיל יצירת מוצרים חדשים מ-SAP</strong> - רק פריטים שלא קיימים ב-WooCommerce יתווספו.</p></div>';
                    echo sap_create_products_from_api();
                }
            } else {
                // Fallback: Direct execution
                echo '<div class="notice notice-warning"><p>⚠️ <strong>Action Scheduler לא זמין</strong> - מפעיל ישירות</p></div>';
                echo '<div class="notice notice-info"><p>📦 <strong>מפעיל יצירת מוצרים חדשים מ-SAP</strong> - רק פריטים שלא קיימים ב-WooCommerce יתווספו.</p></div>';
                echo sap_create_products_from_api();
            }
        }
        ?>


        <hr>

        <h2>שליחה קבוצתית ל-SAP</h2>
        <form method="post" action="">
            <?php wp_nonce_field('bulk_sap_send', 'bulk_sap_send_nonce'); ?>
            <p>
                שלח את כל ההזמנות שבסטטוס "Processing" ועדיין לא נשלחו ל-SAP:
            </p>
            <p>
                <input type="submit" name="bulk_sap_send" class="button button-primary" value="שלח הזמנות לא מסונכרנות ל-SAP" onclick="return confirm('האם אתה בטוח שרוצה לשלוח את כל ההזמנות שלא סונכרנו ל-SAP?')">
            </p>
        </form>

        <?php
        // Handle bulk SAP send
        if (isset($_POST['bulk_sap_send']) && current_user_can('manage_options') && check_admin_referer('bulk_sap_send', 'bulk_sap_send_nonce')) {
            echo '<div style="background: #dbeafe; padding: 15px; margin: 20px 0; border: 1px solid #3b82f6;">';
            echo '<h3>שליחה קבוצתית ל-SAP</h3>';
            
            // Find all processing orders that haven't been synced
            global $wpdb;
            $sync_table = $wpdb->prefix . 'sap_order_sync_log';
            
            // Get all orders with status 'processing'
            $processing_orders = wc_get_orders([
                'status' => 'processing',
                'limit' => -1,
                'return' => 'ids'
            ]);
            
            echo "<p>נמצאו " . count($processing_orders) . " הזמנות בסטטוס 'Processing'</p>";
            
            if (!empty($processing_orders)) {
                // Filter out orders that are already synced or in progress
                $orders_to_sync = [];
                
                foreach ($processing_orders as $order_id) {
                    $sync_record = $wpdb->get_row($wpdb->prepare(
                        "SELECT sync_status FROM $sync_table WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                        $order_id
                    ));
                    
                    // Only include if no sync record OR not successful/in_progress
                    if (!$sync_record || !in_array($sync_record->sync_status, ['success', 'in_progress'])) {
                        $orders_to_sync[] = $order_id;
                    }
                }
                
                echo "<p>מתוכן " . count($orders_to_sync) . " הזמנות טרם סונכרנו עם SAP</p>";
                
                if (!empty($orders_to_sync)) {
                    echo "<p>מעבד הזמנות:</p><ul>";
                    
                    $success_count = 0;
                    $error_count = 0;
                    
                    foreach ($orders_to_sync as $order_id) {
                        echo "<li>הזמנה #{$order_id}: ";
                        
                        try {
                            // Check if background processing is available
                            if (class_exists('SAP_Background_Processor') && SAP_Background_Processor::is_action_scheduler_available()) {
                                $job_id = SAP_Background_Processor::queue_order_integration($order_id, false);
                                
                                if ($job_id) {
                                    echo "<span style='color: green;'>נשלחה לעיבוד ברקע (Job ID: $job_id)</span>";
                                    $success_count++;
                                } else {
                                    echo "<span style='color: red;'>שגיאה בשליחה לעיבוד ברקע</span>";
                                    $error_count++;
                                }
                            } else {
                                // Fallback to synchronous processing (not recommended for bulk)
                                echo "<span style='color: orange;'>מעבד סנכרונית (עלול לקחת זמן)...</span>";
                                sap_handle_order_integration($order_id);
                                echo "<span style='color: green;'> הושלם</span>";
                                $success_count++;
                            }
                        } catch (Exception $e) {
                            echo "<span style='color: red;'>שגיאה: " . $e->getMessage() . "</span>";
                            $error_count++;
                        }
                        
                        echo "</li>";
                        
                        // Flush output for real-time display
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                        
                        // Small delay to prevent overwhelming the system
                        usleep(500000); // 0.5 seconds
                    }
                    
                    echo "</ul>";
                    echo "<p><strong>סיכום:</strong> $success_count הצליחו, $error_count נכשלו</p>";
                    
                    if ($success_count > 0) {
                        echo "<p style='color: green;'>✅ ההזמנות נשלחו לעיבוד. תקבל הודעות טלגרם כאשר העיבוד יושלם.</p>";
                    }
                } else {
                    echo "<p style='color: green;'>✅ כל ההזמנות כבר סונכרנו עם SAP!</p>";
                }
            } else {
                echo "<p>לא נמצאו הזמנות בסטטוס 'Processing'</p>";
            }
            
            echo '</div>';
        }
        ?>

        <hr>

        <h3>שליחה ידנית ל-SAP</h3>
        <form method="post" action="">
            <?php wp_nonce_field('manual_sap_retry', 'manual_sap_retry_nonce'); ?>
            <p>
                כפה שליחה של הזמנה ל-SAP (מעדכן סטטוס ושולח מחדש):
            </p>
            <label for="retry_order_id">מספר הזמנה:</label>
            <input type="number" id="retry_order_id" name="retry_order_id" placeholder="לדוגמה: 68063" min="1">
            <p>
                <input type="submit" name="manual_sap_retry" class="button button-primary" value="שלח ל-SAP עכשיו" onclick="return confirm('האם אתה בטוח שרוצה לשלוח הזמנה זו ל-SAP?')">
            </p>
        </form>

        <?php
        if (isset($_POST['manual_sap_retry']) && current_user_can('manage_options') && check_admin_referer('manual_sap_retry', 'manual_sap_retry_nonce')) {
            $retry_order_id = intval($_POST['retry_order_id']);
            if ($retry_order_id) {
                echo "<div style='background: #dbeafe; padding: 15px; margin: 20px 0; border: 1px solid #3b82f6;'>";
                echo "<h3>שליחה ידנית ל-SAP - הזמנה #$retry_order_id</h3>";
                
                // Reset sync status to allow retry
                global $wpdb;
                $table_name = $wpdb->prefix . 'sap_order_sync_log';
                $deleted = $wpdb->delete($table_name, ['order_id' => $retry_order_id]);
                
                echo "<p>מעדכן סטטוס סנכרון (נמחקו $deleted רשומות) וכופה שליחה מחדש...</p>";
                
                // Force sync - use background processing
                try {
                    // Ensure classes are loaded
                    if (!class_exists('SAP_Sync_Logger')) {
                        require_once plugin_dir_path( __FILE__ ) . 'includes/class-sap-sync-logger.php';
                    }
                    
                    // Try background processing first
                    if (class_exists('SAP_Background_Processor') && SAP_Background_Processor::is_action_scheduler_available()) {
                        $job_id = SAP_Background_Processor::queue_order_integration($retry_order_id, true);
                        
                        if ($job_id) {
                            echo "<p style='color: green;'>✅ הזמנה נשלחה לעיבוד ברקע!</p>";
                            echo "<p><strong>Job ID:</strong> $job_id</p>";
                            echo "<p>אתה תקבל הודעה בטלגרם כאשר העיבוד יושלם.</p>";
                            $output = '';
                        } else {
                            throw new Exception('Failed to queue background job');
                        }
                    } else {
                        // Fallback to synchronous processing
                        echo "<p style='color: orange;'>⚠️ מעבד הרקע אינו זמין - מעבד סנכרונית...</p>";
                    ob_start();
                    sap_handle_order_integration($retry_order_id);
                    $output = ob_get_clean();
                    }
                    
                    // Only show completion status for synchronous processing
                    if (!empty($output)) {
                    echo "<p style='color: green;'>✅ תהליך שליחה הושלם!</p>";
                    
                    // Check final status
                    $final_status = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $table_name WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                        $retry_order_id
                    ));
                    
                    if ($final_status) {
                        if ($final_status->sync_status === 'success') {
                            echo "<p style='color: green;'>✅ הזמנה נשלחה ל-SAP בהצלחה!</p>";
                            echo "<p><strong>SAP DocEntry:</strong> " . $final_status->order_doc_entry . "</p>";
                        } else {
                            echo "<p style='color: red;'>❌ שליחה נכשלה: " . $final_status->error_message . "</p>";
                            }
                        }
                    }
                    
                    if ($output) {
                        echo "<details><summary>פרטים טכניים</summary><pre>$output</pre></details>";
                    }
                    
                } catch (Exception $e) {
                    echo "<p style='color: red;'>❌ שגיאה בשליחה: " . $e->getMessage() . "</p>";
                }
                
                echo "</div>";
            }
        }
        ?>


        <hr>



        <?php
        // אופציונלי: הצג את זמן הריצה הבא של הקרונים
        echo '<h3>סטטוס קרונים יומיים:</h3>';
        
        // קרון ייבוא וריאציות
        $next_variations = wp_next_scheduled('sap_daily_import_event');
        if ($next_variations) {
            echo '<p>🔄 ייבוא וריאציות מ-SAP: <strong>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_variations) . '</strong> (02:00)</p>';
        } else {
            echo '<p>❌ ייבוא וריאציות: לא מתוזמן</p>';
        }
        
        // קרון יצירת מוצרים שבועי
        if (function_exists('as_next_scheduled_action')) {
            $next_product_creation = as_next_scheduled_action('sap_weekly_product_creation_action');
            if ($next_product_creation) {
                echo '<p>🔄 יצירת מוצרים שבועית מ-SAP: <strong>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_product_creation) . '</strong> (ראשון 03:00)</p>';
            } else {
                echo '<p>❌ יצירת מוצרים שבועית: לא מתוזמן</p>';
            }
        }
        ?>

    </div>
    <?php
}

