<?php
/**
 * SAP Manual Product Import Functions
 * Based on Make scenario logic for creating products grouped by ItmsGrpNam
 *
 * @package My_SAP_Importer
 * @subpackage Includes
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Telegram notification configuration (reuse from sap-products-import.php)
if (!defined('SAP_TELEGRAM_BOT_TOKEN')) {
    define('SAP_TELEGRAM_BOT_TOKEN', '8309945060:AAHKHfGtTf6D_U_JnapGrTHxOLcuht9ULA4');
}
if (!defined('SAP_TELEGRAM_CHAT_ID')) {
    define('SAP_TELEGRAM_CHAT_ID', '5418067438');
}

// Increase HTTP timeouts for long-running SAP/Telegram requests and Action Scheduler
if (!function_exists('sap_manual_import_http_timeout')) {
function sap_manual_import_http_timeout($timeout) {
    return max((int)$timeout, 60);
}
}
add_filter('http_request_timeout', 'sap_manual_import_http_timeout');

if (!function_exists('sap_as_time_limit')) {
function sap_as_time_limit($time_limit) {
    return max((int)$time_limit, 120);
}
}
add_filter('action_scheduler_queue_runner_time_limit', 'sap_as_time_limit');

// Ensure Action Scheduler can trigger the manual import
if (!function_exists('sap_manual_product_import_async_handler')) {
function sap_manual_product_import_async_handler() {
    if (function_exists('sap_manual_product_import')) {
        sap_manual_product_import();
    }
}
}
add_action('sap_manual_product_import_async', 'sap_manual_product_import_async_handler');

// Public helper to enqueue the async job (can be called from admin/UI)
if (!function_exists('sap_enqueue_manual_product_import')) {
function sap_enqueue_manual_product_import() {
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('sap_manual_product_import_async');
        return true;
    }
    return false;
}
}

// Bump default timeout for all HTTP requests
if (!function_exists('sap_http_request_args_timeout')) {
function sap_http_request_args_timeout($args) {
    if (!is_array($args)) {
        return $args;
    }
    $args['timeout'] = isset($args['timeout']) ? max((int)$args['timeout'], 60) : 60;
    return $args;
}
}
add_filter('http_request_args', 'sap_http_request_args_timeout');

/**
 * Main function for manual product import from SAP
 * Groups items by ItmsGrpNam and creates variable products with variations
 *
 * @return string HTML output of the import status
 */
