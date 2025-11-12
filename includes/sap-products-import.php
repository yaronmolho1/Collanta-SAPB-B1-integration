<?php
/**
 * SAP Stock Update - Simple stock synchronization based on SKU
 *
 * @package My_SAP_Importer
 * @subpackage Includes
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Telegram notification configuration
define('SAP_TELEGRAM_BOT_TOKEN', '8309945060:AAHKHfGtTf6D_U_JnapGrTHxOLcuht9ULA4');
define('SAP_TELEGRAM_CHAT_ID', '5418067438');

if (!function_exists('sap_api_post')) {
    function sap_api_post($endpoint, $data = [], $token = null)
    {
        // Use streaming API for large requests (Items/get) or fallback for others
        if ($endpoint === 'Items/get' && !empty($data)) {
            return sap_api_post_streaming($endpoint, $data, $token);
        }
        
        // Regular API call for small requests (login, etc.)
        $url = trailingslashit(SAP_API_BASE) . ltrim($endpoint, '/');
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $args = [
            'method'      => 'POST',
            'headers'     => $headers,
            'body'        => wp_json_encode($data),
            'timeout'     => 60,
            'data_format' => 'body',
            'sslverify'   => false,
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            error_log('SAP API Post Error (' . $endpoint . '): ' . $response->get_error_message());
            return new WP_Error('api_error', 'SAP API Post Error (' . $endpoint . '): ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        
        if ($http_code !== 200) {
            error_log("SAP API HTTP Error {$http_code} for {$endpoint}: " . substr($body, 0, 500));
            return new WP_Error('sap_api_http_error', "HTTP {$http_code} for {$endpoint}");
        }

        // Apply the same robust JSON parsing logic
        return sap_parse_api_response($body, $endpoint);
    }
}

/**
 * SAP API POST with streaming support for large responses
 * CRITICAL: Handles SAP's streaming response body (25+ second streams)
 */
if (!function_exists('sap_api_post_streaming')) {
function sap_api_post_streaming($endpoint, $data, $auth_token) {
    if (!function_exists('curl_init')) {
        return new WP_Error('curl_not_available', 'cURL is required for streaming SAP API calls');
    }
    
    $url = SAP_API_BASE . '/' . $endpoint;
    
    error_log("=== SAP STREAMING API START ===");
    error_log("SAP Streaming URL: {$url}");
    
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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_BUFFERSIZE => 16384,      // Larger buffer for streaming (16KB)
        CURLOPT_NOPROGRESS => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_ENCODING => '',
    ]);
    
    $start_time = microtime(true);
    $response = curl_exec($ch);
    
    $end_time = microtime(true);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total_time = round($end_time - $start_time, 2);
    $response_size = strlen($response);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    error_log("SAP Streaming: {$total_time}s, {$response_size} bytes, HTTP {$http_code}");
    error_log("=== SAP STREAMING API END ===");
    
    if ($curl_error) {
        error_log("SAP Streaming cURL Error: " . $curl_error);
        return new WP_Error('curl_error', $curl_error);
    }
    
    if ($http_code !== 200) {
        error_log("SAP Streaming HTTP Error {$http_code}: " . substr($response, 0, 500));
        return new WP_Error('sap_api_http_error', "HTTP {$http_code}", [
            'http_code' => $http_code,
            'response_body' => $response,
            'total_time' => $total_time
        ]);
    }
    
    // Parse response using concatenated JSON parser (handles broken SAP format)
    $decoded_response = sap_parse_concatenated_json($response);
    
    // Check if parsing was successful
    if (is_array($decoded_response) && !empty($decoded_response)) {
        error_log("SAP Streaming: Parsed " . count($decoded_response) . " objects");
        return $decoded_response;
    }
    
    // Fallback: try standard JSON decode
    $decoded_response = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("SAP Streaming: JSON decode error: " . json_last_error_msg());
        return new WP_Error('json_decode_error', 'Failed to decode JSON: ' . json_last_error_msg());
    }
    
    return $decoded_response;
}
}

/**
 * Parse API response with multiple format support
 */