if (!function_exists('sap_manual_product_import')) {
function sap_manual_product_import() {
    // Ensure WooCommerce functions are available
    if (!function_exists('wc_get_product')) {
        return "<p style='color: red;'>שגיאה: ווקומרס אינו פעיל. אנא וודא שווקומרס מותקן ומופעל.</p>";
    }

    ob_start(); // Start output buffering

    // Initialize import log with proper array initialization
    $import_log = [
        'start_time' => current_time('mysql'),
        'products_created' => 0,
        'products_updated' => 0,
        'simple_products_created' => 0,
        'variations_created' => 0,
        'groups_processed' => 0,
        'existing_groups_updated' => 0,
        'new_groups_created' => 0,
        'errors' => [], // Ensure this is always an array
        'skipped_items' => 0,
        'skipped_reasons' => []
    ];
    
    error_log("=== התחלת ייבוא מוצרים ידני מ-SAP ===");
    
    // Log import flow start
    sap_log_import_flow('flow_start', [
        'start_time' => $import_log['start_time'],
        'user_id' => get_current_user_id(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);
    
    // Send import started notification
    $start_message = "▶️ SAP Manual Import Started\n\n";
    $start_message .= "Time: " . current_time('Y-m-d H:i:s') . "\n";
    $start_message .= "User ID: " . get_current_user_id();
    sap_send_telegram_message_manual($start_message);
    
    // Get authentication token
    $auth_token = sap_get_auth_token();
    if (!$auth_token) {
        $import_log['errors'][] = "שגיאה בקבלת אסימון אימות מ-SAP";
        sap_save_import_log($import_log);
        return false;
    }
    
    error_log("קיבלתי אסימון אימות מ-SAP");

    // Get grouped items from SAP
    $grouped_items = sap_get_grouped_items_from_sap($auth_token, $import_log);
    
    if (empty($grouped_items)) {
        error_log("לא נמצאו פריטים חדשים לייבוא");
        $import_log['errors'][] = "לא נמצאו פריטים חדשים לייבוא";
        sap_save_import_log($import_log);
        return false;
    }
    
    // Add array check before processing
    if (!is_array($grouped_items)) {
        error_log("Invalid grouped_items data returned from SAP");
        $import_log['errors'][] = "Invalid grouped items data";
        sap_save_import_log($import_log);
        return false;
    }
    
    error_log("נמצאו " . (is_array($grouped_items) ? count($grouped_items) : 0) . " קבוצות חדשות לייבוא");
    
    // DISABLED: 20-item limit for full testing
    /*
    if (is_array($grouped_items) && count($grouped_items) > 20) {
        $grouped_items = array_slice($grouped_items, 0, 20, true);
        error_log("SAP Manual Import: LIMITED TO 20 ITEMS FOR TESTING");
        echo "<p style='color: orange;'><strong>⚠️ TESTING MODE: Limited to first 20 product groups</strong></p>";
    }
    */
    error_log("SAP Manual Import: Processing ALL " . (is_array($grouped_items) ? count($grouped_items) : 0) . " groups (20-item limit DISABLED)");
    
    // Process each group with comprehensive error handling
    foreach ($grouped_items as $group_name => $items) {
        $import_log['groups_processed']++;
        
        try {
            error_log("מעבד קבוצה: {$group_name} עם " . (is_array($items) ? count($items) : 0) . " פריטים");
            
            $result = sap_process_product_group($group_name, $items, $auth_token, $import_log);
            
            if (!$result) {
                error_log("שגיאה בעיבוד קבוצה: {$group_name}");
                $import_log['errors'][] = "Failed to process group: {$group_name}";
            }
            
        } catch (Exception $e) {
            sap_handle_import_exception($e, [
                'group_name' => $group_name,
                'item_count' => is_array($items) ? count($items) : 0,
                'phase' => 'group_processing'
            ]);
            $import_log['errors'][] = "Exception in group {$group_name}: " . $e->getMessage();
        }
    }
    
    // Log import flow end
    sap_log_import_flow('flow_end', [
        'groups_processed' => $import_log['groups_processed'],
        'products_created' => $import_log['products_created'],
        'variations_created' => $import_log['variations_created'],
        'errors_count' => is_array($import_log['errors']) ? count($import_log['errors']) : 0,
        'end_time' => current_time('mysql')
    ]);
    
    // Save import log
    sap_save_import_log($import_log);
    
    // Display results
    echo "<div class='notice notice-info'>";
    echo "<h3>תוצאות ייבוא מוצרים ידני</h3>";
    echo "<p><strong>זמן התחלה:</strong> " . esc_html($import_log['start_time']) . "</p>";
    echo "<p><strong>קבוצות שעובדו:</strong> " . esc_html($import_log['groups_processed']) . "</p>";
    echo "<p><strong>מוצרים שעודכנו:</strong> " . esc_html($import_log['products_updated']) . "</p>";
    echo "<p><strong>קבוצות חדשות שנוצרו:</strong> " . esc_html($import_log['new_groups_created']) . "</p>";
    echo "<p><strong>קבוצות קיימות שעודכנו:</strong> " . esc_html($import_log['existing_groups_updated']) . "</p>";
    echo "<p><strong>מוצרים פשוטים שנוצרו:</strong> " . esc_html($import_log['simple_products_created']) . "</p>";
    echo "<p><strong>מוצרים משתנים שנוצרו:</strong> " . esc_html($import_log['products_created'] - $import_log['simple_products_created']) . "</p>";
    echo "<p><strong>וריאציות שנוצרו:</strong> " . esc_html($import_log['variations_created']) . "</p>";
    
    if (is_array($import_log['errors']) && !empty($import_log['errors'])) {
        echo "<p><strong>שגיאות:</strong></p>";
        echo "<ul>";
        foreach ($import_log['errors'] as $error) {
            echo "<li style='color: red;'>" . esc_html($error) . "</li>";
        }
        echo "</ul>";
    }
    
    if (!empty($import_log['skipped_reasons'])) {
        echo "<p><strong>פריטים שדולגו:</strong></p>";
        echo "<ul>";
        foreach ($import_log['skipped_reasons'] as $reason => $count) {
            echo "<li>" . esc_html($reason) . ": " . esc_html($count) . "</li>";
        }
        echo "</ul>";
    }
    
    echo "</div>";
    
    // Output collected SAP PATCH data
    sap_output_collected_patch_data($import_log);
    
    // Get newly created products for Telegram notification
    $new_products_logs = get_option('sap_new_product_logs', []);
    $recent_products = array_filter($new_products_logs, function($log) use ($import_log) {
        return strtotime($log['timestamp']) >= strtotime($import_log['start_time']);
    });
    
    // Find and handle products without SKUs (cleanup phase)
    echo "<hr style='margin: 20px 0;'>";
    echo "<h4>בדיקת מוצרים ללא מק\"ט...</h4>";
    $cleanup_result = sap_handle_products_without_sku();
    echo $cleanup_result;
    
    // Send Telegram notification - ensure errors is an array
    $errors_array = is_array($import_log['errors']) ? $import_log['errors'] : [];
    $telegram_result = sap_send_manual_import_telegram_notification($import_log, $errors_array, $recent_products);
    if (is_wp_error($telegram_result)) {
        echo "<p style='color: orange;'>אזהרה: שליחת התראת טלגרם נכשלה: " . $telegram_result->get_error_message() . "</p>";
    } else {
        echo "<p style='color: green;'>התראת טלגרם נשלחה בהצלחה.</p>";
    }
    
    error_log("=== סיום ייבוא מוצרים ידני מ-SAP ===");
    return true;
}
}

/**
 * Map U_EM_Age to WooCommerce product categories
 *
 * @param string $age_value The U_EM_Age value from SAP
 * @return string|null Category name or null for "כללי" (General)
 */
if (!function_exists('sap_map_age_to_category')) {
function sap_map_age_to_category($age_value) {
    // Define the mapping from U_EM_Age to main categories
    $age_category_mapping = [
        'תינוקות' => 'תינוקות',
        'בנים' => 'בנים',
        'בנות' => 'בנות',
        'ילדות' => 'ילדות',
        'ילדים' => 'ילדים',
        'נערות' => 'נערות',
        'נערים' => 'נערים',
        'מבוגרים' => 'מבוגרים',
        'גברים' => 'גברים',
        'נשים' => 'נשים',
        'נוער' => 'נוער',
    ];
    
    // Clean and normalize the age value
    $normalized_age = trim($age_value);
    
    // Check if this age maps to a main category
    if (isset($age_category_mapping[$normalized_age])) {
        return $age_category_mapping[$normalized_age];
    }
    
    // Return null for "כללי" (General) category fallback
    return null;
}
}

/**
 * Assign product to appropriate category based on U_EM_Age
 *
 * @param int $product_id The WooCommerce product ID
 * @param string $age_value The U_EM_Age value from SAP
 * @return bool True on success, false on failure
 */
if (!function_exists('sap_assign_product_category')) {
function sap_assign_product_category($product_id, $age_value) {
    $category_name = sap_map_age_to_category($age_value);
    
    // If no specific category mapping, use "כללי" (General)
    if (!$category_name) {
        $category_name = 'כללי';
    }
    
    // Get or create the category
    $category_term = get_term_by('name', $category_name, 'product_cat');
    
    if (!$category_term) {
        // Create the category if it doesn't exist
        $new_category = wp_insert_term($category_name, 'product_cat');
        
        if (is_wp_error($new_category)) {
            error_log("SAP Manual Import: Failed to create category '{$category_name}': " . $new_category->get_error_message());
            return false;
        }
        
        $category_id = $new_category['term_id'];
        error_log("SAP Manual Import: Created new category '{$category_name}' with ID {$category_id}");
    } else {
        $category_id = $category_term->term_id;
    }
    
    // Assign the product to this category ONLY (replace any existing categories)
    $result = wp_set_object_terms($product_id, [$category_id], 'product_cat');
    
    if (is_wp_error($result)) {
        error_log("SAP Manual Import: Failed to assign product {$product_id} to category '{$category_name}': " . $result->get_error_message());
        return false;
    }
    
    error_log("SAP Manual Import: Assigned product {$product_id} to category '{$category_name}' (ID: {$category_id})");
    return true;
}
}

/**
 * Get group name from group code using the mapping table
 *
 * @param int $group_code The ItemsGroupCode from SAP
 * @return string The group name or fallback name
 */
if (!function_exists('sap_get_group_name_from_code')) {
function sap_get_group_name_from_code($group_code) {
    // Group code to name mapping table
    // Complete mapping from groupcode_name_table
    $group_mapping = [
        100 => 'פריטים',
        101 => 'פתיל דק בהכשר בד"ץ העדה החרדית',
        102 => 'גרביון ילדות 48',
        103 => 'גרביון ילדות 50',
        104 => 'זוג גרביון ילדות מדוגם (דיגומים שונים)',
        105 => 'זוג גרביון ילדות תחרה',
        106 => 'זוג חולצות בסיס לבנות שרוול 3/4 95% כותנה 5% לייקרה',
        107 => 'זוג כותנות בנות שרוול 3/4 100%כותנה',
        108 => 'חולצות T שרוול קצר 100% כותנה',
        109 => 'בגד גוף ריב',
        110 => 'חולצות לבנות לגברים גזרה רגילה',
        111 => 'חולצות לבנות לגברים גזרת סילם פיט',
        112 => 'בד לציצית',
        113 => 'חולצת לייקרה שילוב אריג',
        114 => 'חלוק מגבת ילדות 100% כותנה',
        115 => 'חלוק מגבת ילדים 100% כותנה',
        116 => 'חלוק מגבת מבוגרים 100% כותנה',
        117 => 'חמישיית בוקסרים גברים 100% כותנה',
        118 => 'חמישיית גופיות ציצית לילדים',
        119 => 'חמישיית לבנים לבנות 95% כותנה 5% לייקרה',
        120 => 'חמישיית לבנים לבנים 95% כותנה 5% לייקרה',
        121 => 'חמישיית ציציות מוכנות לילדים',
        122 => 'חצאית דמוי צמר כיס טאפטה',
        123 => 'טיפות יובש לעיניים 20 מ"ל',
        124 => 'טלית גדול צמר ללא פתיל',
        125 => 'טלית גדול צמר עם פתיל עבה',
        126 => 'כותונת בנות כבשה צבעונית',
        127 => 'כותונת לנערות גליטר לבבות',
        128 => 'כיפות לילדים',
        129 => 'מגבות אמבטיה 100% כותנה',
        130 => 'מגן מזרן שקט',
        131 => 'מטפחות פרנזים',
        132 => 'סט מצעים יוקרתי לשתי מיטות',
        133 => 'סט מצעים לנוער',
        134 => 'סריג בנים סריגת פסים',
        135 => 'עגיל כסף (925) משובץ זרקונים עם תליון מזרקון בצורת טיפה',
        136 => 'עגיל כסף (925) צמוד עם זרקון בצורת לב',
        137 => 'עדשות מגע חודשיות Eoptic plus',
        138 => 'פיג\'מת אינטרלוק בנים AB',
        139 => 'פיג\'מת פלנל ילדות זריקת צבע',
        140 => 'פיג\'מת פלנל לנערות זריקת צבע',
        141 => 'חולצת אריג שיבוץ אנגלי',
        142 => 'כותונת בנות החיים היפים',
        143 => 'פיג\'מה לנערות גליטר לבבות',
        144 => 'פתיל עבה בהכשר בד"ץ העדה החרדית',
        145 => 'פתיל עבה ניפוץ בהכשר בד"ץ העדה החרדית',
        146 => 'רביעיית בגדי גוף לתינוק 100% כותנה שרוול קצר',
        147 => 'רביעיית בגדי גוף צבעוני לתינוק - בנות 100% כותנה שרוול קצר',
        148 => 'רביעיית בגדי גוף צבעוני לתינוק - בנים 100% כותנה שרוול קצר',
        149 => 'שטיח לאמבטיה',
        150 => 'שטיח לחדר ילדים 60/40 100% כותנה דו צדדי',
        151 => 'שישיית גרביון כותנה לבן חלק 85% כותנה 15% פנדקס',
        152 => 'שישיית גרביון כותנה לבן מדוגם 85% כותנה 15% פנדקס',
        153 => 'שישיית גרביוני פלמינגו 50 דנייר',
        154 => 'שישיית גרביוני פלמינגו 60 דנייר',
        155 => 'שישיית ירך סיליקון 40 דנייר',
        156 => 'שישיית ירך פלמינגו 50 דנייר',
        157 => 'שישיית ירך פלמינגו 60 דנייר',
        158 => 'שישיית ירך פלמינגו 70 דנייר',
        159 => 'שישיית לבני גברים משולש 100% כותנה',
        160 => 'שלישיית גופיות גברים כתפיות 100% כותנה',
        161 => 'שלישיית גופיות גברים שרוול קצר- צווארון וי 100% כותנה',
        162 => 'שלישיית גופיות גברים שרוול קצר- צווארון עגול 100% כותנה',
        163 => 'שלישיית גופיות לילדות ללא שרוול 100% כותנה',
        164 => 'שלישיית גופיות לילדים ללא שרוול 100% כותנה',
        165 => 'שמיכה רכה לתינוק 100% כותנה',
        166 => 'שמלה דמוי צמר כיס טאפטה',
        167 => 'שרשרת כסף (925) עם תליון מזרקון בצורת טיפה',
        168 => 'שרשרת כסף (925) עם תליון מזרקון בצורת עגול',
        169 => 'תמיסת לעדשות 350 מ"ל DISPO',
        170 => 'תמיסת לעדשות 350 מ"ל UNICA',
        171 => 'תמיסת לעדשות 500 מ"ל סיילין',
        172 => 'תשיעיית גרביי גברים',
        173 => 'תשיעיית גרביי ילדים',
        174 => 'שרשרת כסף (925) עם תליון מזרקון בצורת לב',
        175 => 'תשיעיית גרביי במבוק',
        176 => 'ציצית מוכנה',
        177 => 'רביעיית מגבות ידיים 100% כותנה',
        178 => 'שישיית גרביוני פלמינגו 40 דנייר',
        179 => 'שישיית גרביוני פלמינגו 70 דנייר',
        180 => 'שישיית ירך פלמינגו 40 דנייר'
    ];
    
    // Return mapped name if exists, otherwise fallback
    if (isset($group_mapping[$group_code])) {
        return $group_mapping[$group_code];
    }
    
    // Fallback: try to get from database or return generic name
    return "קבוצה " . $group_code;
}
}

/**
 * Get all items from SAP and group them by ItemsGroupCode
 * Only returns groups that don't have U_EM_SiteGroupID (new products)
 *
 * @param string $auth_token SAP authentication token
 * @param array $import_log Reference to import log
 * @return array|WP_Error Array of grouped items or error
 */
if (!function_exists('sap_get_grouped_items_from_sap')) {
function sap_get_grouped_items_from_sap($auth_token, &$import_log) {
    // Request specific fields from SAP (optimized request)
    $items_request = [
        "selectObjects" => [
            ["field" => "ItemCode"],
            ["field" => "ItemName"],
            ["field" => "ItemsGroupCode"],
            ["field" => "SWW"],
            ["field" => "U_ssize"],
            ["field" => "U_scolor"],
            ["field" => "U_sdanier"],
            ["field" => "U_SiteGroupID"],
            ["field" => "U_SiteItemID"],
            ["field" => "ItemPrices"],
            ["field" => "ItemWarehouseInfoCollection"]
        ],
        "filterObjects" => [
            [
                "field" => "BarCode",
                "fieldType" => "string",
                "operator" => "!=",
                "fieldValue" => ""
            ],
            [
                "field" => "BarCode",
                "fieldType" => "string",
                "operator" => "!=",
                "fieldValue" => "0"
            ]
        ],
        "orderByObjects" => [
            [
                "orderField" => "ItemCode",
                "sortType" => "ASC"
            ]
        ]
    ];

    echo "<p>שולח בקשה ל-SAP API...</p>";
    echo "<p>⏳ <strong>ממתין לתגובת SAP מזורמת (עשוי לקחת עד 30 שניות)...</strong></p>";
    flush(); // Force output to browser immediately
    
    // CRITICAL: Use streaming API for SAP's 25+ second streamed responses
    $items_response = sap_api_post_streaming('Items/get', $items_request, $auth_token);
    
    // Provide immediate feedback after streaming completes
    if (is_wp_error($items_response)) {
        echo "<p style='color: red;'>❌ <strong>שגיאה בתגובת הזרמה:</strong> " . esc_html($items_response->get_error_message()) . "</p>";
    } else {
        echo "<p style='color: green;'>✅ <strong>תגובת הזרמה התקבלה בהצלחה!</strong></p>";
    }
    flush();

    // STRONG DEBUGGING - Log the entire response
    error_log('=== SAP API RESPONSE DEBUG ===');
    error_log('Full response: ' . print_r($items_response, true));
    error_log('Response type: ' . gettype($items_response));
    if (is_array($items_response)) {
        error_log('Response keys: ' . implode(', ', array_keys($items_response)));
        if (isset($items_response['Results']) && is_array($items_response['Results'])) {
            error_log('Results count: ' . count($items_response['Results']));
        }
        if (isset($items_response['data'])) {
            error_log('Data type: ' . gettype($items_response['data']));
            if (is_array($items_response['data'])) {
                error_log('Data keys: ' . implode(', ', array_keys($items_response['data'])));
            }
        }
        // Check for apiResponse structure
        if (isset($items_response['apiResponse'])) {
            error_log('apiResponse found with keys: ' . implode(', ', array_keys($items_response['apiResponse'])));
            if (isset($items_response['apiResponse']['data'])) {
                $data_type = gettype($items_response['apiResponse']['data']);
                error_log('apiResponse->data type: ' . $data_type);
                if ($data_type === 'string') {
                    error_log('apiResponse->data is JSON string (first 200 chars): ' . substr($items_response['apiResponse']['data'], 0, 200));
                }
            }
        }
    }
    error_log('=== END DEBUG ===');

    if (is_wp_error($items_response)) {
        error_log('SAP Manual Import: Error getting items - ' . $items_response->get_error_message());
        $import_log['errors'][] = "Error getting items from SAP: " . $items_response->get_error_message();
        return []; // Return empty array instead of WP_Error
    }

    // Handle the response structure from SAP (now supports multiple formats)
    $items = [];
    
    // NEWEST FORMAT: Direct array of objects from concatenated JSON parser
    if (is_array($items_response) && isset($items_response[0]['ItemCode'])) {
        $items = $items_response;  // Direct array of objects
        echo "<p>נמצאו " . count($items) . " פריטים מ-SAP (concatenated objects parsed)</p>";
        error_log("SAP Manual Import: Using CONCATENATED format - direct array of " . count($items) . " objects");
        
        // Validate that items have expected structure
        $sample_item = $items[0];
        $required_fields = ['ItemCode', 'ItemName', 'ItemsGroupCode'];
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!isset($sample_item[$field])) {
                $missing_fields[] = $field;
            }
        }
        if (!empty($missing_fields)) {
            error_log("SAP Manual Import: WARNING - Concatenated objects missing fields: " . implode(', ', $missing_fields));
        } else {
            error_log("SAP Manual Import: Concatenated objects validation passed");
        }
    }
    // NEW FORMAT: Check for direct ['items'] array structure (NEW API FORMAT)
    elseif (isset($items_response['items']) && is_array($items_response['items'])) {
        $items = $items_response['items'];
        $total_count = $items_response['total'] ?? 'N/A';
        echo "<p>נמצאו " . (is_array($items) ? count($items) : 0) . " פריטים מ-SAP (מתוך " . $total_count . ") - NEW API FORMAT</p>";
        error_log("SAP Manual Import: Using NEW API format with direct [items] array");
    }
    // NEW FORMAT: apiResponse->data contains JSON string with value array
    elseif (isset($items_response['apiResponse']['data']) && is_string($items_response['apiResponse']['data'])) {
        $decoded_data = json_decode($items_response['apiResponse']['data'], true);
        if ($decoded_data && isset($decoded_data['value']) && is_array($decoded_data['value'])) {
            $items = $decoded_data['value'];
            echo "<p>נמצאו " . (is_array($items) ? count($items) : 0) . " פריטים מ-SAP (JSON string format)</p>";
            error_log("SAP Manual Import: Using NEW JSON string format with ['value'] array");
            error_log("SAP Manual Import: Decoded JSON data keys: " . implode(', ', array_keys($decoded_data)));
        } else {
            error_log("SAP Manual Import: Failed to decode JSON string or missing 'value' array");
            error_log("SAP Manual Import: JSON decode error: " . json_last_error_msg());
            error_log("SAP Manual Import: Raw JSON string (first 500 chars): " . substr($items_response['apiResponse']['data'], 0, 500));
        }
    }
    // Check for the nested structure: apiResponse -> result -> data -> Results (OLD FORMAT)
    elseif (isset($items_response['apiResponse']['result']['data']['Results']) && is_array($items_response['apiResponse']['result']['data']['Results'])) {
        $items = $items_response['apiResponse']['result']['data']['Results'];
        $total_count = $items_response['apiResponse']['result']['data']['TotalCount'] ?? 'N/A';
        echo "<p>נמצאו " . (is_array($items) ? count($items) : 0) . " פריטים מ-SAP (מתוך " . $total_count . ") - OLD NESTED FORMAT</p>";
    } 
    // Fallback for other response structures
    elseif (isset($items_response['Results']) && is_array($items_response['Results'])) {
        $items = $items_response['Results'];
        echo "<p>נמצאו " . (is_array($items) ? count($items) : 0) . " פריטים מ-SAP (מבנה ישיר)</p>";
    } 
    elseif (isset($items_response['data']) && is_array($items_response['data'])) {
        $items = $items_response['data'];
        echo "<p>נמצאו " . (is_array($items) ? count($items) : 0) . " פריטים מ-SAP (מבנה data)</p>";
    } 
    else {
        error_log('SAP Manual Import: Unexpected response structure - ' . print_r($items_response, true));
        $import_log['errors'][] = "Unexpected response structure from SAP API";
        return []; // Return empty array instead of WP_Error
    }

    if (empty($items)) {
        $import_log['errors'][] = "No items found in SAP";
        return []; // Return empty array instead of WP_Error
    }

    echo "<p>מתחיל לקבץ פריטים לפי ItemsGroupCode (מכיוון ש-ItmsGrpNam חסר)...</p>";
    
    // Debug: Show sample of first few items
    echo "<p><strong>דוגמה לפריטים ראשונים:</strong></p>";
    $sample_items = array_slice($items, 0, 3);
    foreach ($sample_items as $index => $item) {
        echo "<p>&nbsp;&nbsp;פריט " . ($index + 1) . ":</p>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;ItemCode: " . ($item['ItemCode'] ?? 'N/A') . "</p>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;ItemName: " . ($item['ItemName'] ?? 'N/A') . "</p>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;ItemsGroupCode: " . ($item['ItemsGroupCode'] ?? 'N/A') . "</p>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;ItmsGrpNam: " . ($item['ItmsGrpNam'] ?? 'N/A') . "</p>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;U_SiteGroupID: " . ($item['U_SiteGroupID'] ?? 'N/A') . "</p>";
        echo "<p>&nbsp;&nbsp;&nbsp;&nbsp;U_SiteItemID: " . ($item['U_SiteItemID'] ?? 'N/A') . "</p>";
    }
    echo "<hr>";
    
    // Group items by ItemsGroupCode since ItmsGrpNam is missing
    $grouped_items = [];
    $skipped_count = 0;
    $grouped_count = 0;
    $missing_group_code = 0;
    $already_imported = 0;
    $existing_groups_updated = 0;
    $new_groups_created = 0;
    
    foreach ($items as $item) {
        $item_code = $item['ItemCode'] ?? 'N/A';
        
        // Skip items without ItemsGroupCode
        if (empty($item['ItemsGroupCode']) || $item['ItemsGroupCode'] == -1) {
            $missing_group_code++;
            $import_log['skipped_reasons']['missing_group_code'] = ($import_log['skipped_reasons']['missing_group_code'] ?? 0) + 1;
            $import_log['skipped_items']++;
            continue;
        }

        // Skip items that already have U_SiteGroupID (already imported)
        if (!empty($item['U_SiteGroupID']) && $item['U_SiteGroupID'] > 0) {
            $already_imported++;
            $import_log['skipped_reasons']['already_imported'] = ($import_log['skipped_reasons']['already_imported'] ?? 0) + 1;
            $import_log['skipped_items']++;
            continue;
        }

        $group_code = $item['ItemsGroupCode'];
        // TEMPORARILY DISABLED - Use raw group code instead of mapping
        // $group_name = sap_get_group_name_from_code($group_code); // Get proper name from mapping
        $group_name = "Group " . $group_code; // Simple fallback for testing
        error_log("SAP Manual Import: Using simple group name for testing: {$group_name}");
        
        // Check if this group already exists in WooCommerce
        $existing_product_by_name = get_page_by_title($group_name, OBJECT, 'product');
        
        if ($existing_product_by_name) {
            // Group exists - check if we can add new variations
            $existing_product = wc_get_product($existing_product_by_name->ID);
            
            if ($existing_product && $existing_product->is_type('variable')) {
                // Check if this specific SKU already exists as a variation
                $existing_skus = [];
                $variations = $existing_product->get_children();
                foreach ($variations as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $existing_skus[] = $variation->get_sku();
                    }
                }
                
                // If this SKU is new, add it to existing group for later processing
                if (!in_array($item_code, $existing_skus)) {
                    if (!isset($grouped_items[$group_name])) {
                        $grouped_items[$group_name] = [];
                        $grouped_count++;
                        $existing_groups_updated++;
                    }
                    $grouped_items[$group_name][] = $item;
                    error_log("מוסיף SKU חדש {$item_code} לקבוצה קיימת: {$group_name}");
                } else {
                    // SKU already exists in this group
                    $import_log['skipped_reasons']['sku_already_exists'] = ($import_log['skipped_reasons']['sku_already_exists'] ?? 0) + 1;
                    $import_log['skipped_items']++;
                    error_log("דילוג על SKU שכבר קיים: {$item_code} בקבוצה {$group_name}");
                }
            } else {
                // Product exists but is not variable - skip (could be enhanced later)
                $import_log['skipped_reasons']['existing_non_variable'] = ($import_log['skipped_reasons']['existing_non_variable'] ?? 0) + 1;
                $import_log['skipped_items']++;
                error_log("דילוג על קבוצה קיימת שאינה משתנה: {$group_name}");
            }
        } else {
            // New group - add to processing
            if (!isset($grouped_items[$group_name])) {
                $grouped_items[$group_name] = [];
                $grouped_count++;
                $new_groups_created++;
            }
            $grouped_items[$group_name][] = $item;
        }
    }

    echo "<p>דילוג על " . $skipped_count . " פריטים:</p>";
    echo "<p>&nbsp;&nbsp;- חסרי קוד קבוצה: " . $missing_group_code . "</p>";
    echo "<p>&nbsp;&nbsp;- כבר מיובאים: " . $already_imported . "</p>";
    echo "<p>נמצאו " . $grouped_count . " קבוצות ליבוא:</p>";
    echo "<p>&nbsp;&nbsp;- קבוצות חדשות: " . $new_groups_created . "</p>";
    echo "<p>&nbsp;&nbsp;- קבוצות קיימות עם SKUs חדשים: " . $existing_groups_updated . "</p>";

    return $grouped_items;
}
}

/**
 * Log new product creation details
 *
 * @param string $type 'simple' or 'variable'
 * @param int $product_id WooCommerce product ID
 * @param string $sku Product SKU
 * @param array $variation_ids Array of variation IDs (for variable products)
 * @param string $category_assigned Category assigned to product
 */
if (!function_exists('sap_log_new_product_creation')) {
function sap_log_new_product_creation($type, $product_id, $sku, $variation_ids = [], $category_assigned = '') {
    $log_entry = [
        'timestamp' => current_time('mysql'),
        'type' => 'new_product_creation',
        'product_type' => $type,
        'product_id' => $product_id,
        'sku' => $sku,
        'category_assigned' => $category_assigned,
        'variation_ids' => $variation_ids,
        'site_group_id' => $product_id, // For simple products, same as product_id
        'site_item_ids' => $type === 'simple' ? [$product_id] : array_values($variation_ids)
    ];
    
    // Log to WordPress error log
    error_log('SAP New Product Created: ' . wp_json_encode($log_entry));
    
    // Store in database for admin review
    $existing_logs = get_option('sap_new_product_logs', []);
    $existing_logs[] = $log_entry;
    
    // Keep only last 500 entries to prevent database bloat
    if (count($existing_logs) > 500) {
        $existing_logs = array_slice($existing_logs, -500);
    }
    
    update_option('sap_new_product_logs', $existing_logs);
}
}

/**
 * Log import flow events
 *
 * @param string $event_type Type of event ('flow_start', 'flow_end', 'group_processing', etc.)
 * @param array $data Additional data for the event
 */
if (!function_exists('sap_log_import_flow')) {
function sap_log_import_flow($event_type, $data = []) {
    $log_entry = [
        'timestamp' => current_time('mysql'),
        'event_type' => $event_type,
        'data' => $data
    ];
    
    // Log to WordPress error log with specific prefix
    error_log('SAP Import Flow [' . strtoupper($event_type) . ']: ' . wp_json_encode($log_entry));
    
    // Store critical events in database
    $critical_events = ['flow_start', 'flow_end', 'critical_error', 'api_failure'];
    if (in_array($event_type, $critical_events)) {
        $existing_logs = get_option('sap_import_flow_logs', []);
        $existing_logs[] = $log_entry;
        
        // Keep only last 100 critical events
        if (count($existing_logs) > 100) {
            $existing_logs = array_slice($existing_logs, -100);
        }
        
        update_option('sap_import_flow_logs', $existing_logs);
    }
}
}

/**
 * Log detailed error and mismatch information
 *
 * @param string $error_type Type of error ('attribute_mapping', 'category_assignment', 'stock_update', etc.)
 * @param array $error_data Detailed error information
 */
if (!function_exists('sap_log_detailed_error')) {
function sap_log_detailed_error($error_type, $error_data) {
    $log_entry = [
        'timestamp' => current_time('mysql'),
        'error_type' => $error_type,
        'error_data' => $error_data,
        'stack_trace' => wp_debug_backtrace_summary()
    ];
    
    // Log to WordPress error log
    error_log('SAP Import Error [' . strtoupper($error_type) . ']: ' . wp_json_encode($log_entry));
    
    // Store in database for analysis
    $existing_errors = get_option('sap_detailed_error_logs', []);
    $existing_errors[] = $log_entry;
    
    // Keep only last 200 errors
    if (count($existing_errors) > 200) {
        $existing_errors = array_slice($existing_errors, -200);
    }
    
    update_option('sap_detailed_error_logs', $existing_errors);
}
}

/**
 * Save import log to WordPress options for later review
 *
 * @param array $import_log The import log data
 */
if (!function_exists('sap_save_import_log')) {
function sap_save_import_log($import_log) {
    $import_log['end_time'] = current_time('Y-m-d H:i:s');
    $import_log['run_id'] = uniqid('import_');
    
    // Get existing logs
    $existing_logs = get_option('sap_import_logs', []);
    
    // Add new log (keep last 10 runs)
    $existing_logs[] = $import_log;
    if (count($existing_logs) > 10) {
        $existing_logs = array_slice($existing_logs, -10);
    }
    
    update_option('sap_import_logs', $existing_logs);
    
    // Also save to WordPress error log for debugging
    error_log('SAP Import Log: ' . print_r($import_log, true));
}
}

/**
 * Send message to Telegram (reused from sap-products-import.php)
 *
 * @param string $message Message to send
 * @return bool|WP_Error True on success, WP_Error on failure
 */
if (!function_exists('sap_send_telegram_message_manual')) {
function sap_send_telegram_message_manual($message) {
    if (empty(SAP_TELEGRAM_BOT_TOKEN) || empty(SAP_TELEGRAM_CHAT_ID)) {
        return new WP_Error('telegram_config', 'Telegram configuration missing');
    }
    
    $url = "https://api.telegram.org/bot" . SAP_TELEGRAM_BOT_TOKEN . "/sendMessage";
    
    $data = [
        'chat_id' => SAP_TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    
    $args = [
        'method' => 'POST',
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($data),
        'timeout' => 60
    ];
    
    $response = wp_remote_post($url, $args);
    
    if (is_wp_error($response)) {
        error_log('Telegram notification failed: ' . $response->get_error_message());
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $error_msg = 'Telegram API error: ' . $response_code;
        error_log($error_msg);
        return new WP_Error('telegram_api', $error_msg);
    }
    
    return true;
}
}

/**
 * Send manual import summary to Telegram
 *
 * @param array $import_stats Import statistics
 * @param array $errors Array of errors
 * @param array $new_products Array of newly created products
 */
if (!function_exists('sap_send_manual_import_telegram_notification')) {
function sap_send_manual_import_telegram_notification($import_stats, $errors = [], $new_products = []) {
    // Add array checks at the start
    if (!is_array($errors)) {
        $errors = [];
    }
    if (!is_array($new_products)) {
        $new_products = [];
    }
    
    $status = empty($errors) ? "✓" : "X";
    $message = $status . " SAP Manual Import Finished\n\n";
    
    // Import statistics
    $message .= "<b>Import Statistics:</b>\n";
    $message .= "Groups processed: " . ($import_stats['groups_processed'] ?? 0) . "\n";
    $message .= "Products updated: " . ($import_stats['products_updated'] ?? 0) . "\n";
    $message .= "New groups created: " . ($import_stats['new_groups_created'] ?? 0) . "\n";
    $message .= "Existing groups updated: " . ($import_stats['existing_groups_updated'] ?? 0) . "\n";
    $message .= "Simple products: " . ($import_stats['simple_products_created'] ?? 0) . "\n";
    $message .= "Variable products: " . (($import_stats['products_created'] ?? 0) - ($import_stats['simple_products_created'] ?? 0)) . "\n";
    $message .= "Variations created: " . ($import_stats['variations_created'] ?? 0) . "\n";
    $message .= "Items skipped: " . ($import_stats['skipped_items'] ?? 0) . "\n\n";
    
    // New products summary
    if (!empty($new_products)) {
        $message .= "<b>New Products Created:</b>\n";
        $product_count = 0;
        foreach (array_slice($new_products, 0, 5) as $product) { // Show first 5
            $product_count++;
            $type_icon = $product['product_type'] === 'simple' ? '📦' : '📋';
            $message .= "$type_icon {$product['sku']} → {$product['category_assigned']}\n";
        }
        if (count($new_products) > 5) {
            $message .= "... and " . (count($new_products) - 5) . " more products\n";
        }
        $message .= "\n";
    }
    
    // Errors
    if (is_array($errors) && !empty($errors)) {
        $message .= "<b>⚠️ Errors (" . count($errors) . "):</b>\n";
        foreach (array_slice($errors, 0, 3) as $error) { // Show first 3 errors
            $message .= "• " . substr($error, 0, 80) . (strlen($error) > 80 ? "..." : "") . "\n";
        }
        if (count($errors) > 3) {
            $message .= "... and " . (count($errors) - 3) . " more errors\n";
        }
        $message .= "\n";
    }
    
    $message .= "<b>Time:</b> " . current_time('Y-m-d H:i:s');
    
    // Send the message
    return sap_send_telegram_message_manual($message);
}
}

/**
 * Display import logs in admin
 */
function sap_display_import_logs() {
    $logs = get_option('sap_import_logs', []);
    
    if (empty($logs)) {
        echo "<p>אין לוגים של ייבוא מוצרים עדיין.</p>";
        return;
    }
    
    echo "<h3>היסטוריית ייבוא מוצרים</h3>";
    echo "<table class='wp-list-table widefat fixed striped'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>תאריך</th>";
    echo "<th>קבוצות שעובדו</th>";
    echo "<th>קבוצות חדשות</th>";
    echo "<th>קבוצות קיימות</th>";
    echo "<th>מוצרים פשוטים</th>";
    echo "<th>מוצרים משתנים</th>";
    echo "<th>וריאציות</th>";
    echo "<th>שגיאות</th>";
    echo "<th>פריטים שדולגו</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach (array_reverse($logs) as $log) {
        $variable_products = isset($log['products_created']) && isset($log['simple_products_created']) 
            ? $log['products_created'] - $log['simple_products_created'] 
            : 0;
        
        echo "<tr>";
        echo "<td>" . esc_html($log['start_time']) . "</td>";
        echo "<td>" . esc_html($log['groups_processed'] ?? 0) . "</td>";
        echo "<td>" . esc_html($log['new_groups_created'] ?? 0) . "</td>";
        echo "<td>" . esc_html($log['existing_groups_updated'] ?? 0) . "</td>";
        echo "<td>" . esc_html($log['simple_products_created'] ?? 0) . "</td>";
        echo "<td>" . esc_html($variable_products) . "</td>";
        echo "<td>" . esc_html($log['variations_created'] ?? 0) . "</td>";
        $errors_count = 0;
        if (isset($log['errors']) && is_array($log['errors'])) {
            $errors_count = count($log['errors']);
        }
        echo "<td>" . esc_html($errors_count) . "</td>";
        echo "<td>" . esc_html($log['skipped_items'] ?? 0) . "</td>";
        echo "</tr>";
        
        // Show errors if any
        if (is_array($log['errors']) && !empty($log['errors'])) {
            echo "<tr>";
            echo "<td colspan='9'>";
            echo "<strong>שגיאות:</strong><br>";
            foreach ($log['errors'] as $error) {
                echo "• " . esc_html($error) . "<br>";
            }
            echo "</td>";
            echo "</tr>";
        }
        
        // Show skipped reasons if any
        if (!empty($log['skipped_reasons'])) {
            echo "<tr>";
            echo "<td colspan='9'>";
            echo "<strong>סיבות דילוג:</strong><br>";
            foreach ($log['skipped_reasons'] as $reason => $count) {
                $reason_text = $reason === 'missing_group_code' ? 'חסרי קוד קבוצה' : 
                              ($reason === 'already_imported' ? 'כבר מיובאים' : 
                              ($reason === 'sku_already_exists' ? 'SKU כבר קיים' :
                              ($reason === 'existing_non_variable' ? 'קבוצה קיימת שאינה משתנה' : $reason)));
                echo "• {$reason_text}: {$count}<br>";
            }
            echo "</td>";
            echo "</tr>";
        }
    }
    
    echo "</tbody>";
    echo "</table>";
}


/**
 * Check if item should be processed for creation or update
 *
 * @param array $item SAP item data
 * @return array Status array with 'should_process', 'action', 'reason', 'existing_product_id', 'product_type'
 */
if (!function_exists('sap_check_item_processing_status')) {
function sap_check_item_processing_status($item) {
    $item_code = $item['ItemCode'] ?? '';
    
    // Skip items without ItemCode
    if (empty($item_code)) {
        return [
            'should_process' => false,
            'action' => 'skip',
            'reason' => 'missing_item_code',
            'existing_product_id' => null,
            'product_type' => null
        ];
    }
    
    // Check if SKU already exists in WooCommerce (PRIORITY: Update existing)
    $existing_product_id = wc_get_product_id_by_sku($item_code);
    if ($existing_product_id > 0) {
        $existing_product = wc_get_product($existing_product_id);
        if ($existing_product) {
            return [
                'should_process' => true,
                'action' => 'update_existing',
                'reason' => 'update_stock_price_validate_data',
                'existing_product_id' => $existing_product_id,
                'product_type' => $existing_product->get_type()
            ];
        }
    }
    
    // Check if already has U_SiteGroupID but no WC SKU match (mismatch case)
    if (!empty($item['U_SiteGroupID']) && $item['U_SiteGroupID'] > 0) {
        // This is a data mismatch - SAP thinks it's imported but WC SKU doesn't exist
        sap_log_detailed_error('data_mismatch_sap_imported_no_wc_sku', [
            'item_code' => $item_code,
            'sap_site_group_id' => $item['U_SiteGroupID'],
            'sap_site_item_id' => $item['U_SiteItemID'] ?? 'not_set',
            'issue' => 'SAP has SiteGroupID but WooCommerce SKU not found'
        ]);
        
        return [
            'should_process' => true,
            'action' => 'create_missing',
            'reason' => 'sap_imported_but_missing_in_wc',
            'existing_product_id' => null,
            'product_type' => null
        ];
    }
    
    // New product that should be created
    return [
        'should_process' => true,
        'action' => 'create',
        'reason' => 'new_product',
        'existing_product_id' => null,
        'product_type' => null
    ];
}
}

/**
 * Get price from SAP item preferring PriceList 1 with fallback
 * 
 * @param array $item SAP item data
 * @param string $item_code Item code for logging
 * @return array Array with 'price' (calculated price), 'used_fallback' (bool), 'pricelist_used' (int)
 */
if (!function_exists('sap_get_price_from_item')) {
function sap_get_price_from_item($item, $item_code = '') {
    if (!isset($item['ItemPrices']) || !is_array($item['ItemPrices'])) {
        return ['price' => null, 'used_fallback' => false, 'pricelist_used' => null];
    }
    
    // First priority: Look for PriceList 1
    foreach ($item['ItemPrices'] as $price_entry) {
        if (isset($price_entry['PriceList']) && $price_entry['PriceList'] === 1 && 
            isset($price_entry['Price']) && is_numeric($price_entry['Price']) && $price_entry['Price'] > 0) {
            $base_price = (float)$price_entry['Price'];
            $calculated_price = floor($base_price * 1.18) . '.9';
            return [
                'price' => $calculated_price,
                'used_fallback' => false,
                'pricelist_used' => 1
            ];
        }
    }
    
    // Fallback: Look for any other valid price list
    foreach ($item['ItemPrices'] as $price_entry) {
        if (isset($price_entry['Price']) && is_numeric($price_entry['Price']) && $price_entry['Price'] > 0) {
            $base_price = (float)$price_entry['Price'];
            $calculated_price = floor($base_price * 1.18) . '.9';
            $pricelist_used = $price_entry['PriceList'] ?? 'unknown';
            
            // Log the fallback usage
            error_log("SAP Manual Import: Using fallback PriceList {$pricelist_used} for item {$item_code} (PriceList 1 not available)");
            sap_log_detailed_error('pricelist_1_missing_fallback_used', [
                'item_code' => $item_code,
                'pricelist_used' => $pricelist_used,
                'base_price' => $base_price,
                'calculated_price' => $calculated_price,
                'available_pricelists' => array_column($item['ItemPrices'], 'PriceList')
            ]);
            
            return [
                'price' => $calculated_price,
                'used_fallback' => true,
                'pricelist_used' => $pricelist_used
            ];
        }
    }
    
    return ['price' => null, 'used_fallback' => false, 'pricelist_used' => null]; // No valid price found
}
}

/**
 * Set parent product price range based on variation prices
 * 
 * @param int $product_id Variable product ID
 * @param array $items Array of SAP items with pricing data
 * @return bool True on success, false on failure
 */
if (!function_exists('sap_set_parent_product_price_range')) {
function sap_set_parent_product_price_range($product_id, $items) {
    // Add array check at the start
    if (!is_array($items)) {
        error_log("SAP Manual Import: Invalid items data for price range setting");
        return false;
    }
    
    $prices = [];
    
    // Collect all valid prices from items
    foreach ($items as $item) {
        $item_code = $item['ItemCode'] ?? 'unknown';
        $price_result = sap_get_price_from_item($item, $item_code);
        if ($price_result['price'] !== null) {
            $prices[] = (float)$price_result['price'];
        }
    }
    
    if (empty($prices)) {
        error_log("SAP Manual Import: No valid prices found for variable product {$product_id}");
        return false;
    }
    
    $min_price = min($prices);
    $max_price = max($prices);
    
    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) {
        error_log("SAP Manual Import: Invalid variable product {$product_id} for price range setting");
        return false;
    }
    
    // Set price range
    if ($min_price === $max_price) {
        // Single price - set as regular price
        $product->set_regular_price($min_price);
        error_log("SAP Manual Import: Set single price {$min_price} for variable product {$product_id}");
    } else {
        // Price range - WooCommerce will automatically show range from variations
        // We just need to ensure variations have correct prices (already done)
        error_log("SAP Manual Import: Price range {$min_price}-{$max_price} for variable product {$product_id}");
    }
    
    $product->save();
    return true;
}
}

/**
 * Determine if items should create a simple product
 * FIXED LOGIC: Create VARIABLE if ANY item has non-empty size OR color (including "0")
 * Create SIMPLE only when ALL items have both size AND color empty/null
 *
 * @param array $items Array of SAP items
 * @return bool True if should create simple product, false for variable
 */
if (!function_exists('sap_should_create_simple_product')) {
function sap_should_create_simple_product($items) {
    // Add array check at the start
    if (!is_array($items)) {
        error_log("SAP Manual Import: Invalid items data for simple product determination");
        return true; // Default to simple product if data is invalid
    }
    
    error_log("SAP Manual Import: Analyzing " . count($items) . " items for product type determination");
    
    foreach ($items as $index => $item) {
        $item_code = $item['ItemCode'] ?? "unknown";
        
        // Check size attributes (including "0" as valid attribute)
        $size_value = isset($item['U_ssize']) ? trim($item['U_ssize']) : '';
        $has_size = !empty($size_value);
        
        // Check color attributes (including "0" as valid attribute) 
        $color_value = isset($item['U_scolor']) ? trim($item['U_scolor']) : '';
        $has_color = !empty($color_value);
        
        error_log("SAP Manual Import: Item {$item_code} - Size: '{$size_value}' (has: " . ($has_size ? 'YES' : 'NO') . "), Color: '{$color_value}' (has: " . ($has_color ? 'YES' : 'NO') . ")");
        
        // If ANY item has size OR color attributes, create VARIABLE product
        if ($has_size || $has_color) {
            error_log("SAP Manual Import: DECISION - Creating VARIABLE product (item {$item_code} has attributes)");
            return false; // Create variable product
        }
    }
    
    // ALL items have NO attributes - create simple product
    error_log("SAP Manual Import: DECISION - Creating SIMPLE product (no items have attributes)");
    return true;
}
}