if (!function_exists('sap_parse_api_response')) {
function sap_parse_api_response($body, $endpoint) {
    if (empty($body) || trim($body) === '') {
        return [];
    }
    
    $body = sap_clean_json_response($body);
    $decoded_body = json_decode($body, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
    
    if ($decoded_body === null) {
        error_log("SAP API JSON decode error for {$endpoint}: " . json_last_error_msg());
        return new WP_Error('json_decode_error', 'Failed to decode JSON for ' . $endpoint);
    }
    
    return $decoded_body;
}
}

/**
 * Clean SAP JSON response by removing control characters
 */
if (!function_exists('sap_clean_json_response')) {
function sap_clean_json_response($response) {
    // Remove UTF-8 BOM if present
    if (substr($response, 0, 3) === "\xEF\xBB\xBF") {
        $response = substr($response, 3);
    }
    
    // Remove ALL control characters
    $response = preg_replace('/[\x00-\x1F\x7F]/', '', $response);
    
    // Normalize line breaks
    $response = preg_replace('/\r\n/', '\n', $response);
    $response = preg_replace('/\r/', '\n', $response);
    
    // Fix common JSON structural issues
    $response = trim($response);
    $response = preg_replace('/,(\s*[}\]])/', '$1', $response);
    
    // Validate UTF-8 encoding
    if (!mb_check_encoding($response, 'UTF-8')) {
        $response = mb_convert_encoding($response, 'UTF-8', 'UTF-8');
    }
    
    return $response;
}
}

/**
 * Parse concatenated JSON objects from SAP
 */
if (!function_exists('sap_parse_concatenated_json')) {
function sap_parse_concatenated_json($response) {
    $response = sap_clean_json_response($response);
    
    if (empty($response)) {
        return [];
    }
    
    // Check if it's already a valid JSON array
    if ($response[0] === '[') {
        $decoded = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    
    // Check for {"value": [...]} wrapper format
    if (preg_match('/^\{"value":\s*(\[.*\])\}$/s', $response, $matches)) {
        $array_json = $matches[1];
        $decoded = json_decode($array_json, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
        if ($decoded !== null && is_array($decoded)) {
            return $decoded;
        }
    }
    
    // Check if it's a single object
    $single_object = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
    
    if ($single_object !== null && json_last_error() === JSON_ERROR_NONE) {
        if (is_array($single_object)) {
            // Check if this is a single item object (has ItemCode)
            if (isset($single_object['ItemCode'])) {
                return [$single_object];
            }
            
            // Check for 'value' array
            if (isset($single_object['value']) && is_array($single_object['value'])) {
                return $single_object['value'];
            }
        }
        
        return [$single_object];
    }
    
    // Split by "}{"  (boundary between concatenated objects)
    $json_objects = [];
    $parts = explode('}{', $response);
    
    if (count($parts) === 1) {
        $whole_object = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
        if ($whole_object !== null && json_last_error() === JSON_ERROR_NONE) {
            if (isset($whole_object['value']) && is_array($whole_object['value'])) {
                return $whole_object['value'];
            }
            return [$whole_object];
        }
        return [];
    }
    
    foreach ($parts as $index => $part) {
        // Add missing braces back
        if ($index === 0) {
            $json_object = rtrim($part);
            if (substr($json_object, -1) !== '}') {
                $json_object .= '}';
            }
        } elseif ($index === count($parts) - 1) {
            $json_object = ltrim($part);
            if (substr($json_object, 0, 1) !== '{') {
                $json_object = '{' . $json_object;
            }
        } else {
            $json_object = '{' . trim($part) . '}';
        }
        
        $decoded = json_decode($json_object, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
        
        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
            $json_objects[] = $decoded;
        }
    }
    
    return $json_objects;
}
}

/**
 * Get authentication token from SAP API
 */
if (!function_exists('sap_get_auth_token')) {
function sap_get_auth_token() {
    $loginData = [
        'username' => SAP_API_USERNAME,
        'password' => SAP_API_PASSWORD
    ];

    $loginResponse = sap_api_post('Login/login', $loginData);

    if (is_wp_error($loginResponse)) {
        error_log('SAP Login Error: ' . $loginResponse->get_error_message());
        return false;
    }

    if (!isset($loginResponse['token'])) {
        error_log('SAP Login Error: No token received');
        return false;
    }

    return $loginResponse['token'];
}
}

/**
 * Send message to Telegram
 */
function sap_send_telegram_message($message) {
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
        'timeout' => 15
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

/**
 * Main function to update stock from SAP API - SIMPLIFIED VERSION
 * Only updates stock quantities based on SKU matching
 *
 * @param string|null $item_code_filter Optional single item code to update
 * @return string HTML output of the update status
 */
if (!function_exists('sap_update_variations_from_api')) {
    function sap_update_variations_from_api($item_code_filter = null)
    {
    if (!function_exists('wc_get_product')) {
        return "<p style='color: red;'>שגיאה: ווקומרס אינו פעיל.</p>";
    }

    ob_start();

    echo "<h2>מתחיל עדכון מלאי מ-SAP...</h2>";
    
    // Send start notification
    $start_message = "עדכון מלאי מ-SAP החל\n";
    if (!empty($item_code_filter)) {
        $start_message .= "פריט בודד: " . $item_code_filter . "\n";
    } else {
        $start_message .= "מעדכן את כל הפריטים המקושרים\n";
    }
    $start_message .= "זמן: " . current_time('Y-m-d H:i:s');
    sap_send_telegram_message($start_message);

    // 1. Get authentication token
    echo "<p>⏳ מתחבר ל-SAP API...</p>";
    flush();
    
    $token = sap_get_auth_token();
    
    if (!$token) {
        echo "<p style='color: red;'>שגיאה: נכשל בחיבור ל-SAP API.</p>";
        return ob_get_clean();
    }

    echo "<p style='color: green;'>✅ התחברות ל-SAP API בוצעה בהצלחה.</p>";

    // 2. Request only necessary fields for stock update
    $itemsRequest = [
        "selectObjects" => [
            ["field" => "ItemCode"],
            ["field" => "ItemName"],
            ["field" => "U_SiteItemID"],
            ["field" => "U_SiteGroupID"],
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

    echo "<p>⏳ שולח בקשה ל-SAP API לשליפת מלאי...</p>";
    flush();

    $itemsResponse = sap_api_post('Items/get', $itemsRequest, $token);
    
    if (is_wp_error($itemsResponse)) {
        echo "<p style='color: red;'>❌ שגיאה: " . esc_html($itemsResponse->get_error_message()) . "</p>";
        return ob_get_clean();
    }

    echo "<p style='color: green;'>✅ תגובה התקבלה!</p>";
    flush();

    // 3. Parse items from response
    $items = [];
    
    if (is_array($itemsResponse) && isset($itemsResponse[0]) && is_array($itemsResponse[0]) && isset($itemsResponse[0]['ItemCode'])) {
        $items = $itemsResponse;
    } elseif (isset($itemsResponse['value']) && is_array($itemsResponse['value'])) {
        $items = $itemsResponse['value'];
    } else {
        $items = $itemsResponse;
    }

    if (empty($items)) {
        echo "<p style='color: red;'>לא נמצאו פריטים מ-SAP API.</p>";
        return ob_get_clean();
    }
    
    $total_items = count($items);
    echo "<p>נתקבלו " . $total_items . " פריטים מ-SAP</p>";
    
    // 4. Filter items
    $filtered_items = [];
    
    if (!empty($item_code_filter)) {
        // Single item filter
        foreach ($items as $item) {
            if (isset($item['ItemCode']) && $item['ItemCode'] === $item_code_filter) {
                $filtered_items[] = $item;
            }
        }
        echo "<p>סינון עבור פריט '{$item_code_filter}': נמצאו " . count($filtered_items) . " פריטים</p>";
    } else {
        // Filter for items with U_SiteItemID or U_SiteGroupID
        foreach ($items as $item) {
            $has_site_item_id = !empty($item['U_SiteItemID']) && $item['U_SiteItemID'] > 0;
            $has_site_group_id = !empty($item['U_SiteGroupID']) && $item['U_SiteGroupID'] > 0;
            
            if ($has_site_item_id || $has_site_group_id) {
                $filtered_items[] = $item;
            }
        }
        echo "<p>סינון עבור פריטים מקושרים: נמצאו " . count($filtered_items) . " פריטים</p>";
    }
    
    $items = $filtered_items;
    
    if (empty($items)) {
        echo "<p style='color: orange;'>לא נמצאו פריטים לעדכון.</p>";
        return ob_get_clean();
    }

    // 5. Update stock for each item
    echo "<h3>מעדכן מלאי:</h3>";
    echo "<ul style='list-style-type: disc; margin-left: 20px;'>";

    $stats = [
        'processed' => 0,
        'updated' => 0,
        'not_found' => 0,
        'errors' => 0
    ];
    
    $failed_items = []; // Track items that couldn't be matched

    foreach ($items as $item) {
        $sap_item_code = $item['ItemCode'] ?? '';
        $sap_item_name = $item['ItemName'] ?? 'Unknown';
        $sap_site_item_id = $item['U_SiteItemID'] ?? null;
        
        if (empty($sap_item_code)) {
            continue;
        }
        
        $stats['processed']++;
        
        // Get stock from ItemWarehouseInfoCollection
        $sap_stock = 0;
        if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
            foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
                if (isset($warehouse_info['InStock'])) {
                    $sap_stock = $warehouse_info['InStock'];
                    break; // Use first warehouse
                }
            }
        }
        
        // Deduct 10 from stock as safety buffer
        $adjusted_stock = max(0, $sap_stock - 10);
        
        $product_id = null;
        $match_method = '';
        
        // Primary: Try to find product by SKU (works for both simple products and variations)
        $product_id = wc_get_product_id_by_sku($sap_item_code);
        
        if ($product_id) {
            $match_method = 'SKU';
        } elseif (!empty($sap_site_item_id) && is_numeric($sap_site_item_id)) {
            // Fallback: Try to match by U_SiteItemID
            $product = wc_get_product($sap_site_item_id);
            if ($product) {
                $product_id = $sap_site_item_id;
                $match_method = 'SiteItemID';
            }
        }
        
        if (!$product_id) {
            $stats['not_found']++;
            $failed_items[] = [
                'item_code' => $sap_item_code,
                'item_name' => $sap_item_name,
                'site_item_id' => $sap_site_item_id,
                'reason' => 'לא נמצא התאמה ב-SKU או SiteItemID'
            ];
            error_log("SAP Stock Update: Item {$sap_item_code} not found - SKU match failed, SiteItemID: " . ($sap_site_item_id ?? 'null'));
            continue;
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            $stats['not_found']++;
            $failed_items[] = [
                'item_code' => $sap_item_code,
                'item_name' => $sap_item_name,
                'site_item_id' => $sap_site_item_id,
                'reason' => 'מוצר לא תקין'
            ];
            continue;
        }
        
        // Update stock - always update even if 0 or negative
        if (is_numeric($sap_stock)) {
            // Always keep manage_stock enabled - don't touch this setting
            $product->set_stock_quantity($adjusted_stock);
            $product->set_stock_status($adjusted_stock > 0 ? 'instock' : 'outofstock');
            $product->save();
            
            $stats['updated']++;
            echo "<li>{$sap_item_code} - {$sap_item_name}: מלאי עודכן ל-{$adjusted_stock} (SAP: {$sap_stock}, -10) [{$match_method}]</li>";
        } else {
            // Only error if stock is not numeric at all
            $stats['errors']++;
            $failed_items[] = [
                'item_code' => $sap_item_code,
                'item_name' => $sap_item_name,
                'site_item_id' => $sap_site_item_id,
                'reason' => 'מלאי לא תקין ב-SAP (לא מספרי)'
            ];
        }
    }

    echo "</ul>";
    
    // Display failed items if any
    if (!empty($failed_items)) {
        echo "<h3 style='color: orange;'>פריטים שנכשלו:</h3>";
        echo "<ul style='list-style-type: disc; margin-left: 20px;'>";
        foreach ($failed_items as $failed) {
            echo "<li>{$failed['item_code']} - {$failed['item_name']} (SiteItemID: {$failed['site_item_id']}): {$failed['reason']}</li>";
        }
        echo "</ul>";
    }
    
    echo "<p style='color: green;'>תהליך עדכון מלאי הסתיים.</p>";
    
    // Summary
    echo "<h3>סיכום:</h3>";
    echo "<ul>";
    echo "<li>פריטים שעובדו: {$stats['processed']}</li>";
    echo "<li>מלאי עודכן: {$stats['updated']}</li>";
    echo "<li>לא נמצאו: {$stats['not_found']}</li>";
    echo "<li>שגיאות: {$stats['errors']}</li>";
    echo "</ul>";

    // Send completion notification in Hebrew
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
    
    sap_send_telegram_message($complete_message);

    return ob_get_clean();
}
}

/**
 * Daily import task - updates stock for all linked items
 */
function sap_run_daily_import_task() {
    $log_output = sap_update_variations_from_api();
    error_log('Daily SAP Stock Update: ' . strip_tags($log_output));
    return $log_output;
}

/**
 * Schedule daily import cron job
 * Runs every day at 02:00 AM
 */
if (!function_exists('sap_schedule_daily_import')) {
    function sap_schedule_daily_import() {
    if ( ! wp_next_scheduled( 'sap_daily_import_event' ) ) {
        wp_schedule_event( strtotime( '02:00:00' ), 'daily', 'sap_daily_import_event' );
    }
}
}
add_action( 'wp', 'sap_schedule_daily_import' );
add_action( 'sap_daily_import_event', 'sap_run_daily_import_task' );