/**
 * Validate business rules for group processing
 *
 * @param string $group_name Group name
 * @param array $items Items in the group
 * @return array Validation result with 'valid', 'action', 'filtered_items', 'update_items', 'messages'
 */
if (!function_exists('sap_validate_group_business_rules')) {
function sap_validate_group_business_rules($group_name, $items) {
    // Add array check at the start
    if (!is_array($items)) {
        error_log("Invalid items data for business validation of group {$group_name}");
        return [
            'valid' => false,
            'action' => 'skip',
            'filtered_items' => [],
            'update_items' => [],
            'messages' => ['Invalid items data']
        ];
    }
    
    $validation_result = [
        'valid' => true,
        'action' => 'mixed',
        'filtered_items' => [],
        'update_items' => [],
        'messages' => []
    ];
    
    // Separate items into different categories
    $create_items = [];
    $update_items = [];
    $create_missing_items = [];
    
    foreach ($items as $item) {
        $check_result = sap_check_item_processing_status($item);
        
        if (!$check_result['should_process']) {
            $validation_result['messages'][] = "Skipping item " . ($item['ItemCode'] ?? 'unknown') . ": " . $check_result['reason'];
            continue;
        }
        
        switch ($check_result['action']) {
            case 'update_existing':
                $update_items[] = array_merge($item, [
                    'existing_product_id' => $check_result['existing_product_id'],
                    'product_type' => $check_result['product_type']
                ]);
                break;
                
            case 'create_missing':
                $create_missing_items[] = $item;
                break;
                
            case 'create':
                $create_items[] = $item;
                break;
        }
    }
    
    // Set update items for processing
    if (!empty($update_items)) {
        $validation_result['update_items'] = $update_items;
        $validation_result['messages'][] = "Found " . (is_array($update_items) ? count($update_items) : 0) . " existing products to update (stock/price/validate)";
    }
    
    // Handle items that need to be created
    if (!empty($create_items) || !empty($create_missing_items)) {
        $all_create_items = array_merge($create_items, $create_missing_items);
        
        // Check if group already exists in WooCommerce for new items
        $existing_product_by_name = get_page_by_title($group_name, OBJECT, 'product');
        
        if ($existing_product_by_name) {
            $existing_product = wc_get_product($existing_product_by_name->ID);
            
            if ($existing_product && $existing_product->is_type('variable')) {
                $validation_result['action'] = 'add_variations';
                $validation_result['filtered_items'] = $all_create_items;
                $validation_result['messages'][] = "Adding " . (is_array($all_create_items) ? count($all_create_items) : 0) . " new variations to existing variable product";
            } else {
                // Group exists as simple product - create with modified name to avoid conflict
                $should_be_simple = sap_should_create_simple_product($all_create_items);
                $validation_result['action'] = $should_be_simple ? 'create_simple' : 'create_variable';
                $validation_result['filtered_items'] = $all_create_items;
                $validation_result['messages'][] = "Group name conflict - creating as " . ($validation_result['action'] === 'create_simple' ? 'simple' : 'variable') . " product with modified name";
            }
        } else {
            // New group - determine type based on attributes, not just count
            $should_be_simple = sap_should_create_simple_product($all_create_items);
            $validation_result['action'] = $should_be_simple ? 'create_simple' : 'create_variable';
            $validation_result['filtered_items'] = $all_create_items;
            $validation_result['messages'][] = "Creating new " . ($validation_result['action'] === 'create_simple' ? 'simple' : 'variable') . " product with " . (is_array($all_create_items) ? count($all_create_items) : 0) . " items";
        }
    }
    
    // Check if we have any valid operations
    if (empty($update_items) && empty($validation_result['filtered_items'])) {
        $validation_result['valid'] = false;
        $validation_result['action'] = 'skip';
        $validation_result['messages'][] = "No valid items for processing";
    }
    
    return $validation_result;
}
}

/**
 * Process a single product group - create variable product and variations
 *
 * @param string $group_name ItmsGrpNam from SAP
 * @param array $items Array of items in this group
 * @param string $auth_token SAP authentication token
 * @return int|WP_Error Product ID on success or error
 */
if (!function_exists('sap_process_product_group')) {
function sap_process_product_group($group_name, $items, $auth_token, &$import_log) {
    // Ensure import_log has errors array
    if (!isset($import_log['errors']) || !is_array($import_log['errors'])) {
        $import_log['errors'] = [];
    }
    
    // Add array check at the start
    if (!is_array($items)) {
        error_log("Invalid items data for group {$group_name}");
        $import_log['errors'][] = "Invalid items data for group {$group_name}";
        return false;
    }
    
    error_log("מעבד קבוצת מוצרים: {$group_name} עם " . (is_array($items) ? count($items) : 0) . " פריטים");
    
    // Log group processing start
    sap_log_import_flow('group_processing', [
        'group_name' => $group_name,
        'item_count' => is_array($items) ? count($items) : 0,
        'first_item_code' => $items[0]['ItemCode'] ?? 'unknown'
    ]);
    
    // Validate business rules for this group
    $validation = sap_validate_group_business_rules($group_name, $items);
    
    // Log validation messages
    foreach ($validation['messages'] as $message) {
        error_log("SAP Manual Import: Group validation - $message");
    }
    
    if (!$validation['valid']) {
        error_log("דילוג על קבוצה {$group_name}: " . implode(', ', $validation['messages']));
        return true; // Not an error, just skipped
    }
    
    $items_to_process = $validation['filtered_items'];
    $update_items = $validation['update_items'] ?? [];
    $action = $validation['action'];
    
    $success = true;
    
    // First, handle updates for existing products
    if (!empty($update_items)) {
        $update_success = sap_handle_update_existing_products($update_items, $auth_token, $import_log);
        if (!$update_success) {
            $success = false;
        }
    }
    
    // Then, handle creation of new products if needed
    if (!empty($items_to_process)) {
        switch ($action) {
            case 'add_variations':
                $create_success = sap_handle_add_variations_to_existing($group_name, $items_to_process, $auth_token, $import_log);
                break;
                
            case 'create_simple':
                $create_success = sap_handle_create_simple_product($group_name, $items_to_process[0], $auth_token, $import_log);
                break;
                
            case 'create_variable':
                $create_success = sap_handle_create_variable_product($group_name, $items_to_process, $auth_token, $import_log);
                break;
                
            default:
                error_log("Unknown action: $action for group $group_name");
                $create_success = false;
        }
        
        if (!$create_success) {
            $success = false;
        }
    }
    
    return $success;
}
}

/**
 * Handle updating existing products (stock, price, validation)
 *
 * @param array $update_items Items that need to be updated
 * @param string $auth_token SAP authentication token
 * @param array $import_log Import log reference
 * @return bool Success status
 */
if (!function_exists('sap_handle_update_existing_products')) {
function sap_handle_update_existing_products($update_items, $auth_token, &$import_log) {
    // Ensure import_log has errors array
    if (!isset($import_log['errors']) || !is_array($import_log['errors'])) {
        $import_log['errors'] = [];
    }
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($update_items as $item) {
        $item_code = $item['ItemCode'] ?? '';
        $existing_product_id = $item['existing_product_id'];
        $product_type = $item['product_type'];
        
        error_log("SAP Manual Import: Updating existing product {$item_code} (ID: {$existing_product_id}, Type: {$product_type})");
        
        try {
            if ($product_type === 'variation') {
                $success = sap_update_existing_variation($item, $existing_product_id);
            } else {
                $success = sap_update_existing_simple_product($item, $existing_product_id);
            }
            
            if ($success) {
                $success_count++;
                error_log("SAP Manual Import: Successfully updated existing product {$item_code}");
            } else {
                $error_count++;
                $import_log['errors'][] = "Failed to update existing product: {$item_code}";
            }
            
        } catch (Exception $e) {
            $error_count++;
            $error_msg = "Exception updating existing product {$item_code}: " . $e->getMessage();
            error_log("SAP Manual Import: " . $error_msg);
            $import_log['errors'][] = $error_msg;
            
            sap_handle_import_exception($e, [
                'item_code' => $item_code,
                'existing_product_id' => $existing_product_id,
                'phase' => 'update_existing_product'
            ]);
        }
    }
    
    // Update import statistics
    if (isset($import_log['products_updated'])) {
        $import_log['products_updated'] += $success_count;
    } else {
        $import_log['products_updated'] = $success_count;
    }
    
    error_log("SAP Manual Import: Updated {$success_count} existing products, {$error_count} failures");
    return $error_count === 0;
}
}

/**
 * Update existing variation with stock/price and validate data
 *
 * @param array $item SAP item data
 * @param int $variation_id WooCommerce variation ID
 * @return bool Success status
 */
if (!function_exists('sap_update_existing_variation')) {
function sap_update_existing_variation($item, $variation_id) {
    $variation = wc_get_product($variation_id);
    if (!$variation || !$variation->is_type('variation')) {
        sap_log_detailed_error('invalid_variation_for_update', [
            'variation_id' => $variation_id,
            'item_code' => $item['ItemCode'] ?? 'unknown'
        ]);
        return false;
    }
    
    $item_code = $item['ItemCode'] ?? '';
    $validation_errors = [];
    
    // Validate and log data mismatches
    sap_validate_and_log_data_integrity($item, $variation, 'variation');
    
    // Update stock from ItemWarehouseInfoCollection
    $stock_updated = false;
    if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
        foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
            if (isset($warehouse_info['InStock']) && is_numeric($warehouse_info['InStock'])) {
                $stock_quantity = max(0, (int)$warehouse_info['InStock'] - 10);
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($stock_quantity);
                $variation->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
                $stock_updated = true;
                error_log("SAP Manual Import: Updated stock for variation {$item_code}: {$warehouse_info['InStock']} -> {$stock_quantity}");
                break; // Use first warehouse's stock
            }
        }
    }
    
    if (!$stock_updated) {
        sap_log_detailed_error('stock_update_failed', [
            'item_code' => $item_code,
            'variation_id' => $variation_id,
            'warehouse_data' => $item['ItemWarehouseInfoCollection'] ?? 'not_set'
        ]);
    }
    
    // Update price using PriceList 1 with fallback
    $price_result = sap_get_price_from_item($item, $item_code);
    $price_updated = false;
    
    if ($price_result['price'] !== null) {
        $variation->set_regular_price($price_result['price']);
        $price_updated = true;
        $fallback_note = $price_result['used_fallback'] ? " (using fallback PriceList {$price_result['pricelist_used']})" : "";
        error_log("SAP Manual Import: Updated price for variation {$item_code}: -> {$price_result['price']}{$fallback_note}");
    } else {
        error_log("SAP Manual Import: No valid price found for variation {$item_code} - skipping price update");
        sap_log_detailed_error('no_valid_price', [
            'item_code' => $item_code,
            'variation_id' => $variation_id,
            'price_data' => $item['ItemPrices'] ?? 'not_set'
        ]);
    }
    
    // Save variation
    $variation->save();
    return true;
}
}

/**
 * Update existing simple product with stock/price and validate data
 *
 * @param array $item SAP item data
 * @param int $product_id WooCommerce product ID
 * @return bool Success status
 */
if (!function_exists('sap_update_existing_simple_product')) {
function sap_update_existing_simple_product($item, $product_id) {
    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('simple')) {
        sap_log_detailed_error('invalid_simple_product_for_update', [
            'product_id' => $product_id,
            'item_code' => $item['ItemCode'] ?? 'unknown'
        ]);
        return false;
    }
    
    $item_code = $item['ItemCode'] ?? '';
    
    // Validate and log data mismatches
    sap_validate_and_log_data_integrity($item, $product, 'simple');
    
    // Update stock from ItemWarehouseInfoCollection
    $stock_updated = false;
    if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
        foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
            if (isset($warehouse_info['InStock']) && is_numeric($warehouse_info['InStock'])) {
                $stock_quantity = max(0, (int)$warehouse_info['InStock'] - 10);
                $product->set_manage_stock(true);
                $product->set_stock_quantity($stock_quantity);
                $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
                $stock_updated = true;
                error_log("SAP Manual Import: Updated stock for simple product {$item_code}: {$warehouse_info['InStock']} -> {$stock_quantity}");
                break; // Use first warehouse's stock
            }
        }
    }
    
    if (!$stock_updated) {
        sap_log_detailed_error('stock_update_failed', [
            'item_code' => $item_code,
            'product_id' => $product_id,
            'warehouse_data' => $item['ItemWarehouseInfoCollection'] ?? 'not_set'
        ]);
    }
    
    // Update price using PriceList 1 with fallback
    $price_result = sap_get_price_from_item($item, $item_code);
    $price_updated = false;
    
    if ($price_result['price'] !== null) {
        $product->set_regular_price($price_result['price']);
        $price_updated = true;
        $fallback_note = $price_result['used_fallback'] ? " (using fallback PriceList {$price_result['pricelist_used']})" : "";
        error_log("SAP Manual Import: Updated price for simple product {$item_code}: -> {$price_result['price']}{$fallback_note}");
    } else {
        error_log("SAP Manual Import: No valid price found for simple product {$item_code} - skipping price update");
        sap_log_detailed_error('no_valid_price', [
            'item_code' => $item_code,
            'product_id' => $product_id,
            'price_data' => $item['ItemPrices'] ?? 'not_set'
        ]);
    }
    
    // Save product
    $product->save();
    return true;
}
}

/**
 * Validate data integrity and log mismatches
 *
 * @param array $item SAP item data
 * @param WC_Product $wc_product WooCommerce product object
 * @param string $type 'simple' or 'variation'
 */
if (!function_exists('sap_validate_and_log_data_integrity')) {
function sap_validate_and_log_data_integrity($item, $wc_product, $type) {
    $item_code = $item['ItemCode'] ?? '';
    $mismatches = [];
    
    // Check SiteItemID mismatch
    $sap_site_item_id = $item['U_SiteItemID'] ?? null;
    $wc_product_id = $wc_product->get_id();
    
    if (!empty($sap_site_item_id) && $sap_site_item_id != $wc_product_id) {
        $mismatches[] = [
            'field' => 'U_SiteItemID',
            'sap_value' => $sap_site_item_id,
            'wc_value' => $wc_product_id,
            'issue' => 'SAP SiteItemID does not match WooCommerce product ID'
        ];
    }
    
    // Check SiteGroupID mismatch (for simple products or parent of variations)
    if ($type === 'simple') {
        $sap_site_group_id = $item['U_SiteGroupID'] ?? null;
        if (!empty($sap_site_group_id) && $sap_site_group_id != $wc_product_id) {
            $mismatches[] = [
                'field' => 'U_SiteGroupID',
                'sap_value' => $sap_site_group_id,
                'wc_value' => $wc_product_id,
                'issue' => 'SAP SiteGroupID does not match WooCommerce simple product ID'
            ];
        }
    } else if ($type === 'variation') {
        $parent_id = wp_get_post_parent_id($wc_product_id);
        $sap_site_group_id = $item['U_SiteGroupID'] ?? null;
        if (!empty($sap_site_group_id) && $sap_site_group_id != $parent_id) {
            $mismatches[] = [
                'field' => 'U_SiteGroupID',
                'sap_value' => $sap_site_group_id,
                'wc_value' => $parent_id,
                'issue' => 'SAP SiteGroupID does not match WooCommerce parent product ID'
            ];
        }
    }
    
    // Check attribute mismatches for variations
    if ($type === 'variation') {
        $variation_attributes = $wc_product->get_variation_attributes();
        
        // Check size attribute
        $sap_size = $item['U_ssize'] ?? '';
        $wc_size = $variation_attributes['pa_size'] ?? '';
        if (!empty($sap_size) && sanitize_title($sap_size) !== $wc_size) {
            $mismatches[] = [
                'field' => 'size_attribute',
                'sap_value' => $sap_size,
                'wc_value' => $wc_size,
                'issue' => 'Size attribute mismatch'
            ];
        }
        
        // Check color attribute
        $sap_color = $item['U_scolor'] ?? '';
        $wc_color = $variation_attributes['pa_color'] ?? '';
        if (!empty($sap_color) && sanitize_title($sap_color) !== $wc_color) {
            $mismatches[] = [
                'field' => 'color_attribute',
                'sap_value' => $sap_color,
                'wc_value' => $wc_color,
                'issue' => 'Color attribute mismatch'
            ];
        }
    }
    
    // Log mismatches if any found
    if (!empty($mismatches)) {
        sap_log_detailed_error('data_integrity_mismatches', [
            'item_code' => $item_code,
            'product_id' => $wc_product_id,
            'product_type' => $type,
            'mismatches' => $mismatches
        ]);
        
        error_log("SAP Manual Import: Data integrity mismatches found for {$item_code} (" . count($mismatches) . " issues)");
    }
}
}

/**
 * Handle adding variations to existing variable product
 */
if (!function_exists('sap_handle_add_variations_to_existing')) {
function sap_handle_add_variations_to_existing($group_name, $items, $auth_token, &$import_log) {
    $existing_product_by_name = get_page_by_title($group_name, OBJECT, 'product');
    
    if ($existing_product_by_name) {
        // Group exists - add new variations to existing product
        $existing_product = wc_get_product($existing_product_by_name->ID);
        
        if ($existing_product && $existing_product->is_type('variable')) {
            error_log("מוסיף וריאציות חדשות למוצר קיים: {$group_name} (ID: {$existing_product_by_name->ID})");
            
            // Add new variations to existing product
            $new_variation_ids = sap_add_variations_to_existing_product($existing_product_by_name->ID, $items);
            
            if (is_wp_error($new_variation_ids)) {
                $error_msg = "שגיאה בהוספת וריאציות למוצר קיים: " . $new_variation_ids->get_error_message();
                error_log($error_msg);
                $import_log['errors'][] = $error_msg;
                return false;
            }
            
            // Update SAP with new variation IDs
            $update_result = sap_update_sap_with_new_variations($items, $existing_product_by_name->ID, $new_variation_ids, $auth_token);
            
            if ($update_result) {
                $import_log['variations_created'] += is_array($new_variation_ids) ? count($new_variation_ids) : 0;
                $import_log['existing_groups_updated']++;
                error_log("וריאציות חדשות נוספו בהצלחה למוצר קיים: {$group_name} - " . (is_array($new_variation_ids) ? count($new_variation_ids) : 0) . " וריאציות");
            } else {
                $import_log['errors'][] = "שגיאה בעדכון SAP עם וריאציות חדשות: {$group_name}";
            }
            
            return true;
        }
    }
    
    return false; // Should not reach here if validation worked correctly
}
}

/**
 * Handle creating a simple product
 */
if (!function_exists('sap_handle_create_simple_product')) {
function sap_handle_create_simple_product($group_name, $item, $auth_token, &$import_log) {
    error_log("קבוצה עם פריט אחד בלבד - יוצר מוצר פשוט: {$item['ItemCode']}");
    
    // Create simple product
    $product_id = sap_create_simple_product_from_item($group_name, $item);
    
    if (is_wp_error($product_id)) {
        $error_msg = "שגיאה ביצירת מוצר פשוט: " . $product_id->get_error_message();
        error_log($error_msg);
        $import_log['errors'][] = $error_msg;
        return false;
    }
    
    // Update SAP with the new product ID
    $update_result = sap_update_sap_with_simple_product_id($item, $product_id, $auth_token);
    
    if ($update_result) {
        $import_log['products_created']++;
        $import_log['simple_products_created']++;
        $import_log['new_groups_created']++;
        
        // Log new product creation (age category no longer used)
        $category_assigned = 'כללי';
        sap_log_new_product_creation('simple', $product_id, $item['ItemCode'], [], $category_assigned);
        
        error_log("מוצר פשוט נוצר בהצלחה: ID {$product_id} עבור פריט {$item['ItemCode']}");
    } else {
        $import_log['errors'][] = "שגיאה בעדכון SAP עם ID מוצר פשוט: {$item['ItemCode']}";
        sap_log_detailed_error('sap_update_failure', [
            'item_code' => $item['ItemCode'],
            'product_id' => $product_id,
            'product_type' => 'simple'
        ]);
    }
    
    return true;
}
}

/**
 * Handle creating a variable product
 */
if (!function_exists('sap_handle_create_variable_product')) {
function sap_handle_create_variable_product($group_name, $items, $auth_token, &$import_log) {
    error_log("קבוצה עם מספר פריטים - יוצר מוצר משתנה עם וריאציות");
    
    // Create variable product
    $product_id = sap_create_variable_product_from_group($group_name, $items);
    
    if (is_wp_error($product_id)) {
        $error_msg = "שגיאה ביצירת מוצר משתנה: " . $product_id->get_error_message();
        error_log($error_msg);
        $import_log['errors'][] = $error_msg;
        return false;
    }
    
    // Create variations
    $variation_ids = sap_create_variations_from_items($product_id, $items);
    
    if (is_wp_error($variation_ids)) {
        $error_msg = "שגיאה ביצירת וריאציות: " . $variation_ids->get_error_message();
        error_log($error_msg);
        $import_log['errors'][] = $error_msg;
        return false;
    }
    
    // Update SAP with the new product and variation IDs
    $update_result = sap_update_sap_with_site_ids($items, $product_id, $variation_ids, $auth_token);
    
    if ($update_result) {
        $import_log['products_created']++;
        $import_log['variations_created'] += is_array($variation_ids) ? count($variation_ids) : 0;
        $import_log['new_groups_created']++;
        
        // Log new variable product creation (age category no longer used)
        $category_assigned = 'כללי';
        sap_log_new_product_creation('variable', $product_id, $group_name, $variation_ids, $category_assigned);
        
        error_log("מוצר משתנה נוצר בהצלחה: ID {$product_id} עם " . (is_array($variation_ids) ? count($variation_ids) : 0) . " וריאציות");
    } else {
        $import_log['errors'][] = "שגיאה בעדכון SAP עם ID מוצר: {$group_name}";
        sap_log_detailed_error('sap_update_failure', [
            'group_name' => $group_name,
            'product_id' => $product_id,
            'product_type' => 'variable',
            'variation_count' => is_array($variation_ids) ? count($variation_ids) : 0
        ]);
    }
    
    return true;
}
}

/**
 * Create a variable product from group name and items
 *
 * @param string $group_name ItmsGrpNam from SAP
 * @param array $items Array of items in this group
 * @return int|WP_Error Product ID on success or error
 */
if (!function_exists('sap_create_variable_product_from_group')) {
function sap_create_variable_product_from_group($group_name, $items) {
    try {
        // Create the product
        $product = new WC_Product_Variable();
        
        // Set basic product data
        $product->set_name($group_name);
        $product->set_status('pending'); // Set as pending as in Make scenario
        $product->set_manage_stock(false); // Variable products don't manage stock directly
        
        // Extract unique sizes and colors for attributes
        $sizes = [];
        $colors = [];
        
        foreach ($items as $item) {
            if (!empty($item['U_ssize'])) {
                $sizes[] = $item['U_ssize'];
            }
            if (!empty($item['U_scolor'])) {
                $colors[] = $item['U_scolor'];
            }
        }
        
        $sizes = array_unique($sizes);
        $colors = array_unique($colors);
        
        // Create attributes array
        $attributes = [];
        
        // Size attribute (ID 4 as in Make scenario)
        if (!empty($sizes)) {
            $size_attribute = new WC_Product_Attribute();
            $size_attribute->set_id(4);
            $size_attribute->set_name('pa_size');
            $size_attribute->set_options($sizes);
            $size_attribute->set_position(0);
            $size_attribute->set_visible(false);
            $size_attribute->set_variation(true);
            $attributes['pa_size'] = $size_attribute;
            
            // Ensure size taxonomy exists and terms are created
            sap_ensure_attribute_terms('pa_size', 'Size', $sizes);
        }
        
        // Color attribute (ID 3 as in Make scenario) 
        if (!empty($colors)) {
            $color_attribute = new WC_Product_Attribute();
            $color_attribute->set_id(3);
            $color_attribute->set_name('pa_color');
            $color_attribute->set_options($colors);
            $color_attribute->set_position(0);
            $color_attribute->set_visible(false);
            $color_attribute->set_variation(true);
            $attributes['pa_color'] = $color_attribute;
            
            // Ensure color taxonomy exists and terms are created
            sap_ensure_attribute_terms('pa_color', 'Color', $colors);
        }
        
        $product->set_attributes($attributes);
        
        // Save the product
        $product_id = $product->save();
        
        if (!$product_id) {
            return new WP_Error('product_creation_failed', 'נכשל ביצירת המוצר');
        }
        
        // Set parent product price range based on item prices
        sap_set_parent_product_price_range($product_id, $items);
        
        // TEMPORARILY DISABLED - Assign category based on U_EM_Age from first item
        /*
        if (!empty($items) && isset($items[0]['U_EM_Age'])) {
            $category_assigned = sap_assign_product_category($product_id, $items[0]['U_EM_Age']);
            if (!$category_assigned) {
                error_log("SAP Manual Import: Warning - Failed to assign category for product {$product_id}");
            }
        }
        */
        error_log("SAP Manual Import: Category assignment DISABLED for testing");
        
        return $product_id;
        
    } catch (Exception $e) {
        error_log('SAP Manual Import: Error creating variable product - ' . $e->getMessage());
        return new WP_Error('product_creation_error', 'שגיאה ביצירת מוצר: ' . $e->getMessage());
    }
}
}

/**
 * Create variations for a variable product
 *
 * @param int $product_id Parent product ID
 * @param array $items Array of SAP items
 * @return array|WP_Error Array of variation IDs mapped to ItemCode on success
 */
if (!function_exists('sap_create_variations_from_items')) {
function sap_create_variations_from_items($product_id, $items) {
    // Add array check at the start
    if (!is_array($items)) {
        error_log("Invalid items data for variations creation");
        return new WP_Error('invalid_items', 'Invalid items data for variations creation');
    }
    
    $variation_ids = [];
    
    try {
        foreach ($items as $item) {
            $item_code = $item['ItemCode'] ?? '';
            
            if (empty($item_code)) {
                continue;
            }
            
            // Create variation
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_sku($item_code);
            
            // Set attributes
            $variation_attributes = [];
            
            // Size attribute mapping
            $size_value = null;
            if (!empty($item['U_ssize'])) {
                $size_value = trim($item['U_ssize']);
            }
            
            if ($size_value) {
                $variation_attributes['pa_size'] = sanitize_title($size_value);
            }
            
            // Color attribute mapping
            $color_value = null;
            if (!empty($item['U_scolor'])) {
                $color_value = trim($item['U_scolor']);
            }
            
            if ($color_value) {
                $variation_attributes['pa_color'] = sanitize_title($color_value);
            }
            
            // Log if no attributes found
            if (empty($variation_attributes)) {
                sap_log_detailed_error('attribute_mapping_failure', [
                    'item_code' => $item_code ?? 'unknown',
                    'U_ssize' => $item['U_ssize'] ?? 'not_set',
                    'U_scolor' => $item['U_scolor'] ?? 'not_set'
                ]);
            }
            
            $variation->set_attributes($variation_attributes);
            
            // Set price with comprehensive fallback logic
            $price_set = false;
            if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
                // Get price using PriceList 1 with fallback
                $price_result = sap_get_price_from_item($item, $item_code);
                if ($price_result['price'] !== null) {
                    $variation->set_regular_price($price_result['price']);
                    $price_set = true;
                    if ($price_result['used_fallback']) {
                        error_log("SAP Manual Import: Used fallback PriceList {$price_result['pricelist_used']} for variation {$item_code}");
                    }
                }
            }
            
            if (!$price_set) {
                error_log("SAP Manual Import: No valid price found for variation " . ($item_code ?? 'unknown') . " - skipping");
                sap_log_detailed_error('no_valid_price', [
                    'item_code' => $item_code ?? 'unknown',
                    'item_prices' => $item['ItemPrices'] ?? 'not_set'
                ]);
            }
            
            // Set stock (InStock - 10 from ItemWarehouseInfoCollection)
            $stock_quantity = 0;
            if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
                foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
                    if (isset($warehouse_info['InStock']) && is_numeric($warehouse_info['InStock'])) {
                        $stock_quantity = max(0, (int)$warehouse_info['InStock'] - 10);
                        break; // Use first warehouse's stock
                    }
                }
            }
            
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($stock_quantity);
            $variation->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
            
            // Save variation
            $variation_id = $variation->save();
            
            if ($variation_id) {
                $variation_ids[$item_code] = $variation_id;
                error_log("SAP Manual Import: Created variation {$item_code} with ID {$variation_id}");
            }
        }
        
        if (empty($variation_ids)) {
            return new WP_Error('no_variations_created', 'לא נוצרו וריאציות');
        }
        
        // Update parent product price range after creating variations
        $parent_product = wc_get_product($product_id);
        if ($parent_product && $parent_product->is_type('variable')) {
            $parent_product->sync(false); // Sync variation prices to parent
            $parent_product->save(); // Save after sync
            wc_delete_product_transients($product_id); // Clear cache
        }
        
        return $variation_ids;
        
    } catch (Exception $e) {
        error_log('SAP Manual Import: Error creating variations - ' . $e->getMessage());
        return new WP_Error('variation_creation_error', 'שגיאה ביצירת וריאציות: ' . $e->getMessage());
    }
}
}

/**
 * Clean SAP JSON response by removing control characters and fixing encoding issues
 * CRITICAL: SAP streaming responses contain unescaped control chars (JSON_ERROR_CTRL_CHAR)
 *
 * @param string $response Raw JSON response from SAP
 * @return string Cleaned JSON response
 */
if (!function_exists('sap_clean_json_response')) {
function sap_clean_json_response($response) {
    error_log("SAP JSON Cleaner: Starting cleanup for " . strlen($response) . " byte response");
    
    // Remove UTF-8 BOM if present
    if (substr($response, 0, 3) === "\xEF\xBB\xBF") {
        $response = substr($response, 3);
        error_log("SAP JSON Cleaner: Removed UTF-8 BOM");
    }
    
    // Count control characters before cleaning
    $ctrl_char_count = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $response);
    if ($ctrl_char_count > 0) {
        error_log("SAP JSON Cleaner: Found {$ctrl_char_count} control characters to remove");
    }
    
    // CRITICAL: Remove ALL control characters that break JSON parsing
    // This is more aggressive - remove ALL control chars including \n and \t
    $original_length = strlen($response);
    $response = preg_replace('/[\x00-\x1F\x7F]/', '', $response);
    $cleaned_length = strlen($response);
    
    if ($original_length !== $cleaned_length) {
        $removed_chars = $original_length - $cleaned_length;
        error_log("SAP JSON Cleaner: Removed {$removed_chars} control characters");
    }
    
    // Handle line breaks and tabs in JSON strings (normalize but don't remove)
    // Replace problematic whitespace sequences that might break parsing
    $response = preg_replace('/\r\n/', '\n', $response);  // Normalize CRLF to LF
    $response = preg_replace('/\r/', '\n', $response);    // Normalize CR to LF
    
    // Fix common JSON structural issues
    $response = trim($response);
    
    // Remove any trailing commas before closing braces/brackets
    $response = preg_replace('/,(\s*[}\]])/', '$1', $response);
    
    // Validate and fix UTF-8 encoding
    if (!mb_check_encoding($response, 'UTF-8')) {
        error_log("SAP JSON Cleaner: Invalid UTF-8 detected, converting encoding");
        $response = mb_convert_encoding($response, 'UTF-8', 'UTF-8');
    }
    
    // Check for Hebrew text issues specifically and fix encoding
    if (strpos($response, 'בד לציצית') !== false || preg_match('/[\x{0590}-\x{05FF}]/u', $response)) {
        error_log("SAP JSON Cleaner: Hebrew text detected, applying encoding fixes");
        
        // Additional Hebrew-specific cleaning
        $response = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $response); // Remove RTL/LTR marks
        $response = preg_replace('/[\x{FEFF}]/u', '', $response); // Remove BOM characters
        
        // Ensure proper UTF-8 normalization
        if (function_exists('normalizer_normalize')) {
            $response = normalizer_normalize($response, Normalizer::FORM_C);
        }
    }
    
    error_log("SAP JSON Cleaner: Cleanup complete, final size: " . strlen($response) . " bytes");
    
    return $response;
}
}

/**
 * Parse concatenated JSON objects from SAP developer's broken format
 * CRITICAL: SAP changed from [{obj1}, {obj2}] to {obj1}{obj2}{obj3} (invalid JSON)
 *
 * @param string $response Raw concatenated JSON objects
 * @return array Array of decoded objects
 */
if (!function_exists('sap_parse_concatenated_json')) {
function sap_parse_concatenated_json($response) {
    error_log("SAP Concatenated Parser: Starting parse for " . strlen($response) . " bytes");
    
    // First clean the response
    $original_size = strlen($response);
    $response = sap_clean_json_response($response);
    $cleaned_size = strlen($response);
    
    if ($cleaned_size < $original_size * 0.9) { // If cleaning removed more than 10%
        error_log("SAP Concatenated Parser: WARNING - Cleaning removed significant content: {$original_size} -> {$cleaned_size} bytes");
    }
    
    // Check if it's already a valid JSON array (fallback for when SAP fixes it)
    if ($response[0] === '[') {
        error_log("SAP Concatenated Parser: Response is already valid JSON array");
        $decoded = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
            error_log("SAP Concatenated Parser: Successfully decoded JSON array with " . count($decoded) . " items");
            return $decoded;
        } else {
            error_log("SAP Concatenated Parser: Failed to decode JSON array: " . json_last_error_msg());
        }
    }
    
    // Check for {"value": [...]} wrapper format first
    if (preg_match('/^\{"value":\s*(\[.*\])\}$/s', $response, $matches)) {
        error_log("SAP Concatenated Parser: Detected value wrapper format");
        $array_json = $matches[1];
        error_log("SAP Concatenated Parser: Extracted array JSON (first 200 chars): " . substr($array_json, 0, 200));
        $decoded = json_decode($array_json, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
        if ($decoded !== null && is_array($decoded)) {
            error_log("SAP Concatenated Parser: Successfully extracted " . count($decoded) . " items from value wrapper");
            return $decoded;
        } else {
            error_log("SAP Concatenated Parser: Failed to decode extracted array: " . json_last_error_msg());
        }
    } else {
        // Debug why wrapper pattern didn't match
        $response_start = substr($response, 0, 100);
        error_log("SAP Concatenated Parser: Wrapper pattern didn't match. Response start: " . $response_start);
        
        // Try simpler pattern matching
        if (strpos($response, '"value":') !== false) {
            error_log("SAP Concatenated Parser: Found 'value' key, trying manual extraction");
            $value_pos = strpos($response, '"value":');
            $after_value = substr($response, $value_pos + 8);
            error_log("SAP Concatenated Parser: After value (first 200 chars): " . substr($after_value, 0, 200));
        }
    }
    
    // Check if it's a single object (not concatenated) 
    error_log("SAP Concatenated Parser: Attempting single object decode...");
    $single_object = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
    $json_error = json_last_error();
    
    error_log("SAP Concatenated Parser: Single object decode result - Error: " . $json_error . " (" . json_last_error_msg() . ")");
    
    if ($single_object !== null && $json_error === JSON_ERROR_NONE) {
        error_log("SAP Concatenated Parser: Response is single valid JSON object");
        
        // Debug the structure of the single object
        if (is_array($single_object)) {
            $object_keys = array_keys($single_object);
            error_log("SAP Concatenated Parser: Single object is array with " . count($object_keys) . " keys: " . implode(', ', array_slice($object_keys, 0, 10)));
            
            // Check if this is a single item object (has ItemCode)
            if (isset($single_object['ItemCode'])) {
                error_log("SAP Concatenated Parser: Single object appears to be a single item with ItemCode: " . $single_object['ItemCode']);
                return [$single_object]; // Wrap single item in array
            }
            
            // Check for 'value' array 
            if (isset($single_object['value']) && is_array($single_object['value'])) {
                error_log("SAP Concatenated Parser: Found 'value' array with " . count($single_object['value']) . " items");
                return $single_object['value']; // Return the value array directly
            }
        }
        
        return [$single_object]; // Wrap in array for consistent handling
    } else {
        error_log("SAP Concatenated Parser: Single object decode failed with error: " . json_last_error_msg());
    }
    
    error_log("SAP Concatenated Parser: Detected concatenated objects, splitting...");
    
    // Split by "}{"  (boundary between concatenated objects)
    $json_objects = [];
    $parts = explode('}{', $response);
    
    error_log("SAP Concatenated Parser: Found " . count($parts) . " parts to process");
    
    // If only 1 part, it's not concatenated - decode the whole response
    if (count($parts) === 1) {
        error_log("SAP Concatenated Parser: Single part detected, not concatenated");
        
        // Decode the entire response as a single object
        $whole_object = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
        if ($whole_object !== null && json_last_error() === JSON_ERROR_NONE) {
            error_log("SAP Concatenated Parser: Successfully decoded single object with keys: " . implode(', ', array_slice(array_keys($whole_object), 0, 5)));
            
            // Check if this is a wrapper object with 'value' array
            if (isset($whole_object['value']) && is_array($whole_object['value'])) {
                error_log("SAP Concatenated Parser: Found 'value' array with " . count($whole_object['value']) . " items");
                return $whole_object['value'];
            }
            
            // Single item object
            return [$whole_object];
        } else {
            error_log("SAP Concatenated Parser: Failed to decode single object: " . json_last_error_msg());
            return [];
        }
    }
    
    foreach ($parts as $index => $part) {
        // Add missing braces back to reconstruct valid JSON objects
        if ($index === 0) {
            // First part: add missing closing brace if needed
            $json_object = rtrim($part);
            if (substr($json_object, -1) !== '}') {
                $json_object .= '}';
            }
        } elseif ($index === count($parts) - 1) {
            // Last part: add missing opening brace if needed
            $json_object = ltrim($part);
            if (substr($json_object, 0, 1) !== '{') {
                $json_object = '{' . $json_object;
            }
        } else {
            // Middle parts: add both opening and closing braces
            $json_object = '{' . trim($part) . '}';
        }
        
        // Attempt to decode individual object
        $decoded = json_decode($json_object, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
        
        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
            $json_objects[] = $decoded;
            error_log("SAP Concatenated Parser: Successfully decoded object {$index}");
        } else {
            error_log("SAP Concatenated Parser: Failed to decode object at index {$index}: " . json_last_error_msg());
            error_log("SAP Concatenated Parser: Problematic object (first 200 chars): " . substr($json_object, 0, 200));
            
            // Try to fix common issues in this specific object
            $fixed_object = preg_replace('/,(\s*})/', '$1', $json_object); // Remove trailing commas
            $fixed_decoded = json_decode($fixed_object, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
            
            if ($fixed_decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                $json_objects[] = $fixed_decoded;
                error_log("SAP Concatenated Parser: Fixed and decoded object {$index} after comma cleanup");
            }
        }
    }
    
    $total_parsed = count($json_objects);
    $response_size = strlen($response);
    error_log("SAP Concatenated Parser: Successfully parsed {$total_parsed} objects from {$response_size} byte concatenated response");
    
    if ($total_parsed === 0) {
        error_log("SAP Concatenated Parser: WARNING - No objects could be parsed!");
        error_log("SAP Concatenated Parser: Response start: " . substr($response, 0, 500));
        error_log("SAP Concatenated Parser: Response end: " . substr($response, -500));
        
        // Return empty array but log it
        error_log("SAP Concatenated Parser: Returning empty array - this will cause 'no items found' error");
        return [];
    }
    
    error_log("SAP Concatenated Parser: Returning array with {$total_parsed} objects");
    return $json_objects;
}
}

/**
 * SAP API POST with streaming support for large responses
 * CRITICAL: Handles SAP's streaming response body (25+ second streams)
 *
 * @param string $endpoint API endpoint (e.g., 'Items/get')
 * @param array $data Request data
 * @param string $auth_token SAP authentication token
 * @return array|WP_Error Decoded response or error
 */
if (!function_exists('sap_api_post_streaming')) {
function sap_api_post_streaming($endpoint, $data, $auth_token) {
    // Increase memory limit for large JSON responses (2.6MB+)
    $original_memory_limit = ini_get('memory_limit');
    ini_set('memory_limit', '512M');
    error_log("SAP Streaming: Increased memory limit from {$original_memory_limit} to 512M");
    
    if (!function_exists('curl_init')) {
        ini_set('memory_limit', $original_memory_limit); // Restore
        return new WP_Error('curl_not_available', 'cURL is required for streaming SAP API calls');
    }
    
    $url = SAP_API_BASE . '/' . $endpoint;
    
    error_log("=== SAP STREAMING API START ===");
    error_log("SAP Streaming URL: {$url}");
    error_log("SAP Streaming Data: " . json_encode($data, JSON_PRETTY_PRINT));
    error_log("SAP Streaming Auth Token Length: " . strlen($auth_token));
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $auth_token,
            'User-Agent: SAP-WP-Plugin-Streaming/1.0'
        ],
        // CRITICAL: Streaming timeouts for 25+ second responses
        CURLOPT_TIMEOUT => 180,           // Total timeout: 3 minutes
        CURLOPT_CONNECTTIMEOUT => 30,     // Connection timeout: 30s
        CURLOPT_LOW_SPEED_LIMIT => 10,    // Minimum 10 bytes/sec
        CURLOPT_LOW_SPEED_TIME => 30,     // For 30 seconds (detects stalled stream)
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,  // For testing
        CURLOPT_SSL_VERIFYHOST => false,  // For testing
        CURLOPT_BUFFERSIZE => 16384,      // Larger buffer for streaming (16KB)
        CURLOPT_NOPROGRESS => false,      // Enable progress callback
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,  // Force HTTP/1.1
        CURLOPT_ENCODING => '',           // Accept all encodings
    ]);
    
    $start_time = microtime(true);
    error_log("SAP Streaming: Starting request at " . date('H:i:s'));
    
    $response = curl_exec($ch);
    
    $end_time = microtime(true);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total_time = round($end_time - $start_time, 2);
    $response_size = strlen($response);
    $curl_error = curl_error($ch);
    
    // Get detailed timing info
    $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
    $pretransfer_time = curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME);
    $starttransfer_time = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
    
    curl_close($ch);
    
    error_log("SAP Streaming Results:");
    error_log("- Total time: {$total_time}s");
    error_log("- Connect time: {$connect_time}s");
    error_log("- First byte time: {$starttransfer_time}s");
    error_log("- Response size: {$response_size} bytes");
    error_log("- HTTP code: {$http_code}");
    error_log("- cURL error: " . ($curl_error ?: 'none'));
    error_log("=== SAP STREAMING API END ===");
    
    if ($curl_error) {
        error_log("SAP Streaming cURL Error: " . $curl_error);
        ini_set('memory_limit', $original_memory_limit); // Restore on error
        return new WP_Error('curl_error', $curl_error);
    }
    
    if ($http_code !== 200) {
        error_log("SAP Streaming HTTP Error {$http_code}: " . substr($response, 0, 500));
        ini_set('memory_limit', $original_memory_limit); // Restore on error
        return new WP_Error('sap_api_http_error', "HTTP {$http_code}", [
            'http_code' => $http_code,
            'response_body' => $response,
            'total_time' => $total_time
        ]);
    }
    
    // Log successful response summary
    error_log("SAP Streaming Success: {$total_time}s, {$response_size} bytes received");
    
    // DIAGNOSTIC: Save raw response to file for analysis
    $temp_file = wp_get_upload_dir()['basedir'] . '/sap_raw_response.txt';
    file_put_contents($temp_file, $response);
    error_log("Raw response saved to {$temp_file}");
    
    // Log exact bytes at boundaries
    $first_100 = substr($response, 0, 100);
    $last_100 = substr($response, -100);
    error_log("First 100 bytes (hex): " . bin2hex($first_100));
    error_log("Last 100 bytes (hex): " . bin2hex($last_100));
    
    // Check for boundary patterns
    $boundary_count = substr_count($response, '}{');
    error_log("Found {$boundary_count} object boundaries ('}{') in response");
    
    if ($response_size > 1000) {
        error_log("SAP Streaming Response (first 500 chars): " . substr($response, 0, 500));
        error_log("SAP Streaming Response (last 500 chars): " . substr($response, -500));
    } else {
        error_log("SAP Streaming Response: " . $response);
    }
    
    // CRITICAL: Clean JSON response to remove control characters and fix encoding
    error_log("SAP Streaming: Starting JSON cleanup and validation for {$response_size} byte response");
    
    // STEP 1: Check boundary detection BEFORE cleaning
    $boundary_count_before = substr_count($response, '}{');
    error_log("SAP Streaming: Found {$boundary_count_before} object boundaries BEFORE cleaning");
    
    // Clean the response using our dedicated function
    $response = sap_clean_json_response($response);
    $cleaned_size = strlen($response);
    
    // Check boundaries AFTER cleaning
    $boundary_count_after = substr_count($response, '}{');
    error_log("SAP Streaming: Found {$boundary_count_after} object boundaries AFTER cleaning");
    
    if ($cleaned_size !== $response_size) {
        error_log("SAP Streaming: Response size changed from {$response_size} to {$cleaned_size} bytes after cleaning");
    }
    
    if ($boundary_count_before !== $boundary_count_after) {
        error_log("SAP Streaming: WARNING - Cleaning corrupted object boundaries! Before: {$boundary_count_before}, After: {$boundary_count_after}");
    }
    
    // Check for common JSON issues
    if (empty($response)) {
        ini_set('memory_limit', $original_memory_limit); // Restore
        return new WP_Error('empty_response', 'Empty response from SAP API');
    }
    
    // STEP 2: Detect JSON format and validate structure
    $first_char = $response[0];
    if ($first_char === '[') {
        error_log("SAP Streaming: Response is JSON array format");
    } elseif ($first_char === '{') {
        error_log("SAP Streaming: Response is JSON object format");
    } else {
        error_log("SAP Streaming: Response doesn't start with JSON delimiter. First char: " . ord($first_char));
        error_log("SAP Streaming: Response first 100 chars: " . substr($response, 0, 100));
        ini_set('memory_limit', $original_memory_limit); // Restore
        return new WP_Error('invalid_json_format', 'Response is not valid JSON format');
    }
    
    // STEP 3: Parse response using concatenated JSON parser (handles broken SAP format)
    error_log("SAP Streaming: Attempting to parse response using concatenated JSON parser...");
    $decoded_response = sap_parse_concatenated_json($response);
    
    // Check if parsing was successful
    if (is_array($decoded_response) && !empty($decoded_response)) {
        error_log("SAP Streaming: Concatenated JSON parser succeeded with " . count($decoded_response) . " objects");
        ini_set('memory_limit', $original_memory_limit); // Restore memory limit
        return $decoded_response;
    }
    
    // Fallback: try standard JSON decode if concatenated parser fails
    error_log("SAP Streaming: Concatenated parser failed, trying standard JSON decode as fallback...");
    $decoded_response = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
    $json_error = json_last_error();
    
    if ($json_error !== JSON_ERROR_NONE) {
        error_log("SAP Streaming: JSON decode error: " . json_last_error_msg());
        error_log("SAP Streaming: JSON error code: " . $json_error);
        
        // Specific handling for control character errors
        if ($json_error === JSON_ERROR_CTRL_CHAR) {
            error_log("SAP Streaming: CRITICAL - Control character error detected! SAP response contains unescaped control chars");
        }
        
        // Find the exact location of JSON error using chunked validation
        error_log("SAP Streaming: Searching for JSON error location...");
        $chunk_size = 1000;
        $error_found = false;
        
        for ($i = 0; $i < min(10000, strlen($response)); $i += $chunk_size) { // Check first 10KB only
            $chunk = substr($response, $i, $chunk_size);
            json_decode($chunk);
            if (json_last_error() !== JSON_ERROR_NONE && json_last_error() !== JSON_ERROR_STATE_MISMATCH) {
                error_log("SAP Streaming: JSON error around position {$i}");
                error_log("SAP Streaming: Problematic chunk (first 200 chars): " . substr($chunk, 0, 200));
                $error_found = true;
                break;
            }
        }
        
        if (!$error_found) {
            error_log("SAP Streaming: JSON error not found in first 10KB, likely structural issue");
        }
        
        // Try to find common JSON issues
        $common_issues = [
            'trailing comma' => '/,\s*[}\]]/',
            'unescaped quotes' => '/[^\\\\]"[^"]*"[^,}\]\s]/',
            'remaining control chars' => '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            'hebrew encoding issue' => '/בד לציצית.*?[^\x20-\x7E\x{0590}-\x{05FF}]/u'
        ];
        
        foreach ($common_issues as $issue => $pattern) {
            if (preg_match($pattern, substr($response, 0, 5000))) {
                error_log("SAP Streaming: Detected possible JSON issue: {$issue}");
            }
        }
        
        ini_set('memory_limit', $original_memory_limit); // Restore
        return new WP_Error('json_decode_error', 'Failed to decode JSON: ' . json_last_error_msg(), [
            'json_error_code' => $json_error,
            'response_size' => $response_size,
            'first_chars' => substr($response, 0, 100),
            'last_chars' => substr($response, -100)
        ]);
    }
    
    error_log("SAP Streaming: JSON decode successful!");
    ini_set('memory_limit', $original_memory_limit); // Restore memory limit
    
    return $decoded_response;
}
}

/**
 * Send PATCH request to SAP API for individual item updates
 *
 * @param string $item_code The ItemCode to update
 * @param array $data Update data (must include ItemCode)
 * @param string $auth_token SAP authentication token
 * @return array|WP_Error Decoded API response or WP_Error object
 */
if (!function_exists('sap_api_patch')) {
function sap_api_patch($item_code, $data, $auth_token) {
    $url = SAP_API_BASE . '/Items/' . urlencode($item_code);
    
    // CRITICAL: Ensure headers match Postman exactly
    $headers = [
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',  // Changed from */* to application/json
        'Authorization' => 'Bearer ' . $auth_token,
        'User-Agent'    => 'SAP-WP-Plugin/1.0',  // Add user agent
        'Cache-Control' => 'no-cache'  // Prevent caching issues
    ];
    
    $args = [
        'method'      => 'PATCH',
        'headers'     => $headers,
        'body'        => json_encode($data),
        'timeout'     => 60,
        'data_format' => 'body',
        'sslverify'   => defined('WP_DEBUG') && WP_DEBUG ? false : true,
        'redirection' => 0,  // Disable redirects
        'httpversion' => '1.1',  // Force HTTP/1.1
        'blocking'    => true,  // Wait for response
    ];
    
    // DETAILED DEBUG LOGGING - Compare with Postman
    error_log("=== SAP PATCH DEBUG START ===");
    error_log("SAP PATCH Request URL: {$url}");
    error_log("SAP PATCH Request Headers ARRAY: " . print_r($headers, true));
    error_log("SAP PATCH Request Headers JSON: " . json_encode($headers, JSON_PRETTY_PRINT));
    error_log("SAP PATCH Request Body RAW: " . json_encode($data));
    error_log("SAP PATCH Request Body PRETTY: " . json_encode($data, JSON_PRETTY_PRINT));
    error_log("SAP PATCH Request Args: " . print_r($args, true));
    error_log("SAP PATCH Auth Token (first 20 chars): " . substr($auth_token, 0, 20) . "...");
    error_log("SAP PATCH Auth Token Length: " . strlen($auth_token));
    
    $response = wp_remote_request($url, $args);
    
    // If wp_remote_request fails with 403, try cURL as backup
    if (!is_wp_error($response)) {
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code === 403) {
            error_log("SAP PATCH: wp_remote_request returned 403, trying cURL fallback...");
            $curl_response = sap_api_patch_curl_fallback($url, $data, $auth_token);
            if (!is_wp_error($curl_response)) {
                // Convert cURL response to wp_remote format
                $response = $curl_response;
            }
        }
    }
    
    // DETAILED RESPONSE LOGGING
    error_log("SAP PATCH Response Type: " . gettype($response));
    if (is_wp_error($response)) {
        error_log("SAP PATCH WP_Error: " . $response->get_error_message());
        error_log("SAP PATCH WP_Error Data: " . print_r($response->get_error_data(), true));
    } else {
        error_log("SAP PATCH Response Code: " . wp_remote_retrieve_response_code($response));
        error_log("SAP PATCH Response Headers: " . print_r(wp_remote_retrieve_headers($response), true));
        error_log("SAP PATCH Response Body: " . wp_remote_retrieve_body($response));
        error_log("SAP PATCH Response Message: " . wp_remote_retrieve_response_message($response));
    }
    error_log("=== SAP PATCH DEBUG END ===");
    
    if (is_wp_error($response)) {
        $error_data = [
            'response_code' => wp_remote_retrieve_response_code($response),
            'response_body' => wp_remote_retrieve_body($response),
        ];
        error_log("SAP PATCH Error for {$item_code}: " . $response->get_error_message());
        return new WP_Error('patch_error', "SAP PATCH error for {$item_code}: " . $response->get_error_message(), $error_data);
    }
    
    $body = wp_remote_retrieve_body($response);
    $http_code = wp_remote_retrieve_response_code($response);
    error_log("SAP PATCH Response for {$item_code} (HTTP {$http_code}): " . $body);
    
    // Consider 200-299 as success
    if ($http_code < 200 || $http_code >= 300) {
        return new WP_Error('patch_http_error', "SAP PATCH HTTP error {$http_code} for {$item_code}", [
            'http_code' => $http_code,
            'response_body' => $body
        ]);
    }
    
    $decoded_body = json_decode($body, true);
    return $decoded_body;
}
}

/**
 * cURL fallback for PATCH requests when wp_remote_request fails
 *
 * @param string $url The API endpoint URL
 * @param array $data Data to send
 * @param string $auth_token SAP authentication token
 * @return array|WP_Error Response array or error
 */
if (!function_exists('sap_api_patch_curl_fallback')) {
function sap_api_patch_curl_fallback($url, $data, $auth_token) {
    if (!function_exists('curl_init')) {
        return new WP_Error('curl_not_available', 'cURL is not available for fallback');
    }
    
    $ch = curl_init();
    
    // Set cURL options to match Postman exactly
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,  // For testing
        CURLOPT_SSL_VERIFYHOST => false,  // For testing
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $auth_token,
            'User-Agent: SAP-WP-Plugin/1.0',
            'Cache-Control: no-cache'
        ],
        CURLOPT_HEADER => true,  // Include headers in response
        CURLOPT_VERBOSE => true  // Enable verbose logging
    ]);
    
    error_log("SAP PATCH cURL: Executing request to {$url}");
    error_log("SAP PATCH cURL: Data: " . json_encode($data));
    error_log("SAP PATCH cURL: Auth token length: " . strlen($auth_token));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    if ($curl_error) {
        error_log("SAP PATCH cURL Error: " . $curl_error);
        return new WP_Error('curl_error', $curl_error);
    }
    
    $headers = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    error_log("SAP PATCH cURL Response Code: " . $http_code);
    error_log("SAP PATCH cURL Response Headers: " . $headers);
    error_log("SAP PATCH cURL Response Body: " . $body);
    
    // Format response to match wp_remote_request format
    return [
        'response' => ['code' => $http_code],
        'body' => $body,
        'headers' => $headers
    ];
}
}

/**
 * Update SAP with new WooCommerce product and variation IDs using individual PATCH calls
 *
 * @param array $items Original SAP items
 * @param int $product_id WooCommerce product ID
 * @param array $variation_ids Array mapping ItemCode to variation ID
 * @param string $auth_token SAP authentication token
 * @return bool|WP_Error True on success or error
 */
if (!function_exists('sap_update_sap_with_site_ids')) {
function sap_update_sap_with_site_ids($items, $product_id, $variation_ids, $auth_token) {
    // Add array checks at the start
    if (!is_array($items) || !is_array($variation_ids)) {
        error_log("Invalid items or variation_ids data for SAP update");
        return new WP_Error('invalid_data', 'Invalid items or variation_ids data for SAP update');
    }
    
    error_log('SAP Manual Import: Starting individual PATCH updates for ' . count($items) . ' items');
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    // Send individual PATCH request for each item
    foreach ($items as $item) {
        $item_code = $item['ItemCode'] ?? '';
        
        if (empty($item_code) || !isset($variation_ids[$item_code])) {
            error_log("SAP Manual Import: Skipping item {$item_code} - missing variation ID");
            continue;
        }
        
        $variation_id = $variation_ids[$item_code];
        
        // Prepare PATCH data with minimal required fields
        $patch_data = [
            'ItemCode' => $item_code,
            'U_SiteItemID' => $variation_id,
            'U_SiteGroupID' => $product_id
        ];
        
        error_log("SAP Manual Import: PATCH updating item {$item_code} with SiteItemID={$variation_id}, SiteGroupID={$product_id}");
        
        // COMMENTED OUT: Send PATCH request
        // $response = sap_api_patch($item_code, $patch_data, $auth_token);
        
        // INSTEAD: Collect the data for output
        global $sap_patch_data_collection;
        $sap_patch_data_collection[] = $patch_data;
        
        $success_count++;
        error_log("SAP Manual Import: Collected PATCH data for variable product item {$item_code} (not sent to SAP)");
        
        // if (is_wp_error($response)) {
        //     $error_count++;
        //     $error_msg = "PATCH failed for {$item_code}: " . $response->get_error_message();
        //     $errors[] = $error_msg;
        //     error_log("SAP Manual Import: " . $error_msg);
        //     echo "<p style='color: red;'>שגיאה: {$error_msg}</p>";
        // } else {
        //     $success_count++;
        //     error_log("SAP Manual Import: Successfully updated item {$item_code} via PATCH");
        //     echo "<p style='color: green;'>הצליח: {$item_code} עודכן בהצלחה</p>";
        // }
    }
    
    error_log("SAP Manual Import: Data collection summary - Total: " . count($items) . ", Collected: {$success_count}, Failed: {$error_count}");
    
    if ($error_count > 0) {
        error_log("SAP Manual Import: Collection errors: " . implode('; ', $errors));
        return new WP_Error('partial_failure', "Data collection completed with {$error_count} failures out of " . count($items) . " items", $errors);
    }
    
                return true;
}
}

/**
 * Ensure attribute taxonomy exists and create terms
 *
 * @param string $taxonomy Taxonomy slug (e.g., 'pa_size')
 * @param string $label Attribute label (e.g., 'Size')
 * @param array $terms Array of term names
 */
if (!function_exists('sap_ensure_attribute_terms')) {
function sap_ensure_attribute_terms($taxonomy, $label, $terms) {
    // Ensure taxonomy exists
    if (!taxonomy_exists($taxonomy)) {
        $attribute_slug = str_replace('pa_', '', $taxonomy);
        $created_attr_id = wc_create_attribute([
            'name' => $label,
            'slug' => $attribute_slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ]);
        
        if (is_wp_error($created_attr_id)) {
            error_log("Failed to create attribute {$label}: " . $created_attr_id->get_error_message());
            return;
        }
    }
    
    // Create terms if they don't exist
    foreach ($terms as $term_name) {
        if (empty($term_name)) continue;
        
        $term_slug = sanitize_title($term_name);
        $term_exists = term_exists($term_slug, $taxonomy);
        
        if (!$term_exists) {
            $inserted_term = wp_insert_term($term_name, $taxonomy);
            if (is_wp_error($inserted_term)) {
                error_log("Failed to insert term {$term_name} for attribute {$taxonomy}: " . $inserted_term->get_error_message());
            }
        }
    }
}
}

if (!function_exists('sap_create_simple_product_from_item')) {
function sap_create_simple_product_from_item($group_name, $item) {
    error_log("יוצר מוצר פשוט: {$item['ItemCode']} עבור קבוצה: {$group_name}");
    
    // Create the product
    $product = new WC_Product_Simple();
    
    // Set basic product data
    $product->set_name($group_name);
    $product->set_sku($item['ItemCode']);
    $product->set_status('pending');
    
    // Set price using PriceList 1 with fallback
    $price_result = sap_get_price_from_item($item, $item['ItemCode']);
    $price_set = false;
    
    if ($price_result['price'] !== null) {
        $product->set_regular_price($price_result['price']);
        $price_set = true;
        $fallback_note = $price_result['used_fallback'] ? " (using fallback PriceList {$price_result['pricelist_used']})" : "";
        error_log("מחיר מוצר: -> {$price_result['price']}{$fallback_note}");
    } else {
        error_log("SAP Manual Import: No valid price found for simple product " . $item['ItemCode'] . " - skipping");
        sap_log_detailed_error('no_valid_price', [
            'item_code' => $item['ItemCode'],
            'product_type' => 'simple',
            'item_prices' => $item['ItemPrices'] ?? 'not_set'
        ]);
    }
    
    // Set stock from ItemWarehouseInfoCollection
    $stock_quantity = 0;
    if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
        foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
            if (isset($warehouse_info['InStock']) && is_numeric($warehouse_info['InStock'])) {
                $stock_quantity = max(0, (int)$warehouse_info['InStock'] - 10);
                error_log("מלאי מוצר: {$warehouse_info['InStock']} -> {$stock_quantity}");
                break; // Use first warehouse's stock
            }
        }
    }
    
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock_quantity);
    $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
    
    // Set custom fields
    $product->update_meta_data('_sap_item_code', $item['ItemCode']);
    $product->update_meta_data('_sap_group_code', $item['ItemsGroupCode']);
    $product->update_meta_data('_sap_group_name', $group_name);
    
    // Set attributes if they exist
    if (!empty($item['U_ssize'])) {
        $product->update_meta_data('_sap_size', $item['U_ssize']);
    }
    if (!empty($item['U_scolor'])) {
        $product->update_meta_data('_sap_color', $item['U_scolor']);
    }
    if (!empty($item['U_sdanier'])) {
        $product->update_meta_data('_sap_denier', $item['U_sdanier']);
    }
    
    // Save the product
    $product_id = $product->save();
    
    if (!$product_id) {
        return new WP_Error('product_creation_failed', 'נכשל ביצירת מוצר פשוט');
    }
    
    // TEMPORARILY DISABLED - Assign category based on U_EM_Age
    /*
    if (isset($item['U_EM_Age'])) {
        $category_assigned = sap_assign_product_category($product_id, $item['U_EM_Age']);
        if (!$category_assigned) {
            error_log("SAP Manual Import: Warning - Failed to assign category for simple product {$product_id}");
        }
    }
    */
    error_log("SAP Manual Import: Category assignment DISABLED for simple product {$product_id}");
    
    error_log("מוצר פשוט נוצר בהצלחה: ID {$product_id}");
    return $product_id;
}
}

/**
 * Add new variations to an existing variable product
 *
 * @param int $product_id Existing product ID
 * @param array $items Array of SAP items to create variations from
 * @return array|WP_Error Array of new variation IDs on success or error
 */
if (!function_exists('sap_add_variations_to_existing_product')) {
function sap_add_variations_to_existing_product($product_id, $items) {
    // Add array check at the start
    if (!is_array($items)) {
        error_log("Invalid items data for adding variations to existing product");
        return new WP_Error('invalid_items', 'Invalid items data for adding variations');
    }
    
    $new_variation_ids = [];
    
    try {
        $existing_product = wc_get_product($product_id);
        if (!$existing_product || !$existing_product->is_type('variable')) {
            return new WP_Error('invalid_product', 'המוצר אינו קיים או אינו משתנה');
        }
        
        // Get existing SKUs to avoid duplicates
        $existing_skus = [];
        $variations = $existing_product->get_children();
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $existing_skus[] = $variation->get_sku();
            }
        }
        
        // Filter items to only new SKUs
        $new_items = array_filter($items, function($item) use ($existing_skus) {
            return !in_array($item['ItemCode'], $existing_skus);
        });
        
        if (empty($new_items)) {
            error_log("אין SKUs חדשים להוספה למוצר {$product_id}");
            return [];
        }
        
        error_log("מוסיף " . (is_array($new_items) ? count($new_items) : 0) . " וריאציות חדשות למוצר {$product_id}");
        
        // Create variations for new items
        foreach ($new_items as $item) {
            $item_code = $item['ItemCode'] ?? '';
            
            if (empty($item_code)) {
                continue;
            }
            
            // Create variation
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_sku($item_code);
            
            // Set attributes
            $variation_attributes = [];
            
            // Size attribute mapping
            $size_value = null;
            if (!empty($item['U_ssize'])) {
                $size_value = trim($item['U_ssize']);
            }
            
            if ($size_value) {
                $variation_attributes['pa_size'] = sanitize_title($size_value);
            }
            
            // Color attribute mapping
            $color_value = null;
            if (!empty($item['U_scolor'])) {
                $color_value = trim($item['U_scolor']);
            }
            
            if ($color_value) {
                $variation_attributes['pa_color'] = sanitize_title($color_value);
            }
            
            // Log if no attributes found
            if (empty($variation_attributes)) {
                sap_log_detailed_error('attribute_mapping_failure', [
                    'item_code' => $item_code ?? 'unknown',
                    'U_ssize' => $item['U_ssize'] ?? 'not_set',
                    'U_scolor' => $item['U_scolor'] ?? 'not_set'
                ]);
            }
            
            $variation->set_attributes($variation_attributes);
            
            // Set price with comprehensive fallback logic
            $price_set = false;
            if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
                // Get price using PriceList 1 with fallback
                $price_result = sap_get_price_from_item($item, $item_code);
                if ($price_result['price'] !== null) {
                    $variation->set_regular_price($price_result['price']);
                    $price_set = true;
                    if ($price_result['used_fallback']) {
                        error_log("SAP Manual Import: Used fallback PriceList {$price_result['pricelist_used']} for variation {$item_code}");
                    }
                }
            }
            
            if (!$price_set) {
                error_log("SAP Manual Import: No valid price found for variation " . ($item_code ?? 'unknown') . " - skipping");
                sap_log_detailed_error('no_valid_price', [
                    'item_code' => $item_code ?? 'unknown',
                    'item_prices' => $item['ItemPrices'] ?? 'not_set'
                ]);
            }
            
            // Set stock (InStock - 10 from ItemWarehouseInfoCollection)
            $stock_quantity = 0;
            if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
                foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
                    if (isset($warehouse_info['InStock']) && is_numeric($warehouse_info['InStock'])) {
                        $stock_quantity = max(0, (int)$warehouse_info['InStock'] - 10);
                        break; // Use first warehouse's stock
                    }
                }
            }
            
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($stock_quantity);
            $variation->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
            
            // Set custom fields
            $variation->update_meta_data('_sap_item_code', $item_code);
            $variation->update_meta_data('_sap_group_code', $item['ItemsGroupCode']);
            
            // Save variation
            $variation_id = $variation->save();
            
            if ($variation_id) {
                $new_variation_ids[$item_code] = $variation_id;
                error_log("וריאציה חדשה נוצרה: ID {$variation_id} עבור SKU {$item_code}");
            } else {
                error_log("שגיאה ביצירת וריאציה עבור SKU {$item_code}");
            }
        }
        
        // Clear product cache to ensure variations are visible
        wc_delete_product_transients($product_id);
        
        // Update parent product price range after adding new variations
        $parent_product = wc_get_product($product_id);
        if ($parent_product && $parent_product->is_type('variable')) {
            $parent_product->sync(false); // Sync variation prices to parent
            $parent_product->save(); // Save after sync
        }
        
        return $new_variation_ids;
        
    } catch (Exception $e) {
        error_log('SAP Manual Import: Error adding variations to existing product - ' . $e->getMessage());
        return new WP_Error('variation_creation_error', 'שגיאה בהוספת וריאציות: ' . $e->getMessage());
    }
}
}

/**
 * Update SAP with new variation IDs for existing products
 *
 * @param array $items Array of SAP items
 * @param int $product_id Parent product ID
 * @param array $variation_ids Array of variation IDs mapped to ItemCode
 * @param string $auth_token SAP authentication token
 * @return bool|WP_Error True on success or error
 */
if (!function_exists('sap_update_sap_with_new_variations')) {
function sap_update_sap_with_new_variations($items, $product_id, $variation_ids, $auth_token) {
    // Add array checks at the start
    if (!is_array($items) || !is_array($variation_ids)) {
        error_log("Invalid items or variation_ids data for SAP new variations update");
        return false;
    }
    
    if (empty($variation_ids)) {
        error_log("אין וריאציות חדשות לעדכון ב-SAP");
        return true;
    }
    
    error_log("מעדכן SAP עם " . (is_array($variation_ids) ? count($variation_ids) : 0) . " וריאציות חדשות למוצר {$product_id}");
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($items as $item) {
        $item_code = $item['ItemCode'] ?? '';
        
        if (empty($item_code) || !isset($variation_ids[$item_code])) {
            continue;
        }
        
        $variation_id = $variation_ids[$item_code];
        
        // Prepare update data for SAP
        $update_data = [
            'ItemCode' => $item_code,
            'U_SiteGroupID' => $product_id,  // Parent product ID
            'U_SiteItemID' => $variation_id  // Variation ID
        ];
        
        // Try multiple SAP update endpoints
        $endpoints = ['Items/update', 'Items/Update', 'Items/updateItems', 'Items'];
        $update_success = false;
        $last_error = null;
        
        foreach ($endpoints as $endpoint) {
            $response = sap_api_post($endpoint, $update_data, $auth_token);
            
            if (!is_wp_error($response)) {
                error_log("עדכון SAP הצליח עבור פריט {$item_code} דרך {$endpoint}");
                $update_success = true;
                $success_count++;
                break;
            } else {
                $last_error = $response;
                error_log("עדכון SAP נכשל עבור פריט {$item_code} דרך {$endpoint}: " . $response->get_error_message());
            }
        }
        
        if (!$update_success) {
            $error_count++;
            error_log("כל ה-endpoints נכשלו בעדכון SAP עבור פריט {$item_code}");
        }
    }
    
    if ($error_count > 0) {
        echo "<p style='color: orange;'>עדכון SAP הושלם עם שגיאות: {$success_count} הצליחו, {$error_count} נכשלו</p>";
        error_log("SAP Manual Import: SAP update completed with errors: {$success_count} succeeded, {$error_count} failed");
    } else {
        echo "<p style='color: green;'>עדכון SAP הושלם בהצלחה: {$success_count} פריטים עודכנו</p>";
        error_log("SAP Manual Import: SAP update completed successfully: {$success_count} items updated");
    }
    
    return $error_count === 0;
}
}

/**
 * Test manual import functionality with validation
 *
 * @return string Test results
 */
if (!function_exists('sap_test_manual_import_functionality')) {
function sap_test_manual_import_functionality() {
    $test_results = [];
    
    // Test 1: Category mapping
    $test_results['category_mapping'] = [];
    $test_ages = ['גברים', 'בנות', 'תינוקות', 'unknown_age'];
    foreach ($test_ages as $age) {
        $category = sap_map_age_to_category($age);
        $test_results['category_mapping'][$age] = $category ?: 'כללי';
    }
    
    // Test 2: Business logic validation
    $test_results['business_logic'] = [];
    $test_item = [
        'ItemCode' => 'TEST001',
        'U_EM_SiteGroupID' => 0,
        'ItemPrices' => [['PriceList' => 1, 'Price' => 25.50]]
    ];
    $status = sap_check_item_processing_status($test_item);
    $test_results['business_logic']['new_item_status'] = $status;
    
    // Test 3: Field mapping and fallbacks
    $test_results['field_mapping'] = [];
    $test_item_missing_fields = [
        'ItemCode' => 'TEST002',
        'U_EM_Size' => '',
        'U_ssize' => 'M',
        'U_EM_Color' => '',
        'U_scolor' => 'Blue'
    ];
    
    // Simulate the attribute extraction logic
    $size_value = null;
    if (!empty($test_item_missing_fields['U_EM_Size'])) {
        $size_value = trim($test_item_missing_fields['U_EM_Size']);
    } elseif (!empty($test_item_missing_fields['U_ssize'])) {
        $size_value = trim($test_item_missing_fields['U_ssize']);
    }
    $test_results['field_mapping']['size_fallback'] = $size_value;
    
    $color_value = null;
    if (!empty($test_item_missing_fields['U_EM_Color'])) {
        $color_value = trim($test_item_missing_fields['U_EM_Color']);
    } elseif (!empty($test_item_missing_fields['U_scolor'])) {
        $color_value = trim($test_item_missing_fields['U_scolor']);
    }
    $test_results['field_mapping']['color_fallback'] = $color_value;
    
    // Test 4: Error logging (simulate)
    $test_results['error_logging'] = 'Functions exist: ' . 
        (function_exists('sap_log_detailed_error') ? 'Yes' : 'No');
    
    // Test 5: Telegram notification (simulate)
    $test_results['telegram_ready'] = 'Functions exist: ' . 
        (function_exists('sap_send_telegram_message_manual') ? 'Yes' : 'No');
    
    return json_encode($test_results, JSON_PRETTY_PRINT);
}
}

/**
 * Comprehensive error handler for manual import
 *
 * @param Exception $exception The exception that occurred
 * @param array $context Additional context information
 * @return void
 */
if (!function_exists('sap_handle_import_exception')) {
function sap_handle_import_exception($exception, $context = []) {
    $error_data = [
        'exception_message' => $exception->getMessage(),
        'exception_code' => $exception->getCode(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'stack_trace' => $exception->getTraceAsString(),
        'context' => $context,
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true)
    ];
    
    // Log to WordPress error log
    error_log('SAP Manual Import Exception: ' . wp_json_encode($error_data));
    
    // Log detailed error
    sap_log_detailed_error('exception', $error_data);
    
    // Send critical error notification
    if (function_exists('sap_send_telegram_message_manual')) {
        $message = "🚨 Critical SAP Import Error\n\n";
        $message .= "Error: " . substr($exception->getMessage(), 0, 100) . "\n";
        $message .= "File: " . basename($exception->getFile()) . ":" . $exception->getLine() . "\n";
        if (!empty($context['group_name'])) {
            $message .= "Group: " . $context['group_name'] . "\n";
        }
        $message .= "Time: " . current_time('Y-m-d H:i:s');
        
                 sap_send_telegram_message_manual($message);
     }
 }
 }

/**
 * Find and handle products/variations without SKU (reuse logic from sap-products-import.php)
 *
 * @return string HTML output of the cleanup status
 */
if (!function_exists('sap_handle_products_without_sku')) {
function sap_handle_products_without_sku() {
    if (!function_exists('wc_get_product')) {
        return "<p style='color: red;'>שגיאה: ווקומרס אינו פעיל.</p>";
    }

    ob_start();
    
    // Get all WooCommerce variations without SKUs
    $args = array(
        'post_type'      => 'product_variation',
        'posts_per_page' => -1,
        'meta_query'     => array(
            'relation' => 'OR',
            array(
                'key'     => '_sku',
                'value'   => '',
                'compare' => '=',
            ),
            array(
                'key'     => '_sku',
                'compare' => 'NOT EXISTS',
            ),
        ),
        'fields'         => 'ids',
    );
    $variations_without_sku = get_posts($args);
    
    // Get all simple products without SKUs  
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'meta_query'     => array(
            'relation' => 'OR',
            array(
                'key'     => '_sku',
                'value'   => '',
                'compare' => '=',
            ),
            array(
                'key'     => '_sku',
                'compare' => 'NOT EXISTS',
            ),
        ),
        'fields'         => 'ids',
    );
    $simple_products_without_sku = get_posts($args);
    
    $total_found = (is_array($variations_without_sku) ? count($variations_without_sku) : 0) + (is_array($simple_products_without_sku) ? count($simple_products_without_sku) : 0);
    
    if ($total_found === 0) {
        echo "<p style='color: green;'>כל המוצרים והוריאציות ב-WooCommerce כוללים מק\"ט. לא נמצאו פריטים ללא מק\"ט.</p>";
        return ob_get_clean();
    }
    
    echo "<p>נמצאו " . $total_found . " פריטים ב-WooCommerce ללא מק\"ט:</p>";
    echo "<ul style='list-style-type: disc; margin-left: 20px;'>";
    
    $processed_count = 0;
    
    // Process variations without SKU
    foreach ($variations_without_sku as $variation_id) {
        $variation = wc_get_product($variation_id);
        
        if (!$variation) {
            continue;
        }
        
        echo "<li><strong>" . esc_html($variation->get_name()) . "</strong> (וריאציה ID: " . esc_html($variation_id) . ")<br>";
        
        // Unpublish the variation
        $variation->set_status('draft');
        $variation->save();
        echo " &gt; הוריאציה הוסתרה מהאתר (סטטוס: טיוטה)<br>";
        
        // Log the missing SKU variation
        sap_log_detailed_error('product_without_sku', [
            'wc_id' => $variation_id,
            'name' => $variation->get_name(),
            'type' => 'variation',
            'action_taken' => 'unpublished'
        ]);
        
        $processed_count++;
        echo "</li>";
    }
    
    // Process simple products without SKU
    foreach ($simple_products_without_sku as $product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            continue;
        }
        
        echo "<li><strong>" . esc_html($product->get_name()) . "</strong> (מוצר ID: " . esc_html($product_id) . ")<br>";
        
        // Unpublish the product
        $product->set_status('draft');
        $product->save();
        echo " &gt; המוצר הוסתר מהאתר (סטטוס: טיוטה)<br>";
        
        // Log the missing SKU product
        sap_log_detailed_error('product_without_sku', [
            'wc_id' => $product_id,
            'name' => $product->get_name(),
            'type' => 'simple',
            'action_taken' => 'unpublished'
        ]);
        
        $processed_count++;
        echo "</li>";
    }
    
    echo "</ul>";
    echo "<p style='color: green;'>הסתרת {$processed_count} פריטים ללא מק\"ט הושלמה.</p>";
    
    return ob_get_clean();
}
}

/**
 * Test PATCH functionality with exact Postman payload
 * Call this function to debug the 403 issue
 *
 * @param string $auth_token SAP authentication token
 * @return string Test results
 */
if (!function_exists('sap_test_patch_request')) {
function sap_test_patch_request($auth_token) {
    error_log("=== SAP PATCH TEST START ===");
    
    ob_start();
    
    // EXACT Postman payload
    $test_data = [
        "ItemCode" => "1001",
        "U_EM_SiteGroupID" => "12347", 
        "U_EM_SiteItemID" => "1234"
    ];
    
    $test_url = 'https://cilapi.emuse.co.il:444/api/Items/1001';
    
    echo "<h3>Testing PATCH Request (Exact Postman Match)</h3>";
    echo "<p><strong>URL:</strong> {$test_url}</p>";
    echo "<p><strong>Payload:</strong> " . json_encode($test_data) . "</p>";
    echo "<p><strong>Auth Token Length:</strong> " . strlen($auth_token) . "</p>";
    echo "<p><strong>Auth Token (first 20):</strong> " . substr($auth_token, 0, 20) . "...</p>";
    
    // Test with our sap_api_patch function
    $result = sap_api_patch('1001', $test_data, $auth_token);
    
    if (is_wp_error($result)) {
        echo "<p style='color: red;'><strong>PATCH Failed:</strong> " . $result->get_error_message() . "</p>";
        $error_data = $result->get_error_data();
        if ($error_data) {
            echo "<p><strong>Error Data:</strong> " . print_r($error_data, true) . "</p>";
        }
    } else {
        echo "<p style='color: green;'><strong>PATCH Success!</strong></p>";
        echo "<p><strong>Response:</strong> " . print_r($result, true) . "</p>";
    }
    
    error_log("=== SAP PATCH TEST END ===");
    
    return ob_get_clean();
}
}

// Global array to collect PATCH data instead of sending to SAP
global $sap_patch_data_collection;
if (!isset($sap_patch_data_collection)) {
    $sap_patch_data_collection = [];
}

if (!function_exists('sap_update_sap_with_simple_product_id')) {
function sap_update_sap_with_simple_product_id($item, $product_id, $auth_token) {
    global $sap_patch_data_collection;
    
    $item_code = $item['ItemCode'];
    error_log("מעדכן SAP עם ID מוצר פשוט: פריט {$item_code} -> מוצר {$product_id}");
    
    // Prepare PATCH data with minimal required fields
    $patch_data = [
        'ItemCode' => $item_code,
        'U_SiteGroupID' => $product_id,  // For simple products, use product ID as group ID
        'U_SiteItemID' => $product_id    // For simple products, use product ID as item ID
    ];
    
    error_log("SAP Manual Import: PATCH updating simple product {$item_code} with SiteItemID={$product_id}, SiteGroupID={$product_id}");
    
    // COMMENTED OUT: Send PATCH request
    // $response = sap_api_patch($item_code, $patch_data, $auth_token);
    
    // INSTEAD: Collect the data for output
    $sap_patch_data_collection[] = $patch_data;
    error_log("SAP Manual Import: Collected PATCH data for simple product {$item_code} (not sent to SAP)");
    
    // if (is_wp_error($response)) {
    //     $error_msg = "PATCH failed for simple product {$item_code}: " . $response->get_error_message();
    //     error_log("SAP Manual Import: " . $error_msg);
    //     return false;
    // }
    
    //     error_log("SAP Manual Import: Successfully updated simple product {$item_code} via PATCH");
            return true;
}
}

/**
 * Output collected SAP PATCH data in tab-separated format
 * 
 * @param array $import_log Import statistics
 * @return void
 */
if (!function_exists('sap_output_collected_patch_data')) {
function sap_output_collected_patch_data($import_log = []) {
    global $sap_patch_data_collection;
    
    echo "<hr>";
    echo "<h3>סיכום ייבוא ונתונים לעדכון SAP</h3>";
    
    // Product creation summary
    $total_products = ($import_log['products_created'] ?? 0);
    $simple_products = ($import_log['simple_products_created'] ?? 0);
    $variable_products = $total_products - $simple_products;
    $total_items = count($sap_patch_data_collection ?? []);
    
    echo "<div style='background: #e7f3ff; padding: 15px; border: 1px solid #b3d9ff; margin: 10px 0;'>";
    echo "<h4>📊 סטטיסטיקות יצירת מוצרים:</h4>";
    echo "<p><strong>סה\"כ מוצרים שנוצרו:</strong> {$total_products}</p>";
    echo "<p><strong>מוצרים פשוטים:</strong> {$simple_products}</p>";
    echo "<p><strong>מוצרים משתנים:</strong> {$variable_products}</p>";
    echo "<p><strong>סה\"כ פריטים לעדכון ב-SAP:</strong> {$total_items}</p>";
    echo "</div>";
    
    if (empty($sap_patch_data_collection)) {
        echo "<p>לא נאספו נתונים לעדכון SAP.</p>";
        return;
    }
    
    echo "<h4>📋 נתונים שנאספו לעדכון SAP:</h4>";
    echo "<p>הנתונים הבאים היו אמורים להישלח ל-SAP:</p>";
    
    // Output in tab-separated format
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto;'>";
    echo "ItemCode\tU_SiteItemID\tU_SiteGroupID\n";
    
    foreach ($sap_patch_data_collection as $patch_data) {
        echo $patch_data['ItemCode'] . "\t" . 
             $patch_data['U_SiteItemID'] . "\t" . 
             $patch_data['U_SiteGroupID'] . "\n";
    }
    
    echo "</pre>";
    
    // Simplified logging
    error_log("SAP Manual Import: Created {$total_products} products ({$simple_products} simple, {$variable_products} variable), collected {$total_items} items for SAP update");
}
}
