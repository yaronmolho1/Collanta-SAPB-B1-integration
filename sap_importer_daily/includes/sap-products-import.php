<?php
/**
 * SAP Product Import and Variation Update Functions
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
 *
 * @param string $endpoint API endpoint (e.g., 'Items/get')
 * @param array $data Request data
 * @param string $auth_token SAP authentication token
 * @return array|WP_Error Decoded response or error
 */
if (!function_exists('sap_api_post_streaming')) {
function sap_api_post_streaming($endpoint, $data, $auth_token) {
    if (!function_exists('curl_init')) {
        return new WP_Error('curl_not_available', 'cURL is required for streaming SAP API calls');
    }
    
    $url = SAP_API_BASE . '/' . $endpoint;
    
    error_log("=== SAP STREAMING API START ===");
    error_log("SAP Streaming URL: {$url}");
    error_log("SAP Streaming Data count: " . (isset($data['selectObjects']) ? count($data['selectObjects']) : 'N/A'));
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
    
    curl_close($ch);
    
    error_log("SAP Streaming Results:");
    error_log("- Total time: {$total_time}s");
    error_log("- Response size: {$response_size} bytes");
    error_log("- HTTP code: {$http_code}");
    error_log("- cURL error: " . ($curl_error ?: 'none'));
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
    
    // Log successful response summary
    error_log("SAP Streaming Success: {$total_time}s, {$response_size} bytes received");
    
    // Parse response using concatenated JSON parser (handles broken SAP format)
    error_log("SAP Streaming: Attempting to parse response using concatenated JSON parser...");
    $decoded_response = sap_parse_concatenated_json($response);
    
    // Check if parsing was successful
    if (is_array($decoded_response) && !empty($decoded_response)) {
        error_log("SAP Streaming: Concatenated JSON parser succeeded with " . count($decoded_response) . " objects");
        return $decoded_response;
    }
    
    // Fallback: try standard JSON decode if concatenated parser fails
    error_log("SAP Streaming: Concatenated parser failed, trying standard JSON decode as fallback...");
    $decoded_response = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
    $json_error = json_last_error();
    
    if ($json_error !== JSON_ERROR_NONE) {
        error_log("SAP Streaming: JSON decode error: " . json_last_error_msg());
        return new WP_Error('json_decode_error', 'Failed to decode JSON: ' . json_last_error_msg(), [
            'json_error_code' => $json_error,
            'response_size' => $response_size,
            'first_chars' => substr($response, 0, 100),
            'last_chars' => substr($response, -100)
        ]);
    }
    
    error_log("SAP Streaming: JSON decode successful!");
    return $decoded_response;
}
}

/**
 * Parse API response with multiple format support
 * Handles both old and new SAP API response formats
 *
 * @param string $body Raw response body
 * @param string $endpoint Endpoint for logging
 * @return array|WP_Error Parsed response
 */
if (!function_exists('sap_parse_api_response')) {
function sap_parse_api_response($body, $endpoint) {
    // Handle empty response body (200 status but no content)
    if (empty($body) || trim($body) === '') {
        error_log('SAP API Parse: Empty response body received for ' . $endpoint . ' - returning empty array');
        return []; // Return empty array for empty responses
    }
    
    // Clean the response first
    $body = sap_clean_json_response($body);
    
    $decoded_body = json_decode($body, true, 512, JSON_INVALID_UTF8_IGNORE | JSON_BIGINT_AS_STRING);
    
    if ($decoded_body === null) {
        $json_error = json_last_error();
        error_log("SAP API JSON decode error for {$endpoint}: " . json_last_error_msg());
        return new WP_Error('json_decode_error', 'Failed to decode JSON for ' . $endpoint . ': ' . json_last_error_msg());
    }
    
    // Check if the response has the specific structure with 'apiResponse.result.data'
    if (isset($decoded_body['apiResponse']['result']['data'])) {
        if (is_string($decoded_body['apiResponse']['result']['data'])) {
            // Old format: nested JSON string
            $inner_data_json = $decoded_body['apiResponse']['result']['data'];
            $inner_decoded_data = json_decode($inner_data_json, true);

            if ($inner_decoded_data !== null) {
                $decoded_body['apiResponse']['result']['data'] = $inner_decoded_data;
                error_log("SAP API: Successfully decoded nested JSON for {$endpoint}");
            } else {
                error_log("Failed to decode inner JSON data from SAP API response for {$endpoint}");
            }
        }
        // If data is already an array, it's already properly decoded
    }
    
    return $decoded_body;
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
    
    // CRITICAL: Remove ALL control characters that break JSON parsing
    $original_length = strlen($response);
    $response = preg_replace('/[\x00-\x1F\x7F]/', '', $response);
    $cleaned_length = strlen($response);
    
    if ($original_length !== $cleaned_length) {
        $removed_chars = $original_length - $cleaned_length;
        error_log("SAP JSON Cleaner: Removed {$removed_chars} control characters");
    }
    
    // Handle line breaks and tabs in JSON strings (normalize but don't remove)
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
    
    // Check for empty response first
    if (empty($response)) {
        error_log("SAP Concatenated Parser: Empty response received - this usually means no items match the filter criteria");
        return [];
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
    error_log("SAP Concatenated Parser: Successfully parsed {$total_parsed} objects from concatenated response");
    
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
 * Get authentication token from SAP API
 * Reused from manual import logic
 *
 * @return string|false Auth token on success, false on failure
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
 * Main function to update product variations from SAP API.
 * Can receive a single item code, a range of codes, or no input (for all items).
 *
 * @param string|array|null $item_codes_to_filter Single item code (string), array of code ranges (e.g., ['60001', '60030']), or null for no filter.
 * @return string HTML output of the import status.
 */
if (!function_exists('sap_update_variations_from_api')) {
    function sap_update_variations_from_api($item_codes_to_filter = null)
    {
    // Ensure WooCommerce functions are available
    if (!function_exists('wc_get_product')) {
        return "<p style='color: red;'>שגיאה: ווקומרס אינו פעיל. אנא וודא שווקומרס מותקן ומופעל.</p>";
    }

    ob_start(); // Start output buffering

    echo "<h2>מתחיל תהליך עדכון וריאציות מ-SAP...</h2>";
    
    // Send import started notification
    $start_message = "✓ SAP Import Started\n\n";
    if (is_array($item_codes_to_filter) && count($item_codes_to_filter) === 2) {
        $start_message .= "Range: " . $item_codes_to_filter[0] . " to " . $item_codes_to_filter[1] . "\n";
    } elseif (is_string($item_codes_to_filter) && !empty($item_codes_to_filter)) {
        $start_message .= "Single item: " . $item_codes_to_filter . "\n";
    } else {
        $start_message .= "Processing all items\n";
    }
    $start_message .= "Time: " . current_time('Y-m-d H:i:s');
    
    sap_send_telegram_message($start_message);

    if (is_string($item_codes_to_filter) && !empty($item_codes_to_filter)) {
        // Single item code (e.g., for specific shortcode testing)
        echo "<p>מעבד פריט בודד עם קוד SAP: <strong>" . esc_html($item_codes_to_filter) . "</strong></p>";
        
        // Even for single items, get all and filter in PHP (like manual import)
        echo "<p>שולח בקשה ל-SAP API לכל הפריטים (מסנן ב-PHP עבור פריט ספציפי)...</p>";
    } else {
        echo "<p>שולח בקשה ל-SAP API לכל הפריטים (מסנן ב-PHP עבור פריטים מקושרים)...</p>";
    }

    // 1. Connect and get token using improved auth function
    echo "<p>⏳ מתחבר ל-SAP API...</p>";
    flush(); // Force output to browser immediately
    
    $token = sap_get_auth_token();
    
    if (!$token) {
        echo "<p style='color: red;'>שגיאה: נכשל בחיבור ל-SAP API. בדוק את פרטי ההתחברות.</p>";
        return ob_get_clean();
    }

    echo "<p style='color: green;'>✅ התחברות ל-SAP API בוצעה בהצלחה.</p>";

    // 2. Retrieve ALL items with EXACT same format as manual import and category assignment
    $itemsRequest = [
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

    echo "<p>⏳ שולח בקשה ל-SAP API לשליפת פריטים...</p>";
    echo "<p><strong>ממתין לתגובת SAP מזורמת (עשוי לקחת עד 30 שניות)...</strong></p>";
    flush(); // Force output to browser immediately

    $itemsResponse = sap_api_post('Items/get', $itemsRequest, $token);
    
    // Provide immediate feedback after streaming completes
    if (is_wp_error($itemsResponse)) {
        echo "<p style='color: red;'>❌ <strong>שגיאה בתגובת הזרמה:</strong> " . esc_html($itemsResponse->get_error_message()) . "</p>";
    } else {
        echo "<p style='color: green;'>✅ <strong>תגובת הזרמה התקבלה בהצלחה!</strong></p>";
    }
    flush();

    if (is_wp_error($itemsResponse)) {
        echo "<p style='color: red;'>שגיאה: נכשל בשליפת נתוני פריטים מ-SAP API: " . esc_html($itemsResponse->get_error_message()) . "</p>";
        return ob_get_clean();
    }

    // 3. Access item data with improved format detection (updated to match manual import logic)
    $items = [];
    
    // Log the response structure for debugging
    error_log("SAP Products Import: Response structure analysis");
    if (is_array($itemsResponse)) {
        error_log("SAP Products Import: Response is array with keys: " . implode(', ', array_keys($itemsResponse)));
        
        // Log first level structure
        foreach (array_slice(array_keys($itemsResponse), 0, 5) as $key) {
            $value_type = gettype($itemsResponse[$key]);
            if ($value_type === 'array') {
                $sub_keys = is_array($itemsResponse[$key]) ? array_keys($itemsResponse[$key]) : [];
                error_log("SAP Products Import: Key '{$key}' is array with " . count($sub_keys) . " items, sub-keys: " . implode(', ', array_slice($sub_keys, 0, 5)));
            } else {
                error_log("SAP Products Import: Key '{$key}' is {$value_type}");
            }
        }
    }
    
    // NEWEST FORMAT: Direct array of objects from concatenated JSON parser
    if (is_array($itemsResponse) && isset($itemsResponse[0]) && is_array($itemsResponse[0]) && isset($itemsResponse[0]['ItemCode'])) {
        $items = $itemsResponse;  // Direct array of objects
        echo "<p>נמצאו " . count($items) . " פריטים מ-SAP (concatenated objects parsed)</p>";
        error_log("SAP Products Import: Using CONCATENATED format - direct array of " . count($items) . " objects");
        
        // Validate that items have expected structure
        $sample_item = $items[0];
        $required_fields = ['ItemCode', 'ItemName'];
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!isset($sample_item[$field])) {
                $missing_fields[] = $field;
            }
        }
        if (!empty($missing_fields)) {
            error_log("SAP Products Import: WARNING - Concatenated objects missing fields: " . implode(', ', $missing_fields));
        } else {
            error_log("SAP Products Import: Concatenated objects validation passed");
        }
    }
    // NEW FORMAT: Check for direct ['items'] array structure (NEW API FORMAT)
    elseif (isset($itemsResponse['items']) && is_array($itemsResponse['items'])) {
        $items = $itemsResponse['items'];
        $total_count = $itemsResponse['total'] ?? 'N/A';
        echo "<p>נמצאו " . count($items) . " פריטים מ-SAP (מתוך " . $total_count . ") - NEW API FORMAT</p>";
        error_log("SAP Products Import: Using NEW API format with direct [items] array");
    }
    // NEW FORMAT: apiResponse->data contains JSON string with value array
    elseif (isset($itemsResponse['apiResponse']['data']) && is_string($itemsResponse['apiResponse']['data'])) {
        $decoded_data = json_decode($itemsResponse['apiResponse']['data'], true);
        if ($decoded_data && isset($decoded_data['value']) && is_array($decoded_data['value'])) {
            $items = $decoded_data['value'];
            echo "<p>נמצאו " . count($items) . " פריטים מ-SAP (JSON string format)</p>";
            error_log("SAP Products Import: Using NEW JSON string format with ['value'] array");
            error_log("SAP Products Import: Decoded JSON data keys: " . implode(', ', array_keys($decoded_data)));
        } else {
            error_log("SAP Products Import: Failed to decode JSON string or missing 'value' array");
            error_log("SAP Products Import: JSON decode error: " . json_last_error_msg());
            if (is_string($itemsResponse['apiResponse']['data'])) {
                error_log("SAP Products Import: Raw JSON string (first 500 chars): " . substr($itemsResponse['apiResponse']['data'], 0, 500));
            }
        }
    }
    // Check for the nested structure: apiResponse -> result -> data -> Results (OLD FORMAT)
    elseif (isset($itemsResponse['apiResponse']['result']['data']['Results']) && is_array($itemsResponse['apiResponse']['result']['data']['Results'])) {
        $items = $itemsResponse['apiResponse']['result']['data']['Results'];
        $total_count = $itemsResponse['apiResponse']['result']['data']['TotalCount'] ?? 'N/A';
        echo "<p>נמצאו " . count($items) . " פריטים מ-SAP (מתוך " . $total_count . ") - OLD NESTED FORMAT</p>";
        error_log("SAP Products Import: Using OLD NESTED Results format");
    }
    // ORIGINAL FORMAT: Check for the nested structure: apiResponse -> result -> data -> value
    elseif (isset($itemsResponse['apiResponse']['result']['data']['value']) && is_array($itemsResponse['apiResponse']['result']['data']['value'])) {
        $items = $itemsResponse['apiResponse']['result']['data']['value'];
        echo "<p>נמצאו " . count($items) . " פריטים מ-SAP (OLD NESTED value FORMAT)</p>";
        error_log("SAP Products Import: Using OLD NESTED value format");
    }
    // FALLBACK: Check for the nested structure: apiResponse -> result -> data (without value)
    elseif (isset($itemsResponse['apiResponse']['result']['data']) && is_array($itemsResponse['apiResponse']['result']['data'])) {
        $items = $itemsResponse['apiResponse']['result']['data'];
        echo "<p>נמצאו " . count($items) . " פריטים מ-SAP (OLD NESTED data FORMAT)</p>";
        error_log("SAP Products Import: Using OLD NESTED data format");
    }
    // Check for direct Results array (fallback)
    elseif (isset($itemsResponse['Results']) && is_array($itemsResponse['Results'])) {
        $items = $itemsResponse['Results'];
        echo "<p>נמצאו " . count($items) . " פריטים מ-SAP (direct Results)</p>";
        error_log("SAP Products Import: Using direct Results format");
    }
    // Check for direct data array (fallback)
    elseif (isset($itemsResponse['data']) && is_array($itemsResponse['data'])) {
        $items = $itemsResponse['data'];
        echo "<p>נמצאו " . count($items) . " פריטים מ-SAP (direct data)</p>";
        error_log("SAP Products Import: Using direct data format");
    }
    else {
        echo "<p style='color: red;'>שגיאה: נכשל בשליפת נתוני פריטים מ-SAP API. מבנה התגובה אינו תקין.</p>";
        error_log('SAP Products Import: Unexpected response structure. Response type: ' . gettype($itemsResponse));
        if (is_array($itemsResponse)) {
            error_log('SAP Products Import: Array keys: ' . implode(', ', array_keys($itemsResponse)));
        } elseif (is_string($itemsResponse)) {
            error_log('SAP Products Import: String response (first 200 chars): ' . substr($itemsResponse, 0, 200));
        }
        return ob_get_clean();
    }
    // --- Start debug code ---
    // echo "<h4>SAP API Response (for debug purposes only):</h4>";
    // echo "<pre>";
    // print_r($itemsResponse);
    // echo "</pre>";
    // --- End debug code ---

    if (empty($items)) {
        echo "<p style='color: red;'>לא נמצאו פריטים מ-SAP API. זה יכול להצביע על בעיית תקשורת או עיבוד.</p>";
        
        // Send notification about empty result
        $empty_message = "⚠️ SAP Import - No Items from API\n\n";
        $empty_message .= "Result: Empty response from SAP API\n";
        $empty_message .= "Time: " . current_time('Y-m-d H:i:s');
        sap_send_telegram_message($empty_message);
        
        return ob_get_clean();
    }
    
    $total_items_from_sap = count($items);
    echo "<p>נתקבלו " . $total_items_from_sap . " פריטים מ-SAP, מסנן לפי הקריטריונים...</p>";
    
    // PHP filtering after getting all items (like manual import and category assignment)
    $filtered_items = [];
    
    if (is_string($item_codes_to_filter) && !empty($item_codes_to_filter)) {
        // Filter for specific item code
        foreach ($items as $item) {
            if (isset($item['ItemCode']) && $item['ItemCode'] === $item_codes_to_filter) {
                $filtered_items[] = $item;
            }
        }
        echo "<p>סינון עבור פריט ספציפי '{$item_codes_to_filter}': נמצאו " . count($filtered_items) . " פריטים</p>";
    } else {
        // Filter for items that have SiteItemID or SiteGroupID (already linked to WooCommerce)
        foreach ($items as $item) {
            $has_site_item_id = !empty($item['U_SiteItemID']) && $item['U_SiteItemID'] > 0;
            $has_site_group_id = !empty($item['U_SiteGroupID']) && $item['U_SiteGroupID'] > 0;
            
            if ($has_site_item_id || $has_site_group_id) {
                $filtered_items[] = $item;
            }
        }
        echo "<p>סינון עבור פריטים מקושרים (SiteItemID > 0 או SiteGroupID > 0): נמצאו " . count($filtered_items) . " פריטים</p>";
    }
    
    // Use filtered items for processing
    $items = $filtered_items;
    
    if (empty($items)) {
        echo "<p style='color: orange;'>לא נמצאו פריטים לעדכון אחרי הסינון.</p>";
        echo "<p><strong>הסבר:</strong> זה יכול להיות בגלל:</p>";
        echo "<ul>";
        echo "<li>אין פריטים ב-SAP עם SiteItemID > 0 או SiteGroupID > 0 (אין פריטים מקושרים)</li>";
        echo "<li>הפריט הספציפי שביקשת לא נמצא ב-SAP</li>";
        echo "<li>הפריטים ב-SAP עדיין לא קושרו ל-WooCommerce</li>";
        echo "</ul>";
        echo "<p><strong>פתרונות אפשריים:</strong></p>";
        echo "<ul>";
        echo "<li>הרץ ייבוא ידני תחילה כדי ליצור קישור בין SAP ל-WooCommerce</li>";
        echo "<li>וודא שיש מוצרים ב-WooCommerce עם SKUs שמתאימים לפריטים ב-SAP</li>";
        echo "<li>בדוק שהפריט הספציפי קיים ב-SAP</li>";
        echo "</ul>";
        
        // Send notification about empty filter result  
        $empty_message = "⚠️ SAP Import - No Items After Filtering\n\n";
        $empty_message .= "Total items from SAP: " . $total_items_from_sap . "\n";
        $empty_message .= "Items after filtering: 0\n";
        $empty_message .= "Filter criteria: " . (is_string($item_codes_to_filter) ? "ItemCode = {$item_codes_to_filter}" : "U_SiteItemID > 0 OR U_SiteGroupID > 0") . "\n";
        $empty_message .= "Time: " . current_time('Y-m-d H:i:s');
        sap_send_telegram_message($empty_message);
        
        return ob_get_clean();
    }

    echo "<p>נמצאו " . count($items) . " פריטים ב-SAP API לעדכון וריאציות פוטנציאלי.</p>";
    echo "<h3>פרטי עדכון:</h3>";
    echo "<ul style='list-style-type: disc; margin-left: 20px;'>";

    // Track processed SAP item codes for missing items detection
    $processed_sap_codes = [];
    
    // Statistics tracking
    $import_stats = [
        'processed' => 0,
        'variations_updated' => 0,
        'simple_updated' => 0,
        'skipped' => 0
    ];

    // 4. Process each item from SAP for variation updates
    foreach ($items as $item) {
        $sap_item_id = $item['ItemCode'] ?? '';
        $sap_item_name = $item['ItemName'] ?? 'פריט ללא שם';
        // Get stock from ItemWarehouseInfoCollection instead of QuantityOnStock
        $sap_quantity_on_hand = null;
        if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
            foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
                if (isset($warehouse_info['InStock'])) {
                    $sap_quantity_on_hand = $warehouse_info['InStock'];
                    break; // Use first warehouse's stock
                }
            }
        }
        $sap_color = $item['U_scolor'] ?? '';
        $sap_size = $item['U_ssize'] ?? '';
        $sap_barcode = $item['BarCode'] ?? '';

   //     echo "<li><strong>פריט SAP: " . esc_html($sap_item_name) . " (מק\"ט: " . esc_html($sap_item_id) . ")</strong><br>";

        if (empty($sap_item_id)) {
            echo "<span style='color: orange;'> &gt; אזהרה: קוד פריט SAP (מק\"ט) ריק. מדלג על פריט זה.</span></li>";
            $import_stats['skipped']++;
            continue;
        }
        
        // Track this SAP item code as processed
        $processed_sap_codes[] = $sap_item_id;
        $import_stats['processed']++;

        // Get SAP Site Item ID for primary matching
        $sap_site_item_id = $item['U_SiteItemID'] ?? null;
        
        // Search for existing product variation in WooCommerce
        $variation_id = null;
        $matching_method = 'none';
        
        // Primary: Try to match by SiteItemID (post ID)
        if (!empty($sap_site_item_id) && is_numeric($sap_site_item_id)) {
            $variation = wc_get_product($sap_site_item_id);
            if ($variation && $variation->is_type('variation')) {
                $variation_id = $sap_site_item_id;
                $matching_method = 'site_item_id';
            }
        }
        
        // Fallback: Match by SKU if SiteItemID match failed
        if (!$variation_id) {
            $args = array(
                'post_type'      => 'product_variation',
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'     => '_sku',
                        'value'   => $sap_item_id,
                        'compare' => '=',
                    ),
                ),
                'fields'         => 'ids',
            );
            $existing_variations = get_posts($args);
            
            if (!empty($existing_variations)) {
                $variation_id = $existing_variations[0];
                $matching_method = 'sku';
                
                // Log mismatch for future mapping
                if (!empty($sap_site_item_id)) {
                    sap_log_mapping_mismatch('variation', [
                        'item_code' => $sap_item_id,
                        'item_name' => $sap_item_name,
                        'expected_site_item_id' => $sap_site_item_id,
                        'found_variation_id' => $variation_id,
                        'match_method' => 'sku_fallback'
                    ]);
                }
            }
        }

        if ($variation_id) {
          //  echo " &gt; וריאציה קיימת (מזהה ווקומרס: <strong>" . esc_html($variation_id) . "</strong>) נמצאה בשיטת {$matching_method}. מעדכן. <br>";

            $variation = wc_get_product($variation_id);

            if (!$variation || !$variation->is_type('variation')) {
               echo "<span style='color: red;'> &gt; שגיאה: מזהה מוצר " . esc_html($variation_id) . " אינו וריאציה חוקית. מדלג על העדכון.</span></li>";
                continue;
            }

            // Always ensure SKU matches ItemCode
                $variation->set_sku($sap_item_id);
           //     echo " > מק\"ט (ItemCode) עודכן ל: " . esc_html($sap_item_id) . "<br>";

            // Update price from PriceList 1
            $b2c_raw_price = null; // Regular price from PriceList 1 (before VAT and rounding)

            if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
                foreach ($item['ItemPrices'] as $price_entry) {
                    if (isset($price_entry['PriceList']) && $price_entry['PriceList'] === 1) {
                        $b2c_raw_price = $price_entry['Price'] ?? null;
                        break; // Only need PriceList 1
                    }
                }
            }

            // Update price from PriceList 1 + VAT and round up to whole number
            if (is_numeric($b2c_raw_price) && $b2c_raw_price >= 0) {
                $b2c_price_with_vat = $b2c_raw_price * 1.18; // Add VAT (18%)

                // Round up to whole number
                // Example: 25.40 * 1.18 = 29.972 → ceil(29.972) = 30.00
                // Example: 22.50 * 1.18 = 26.55 → ceil(26.55) = 27.00
                $b2c_final_price = ceil($b2c_price_with_vat);

                $variation->set_regular_price($b2c_final_price);
                $variation->set_price($b2c_final_price); // Also the current sale price
            //    echo " > מחיר עודכן ל: " . wc_price($b2c_final_price) . " (כולל מע\"מ ומעוגל למעלה)<br>";
            } else {
                echo "<span style='color: orange;'> > אזהרה: מחיר לא תקין או חסר מ-PriceList 1. מחיר לא עודכן.</span><br>";
            }

            // Update stock
            if (is_numeric($sap_quantity_on_hand) && $sap_quantity_on_hand >= 0) {
                // Use SAP stock quantity as-is (no deduction)
                $updated_quantity = $sap_quantity_on_hand;
            
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($updated_quantity);
                $variation->set_stock_status($updated_quantity > 0 ? 'instock' : 'outofstock');
            //    echo " > מלאי עודכן ל: " . esc_html($updated_quantity) . "<br>";
            } else {
                echo "<span style='color: orange;'> > אזהרה: כמות לא תקינה או חסרה עבור וריאציה. מלאי לא עודכן.</span><br>";
                $variation->set_stock_status('outofstock');
                $variation->set_manage_stock(false);
            }

            // Update additional meta fields
            if (!empty($sap_barcode)) {
                update_post_meta($variation_id, 'barcode', $sap_barcode);
             //   echo " &gt; ברקוד עודכן.<br>";
            }
            if (!empty($item['SWW'])) {
                update_post_meta($variation_id, 'sap_sww', $item['SWW']);
            //    echo " &gt; SWW עודכן.<br>";
            }

            // Update variation attributes
            $attributes_updated = false;
            $variation_attributes = $variation->get_variation_attributes();

            // Update color attribute
            if (!empty($sap_color)) {
                $attribute_slug = 'pa_color';
                $attribute_name_display = 'Color';

                // Ensure taxonomy exists
                if (!taxonomy_exists($attribute_slug)) {
                    $created_attr_id = wc_create_attribute([
                        'name' => $attribute_name_display,
                        'slug' => str_replace('pa_', '', $attribute_slug),
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => false,
                    ]);
                    if (is_wp_error($created_attr_id)) {
                        error_log("Failed to create attribute {$attribute_name_display}: " . $created_attr_id->get_error_message());
                    } else {
                  //      echo " &gt; תכונת '{$attribute_name_display}' נוצרה.<br>";
                    }
                }

                $term_slug = sanitize_title($sap_color);
                $term_exists = term_exists($term_slug, $attribute_slug);
                if (!$term_exists) {
                    $inserted_term = wp_insert_term($sap_color, $attribute_slug);
                    if (is_wp_error($inserted_term)) {
                        error_log("Failed to insert term {$sap_color} for attribute {$attribute_slug}: " . $inserted_term->get_error_message());
                    } else {
                  //      echo " &gt; מונח '{$sap_color}' נוסף לתכונה '{$attribute_name_display}'.<br>";
                    }
                }

                if ( !isset($variation_attributes[$attribute_slug]) || $variation_attributes[$attribute_slug] !== $term_slug ) {
                    $variation_attributes[$attribute_slug] = $term_slug;
                    $attributes_updated = true;
                }
            }

            // Update size attribute
            if (!empty($sap_size)) {
                $attribute_slug = 'pa_size';
                $attribute_name_display = 'Size';

                // Ensure taxonomy exists
                if (!taxonomy_exists($attribute_slug)) {
                    $created_attr_id = wc_create_attribute([
                        'name' => $attribute_name_display,
                        'slug' => str_replace('pa_', '', $attribute_slug),
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => false,
                    ]);
                    if (is_wp_error($created_attr_id)) {
                        error_log("Failed to create attribute {$attribute_name_display}: " . $created_attr_id->get_error_message());
                    } else {
                      //  echo " &gt; תכונת '{$attribute_name_display}' נוצרה.<br>";
                    }
                }

                $term_slug = sanitize_title($sap_size);
                $term_exists = term_exists($term_slug, $attribute_slug);
                if (!$term_exists) {
                    $inserted_term = wp_insert_term($sap_size, $attribute_slug);
                    if (is_wp_error($inserted_term)) {
                        error_log("Failed to insert term {$sap_size} for attribute {$attribute_slug}: " . $inserted_term->get_error_message());
                    } else {
                   //     echo " &gt; מונח '{$sap_size}' נוסף לתכונה '{$attribute_name_display}'.<br>";
                    }
                }

                if ( !isset($variation_attributes[$attribute_slug]) || $variation_attributes[$attribute_slug] !== $term_slug ) {
                    $variation_attributes[$attribute_slug] = $term_slug;
                    $attributes_updated = true;
                }
            }

            if ($attributes_updated) {
                $variation->set_attributes($variation_attributes);
            //    echo " &gt; תכונות וריאציה (צבע/מידה) עודכנו.<br>";
            }

            // Save variation changes
            $variation->save();
            $import_stats['variations_updated']++;
         //   echo " &gt; וריאציה נשמרה בהצלחה.<br>";

            // Update parent product attributes if necessary
            $parent_id = wp_get_post_parent_id($variation_id);
            if ($parent_id) {
                $parent_product = wc_get_product($parent_id);
                if ($parent_product && $parent_product->is_type('simple')) {
                    // If the product was simple and now has variations, convert it to variable
                    wp_set_object_terms($parent_id, 'variable', 'product_type');
                    $parent_product = wc_get_product($parent_id); // Reload as variable product
                }
                if ($parent_product && $parent_product->is_type('variable')) {
                    $parent_product_attributes = $parent_product->get_attributes();
                    $parent_attributes_changed = false;

                    // Handle color attribute in parent product
                    if (!empty($sap_color)) {
                        $attribute_taxonomy = 'pa_color';
                        $term_slug = sanitize_title($sap_color);

                        if (!isset($parent_product_attributes[$attribute_taxonomy])) {
                            $new_attribute = new WC_Product_Attribute();
                            $new_attribute->set_id(wc_attribute_taxonomy_id_by_name($attribute_taxonomy));
                            $new_attribute->set_name(wc_attribute_taxonomy_name_by_slug($attribute_taxonomy));
                            $new_attribute->set_options(array($term_slug));
                            $new_attribute->set_position(0);
                            $new_attribute->set_visible(true);
                            $new_attribute->set_variation(true);
                            $parent_product_attributes[$attribute_taxonomy] = $new_attribute;
                            $parent_attributes_changed = true;
                        } else {
                            $current_options = $parent_product_attributes[$attribute_taxonomy]->get_options();
                            if (!in_array($term_slug, $current_options)) {
                                $current_options[] = $term_slug;
                                $parent_product_attributes[$attribute_taxonomy]->set_options($current_options);
                                $parent_attributes_changed = true;
                            }
                        }
                    }

                    // Handle size attribute in parent product
                    if (!empty($sap_size)) {
                        $attribute_taxonomy = 'pa_size';
                        $term_slug = sanitize_title($sap_size);

                        if (!isset($parent_product_attributes[$attribute_taxonomy])) {
                            $new_attribute = new WC_Product_Attribute();
                            $new_attribute->set_id(wc_attribute_taxonomy_id_by_name($attribute_taxonomy));
                            $new_attribute->set_name(wc_attribute_taxonomy_name_by_slug($attribute_taxonomy));
                            $new_attribute->set_options(array($term_slug));
                            $new_attribute->set_position(1);
                            $new_attribute->set_visible(true);
                            $new_attribute->set_variation(true);
                            $parent_product_attributes[$attribute_taxonomy] = $new_attribute;
                            $parent_attributes_changed = true;
                        } else {
                            $current_options = $parent_product_attributes[$attribute_taxonomy]->get_options();
                            if (!in_array($term_slug, $current_options)) {
                                $current_options[] = $term_slug;
                                $parent_product_attributes[$attribute_taxonomy]->set_options($current_options);
                                $parent_attributes_changed = true;
                            }
                        }
                    }

                    if ($parent_attributes_changed) {
                        $parent_product->set_attributes($parent_product_attributes);
                        $parent_product->save();
                      //  echo " &gt; תכונות מוצר אב עודכנו עבור וריאציות.<br>";
                    }
                }
            }
        } else {
            // No variation found, try to find simple product
            $sap_site_group_id = $item['U_SiteGroupID'] ?? null;
            $simple_product_id = null;
            $simple_matching_method = 'none';
            
            // Try to match simple product by SiteGroupID first
            if (!empty($sap_site_group_id) && is_numeric($sap_site_group_id)) {
                $simple_product = wc_get_product($sap_site_group_id);
                if ($simple_product && $simple_product->is_type('simple')) {
                    $simple_product_id = $sap_site_group_id;
                    $simple_matching_method = 'site_group_id';
                }
            }
            
            // Fallback: Try to match simple product by SKU
            if (!$simple_product_id) {
                $args = array(
                    'post_type'      => 'product',
                    'posts_per_page' => 1,
                    'meta_query'     => array(
                        array(
                            'key'     => '_sku',
                            'value'   => $sap_item_id,
                            'compare' => '=',
                        ),
                    ),
                    'fields'         => 'ids',
                );
                $existing_products = get_posts($args);
                
                if (!empty($existing_products)) {
                    $simple_product = wc_get_product($existing_products[0]);
                    if ($simple_product && $simple_product->is_type('simple')) {
                        $simple_product_id = $existing_products[0];
                        $simple_matching_method = 'sku';
                        
                        // Log mismatch for future mapping
                        if (!empty($sap_site_group_id)) {
                            sap_log_mapping_mismatch('simple_product', [
                                'item_code' => $sap_item_id,
                                'item_name' => $sap_item_name,
                                'expected_site_group_id' => $sap_site_group_id,
                                'found_product_id' => $simple_product_id,
                                'match_method' => 'sku_fallback'
                            ]);
                        }
                    }
                }
            }
            
            if ($simple_product_id) {
                echo "<span style='color: green;'> &gt; מוצר פשוט נמצא (מזהה: " . esc_html($simple_product_id) . ") בשיטת {$simple_matching_method}. מעדכן מחיר ומלאי.</span><br>";
                
                $simple_product = wc_get_product($simple_product_id);
                
                // Always ensure SKU matches ItemCode
                $simple_product->set_sku($sap_item_id);
                
                // Update price from PriceList 1
                $b2c_raw_price = null;
                if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
                    foreach ($item['ItemPrices'] as $price_entry) {
                        if (isset($price_entry['PriceList']) && $price_entry['PriceList'] === 1) {
                            $b2c_raw_price = $price_entry['Price'] ?? null;
                            break;
                        }
                    }
                }
                
                if (is_numeric($b2c_raw_price) && $b2c_raw_price >= 0) {
                    $b2c_price_with_vat = $b2c_raw_price * 1.18; // Add VAT (18%)
                    $b2c_final_price = ceil($b2c_price_with_vat); // Round up to whole number
                    
                    $simple_product->set_regular_price($b2c_final_price);
                    $simple_product->set_price($b2c_final_price);
                }
                
                // Update stock
                if (is_numeric($sap_quantity_on_hand) && $sap_quantity_on_hand >= 0) {
                    $updated_quantity = $sap_quantity_on_hand;
                    $simple_product->set_manage_stock(true);
                    $simple_product->set_stock_quantity($updated_quantity);
                    $simple_product->set_stock_status($updated_quantity > 0 ? 'instock' : 'outofstock');
                } else {
                    $simple_product->set_stock_status('outofstock');
                    $simple_product->set_manage_stock(false);
                }
                
                // Save simple product changes
                $simple_product->save();
                $import_stats['simple_updated']++;
                echo " &gt; מוצר פשוט עודכן בהצלחה.<br>";
            } else {
                echo "<span style='color: blue;'> &gt; מוצר עם מק\"ט " . esc_html($sap_item_id) . " לא נמצא בווקומרס (לא וריאציה ולא מוצר פשוט). דילוג על יצירה.</span></li>";
            }
        }
        echo "</li>"; // End current item log
    } // End foreach ($items as $item)

    echo "</ul>";
    echo "<p style='color: green;'>תהליך עדכון וריאציות SAP הסתיים.</p>";

    // Check for items that exist in WooCommerce but not in SAP (optional but recommended)
    if (!empty($processed_sap_codes)) {
        echo "<hr style='margin: 20px 0;'>";
        $missing_items_result = sap_handle_missing_items($processed_sap_codes, 'unpublish');
        echo $missing_items_result;
    }

    // Collect recent mismatches and missing items for Telegram notification
    $recent_mismatches = sap_get_mapping_mismatches(null, 50); // Last 50 mismatches
    $recent_missing = array_filter($recent_mismatches, function($mismatch) {
        return $mismatch['type'] === 'missing_from_sap';
    });
    $recent_mapping_issues = array_filter($recent_mismatches, function($mismatch) {
        return in_array($mismatch['type'], ['variation', 'simple_product']);
    });

    // Send Telegram notification for every import
    $telegram_result = sap_send_import_telegram_notification($import_stats, $recent_mapping_issues, $recent_missing);
    if (is_wp_error($telegram_result)) {
        echo "<p style='color: orange;'>אזהרה: שליחת התראת טלגרם נכשלה: " . $telegram_result->get_error_message() . "</p>";
    } else {
        echo "<p style='color: green;'>התראת טלגרם נשלחה בהצלחה.</p>";
    }

    return ob_get_clean(); // Return buffered output
}
}

/**
 * Function to handle products/variations that exist in WooCommerce but not in SAP response
 * This should be called after the main import to clean up orphaned items
 *
 * @param array $processed_sap_item_codes Array of ItemCodes that were processed from SAP
 * @param string $action 'unpublish' or 'delete' - what to do with missing items
 * @return string HTML output of the cleanup status
 */
function sap_handle_missing_items($processed_sap_item_codes = [], $action = 'unpublish')
{
    if (!function_exists('wc_get_product')) {
        return "<p style='color: red;'>שגיאה: ווקומרס אינו פעיל.</p>";
    }

    ob_start();
    
    echo "<h3>בדיקת פריטים שנעדרו מ-SAP...</h3>";
    
    if (empty($processed_sap_item_codes)) {
        echo "<p style='color: orange;'>אזהרה: לא סופקו קודי פריטים מ-SAP לבדיקה.</p>";
        return ob_get_clean();
    }
    
    // Get all WooCommerce variations with SKUs
    $args = array(
        'post_type'      => 'product_variation',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_sku',
                'value'   => '',
                'compare' => '!=',
            ),
        ),
        'fields'         => 'ids',
    );
    $all_variations = get_posts($args);
    
    // Get all simple products with SKUs  
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_sku',
                'value'   => '',
                'compare' => '!=',
            ),
        ),
        'fields'         => 'ids',
    );
    $all_simple_products = get_posts($args);
    
    $missing_items = [];
    $processed_count = 0;
    
    // Check variations
    foreach ($all_variations as $variation_id) {
        $variation = wc_get_product($variation_id);
        if ($variation && $variation->get_sku()) {
            $sku = $variation->get_sku();
            if (!in_array($sku, $processed_sap_item_codes)) {
                $missing_items[] = [
                    'id' => $variation_id,
                    'sku' => $sku,
                    'type' => 'variation',
                    'name' => $variation->get_name()
                ];
            }
        }
    }
    
    // Check simple products
    foreach ($all_simple_products as $product_id) {
        $product = wc_get_product($product_id);
        if ($product && $product->get_sku()) {
            $sku = $product->get_sku();
            if (!in_array($sku, $processed_sap_item_codes)) {
                $missing_items[] = [
                    'id' => $product_id,
                    'sku' => $sku,
                    'type' => 'simple',
                    'name' => $product->get_name()
                ];
            }
        }
    }
    
    if (empty($missing_items)) {
        echo "<p style='color: green;'>כל הפריטים ב-WooCommerce נמצאים גם ב-SAP. אין פריטים חסרים.</p>";
        return ob_get_clean();
    }
    
    echo "<p>נמצאו " . count($missing_items) . " פריטים ב-WooCommerce שלא קיימים ב-SAP:</p>";
    echo "<ul style='list-style-type: disc; margin-left: 20px;'>";
    
    foreach ($missing_items as $missing_item) {
        $product = wc_get_product($missing_item['id']);
        
        if (!$product) {
            continue;
        }
        
        echo "<li><strong>" . esc_html($missing_item['name']) . "</strong> (מק\"ט: " . esc_html($missing_item['sku']) . ", סוג: " . esc_html($missing_item['type']) . ")<br>";
        
        if ($action === 'unpublish') {
            // Unpublish the product/variation
            $product->set_status('draft');
            $product->save();
            echo " &gt; הוסתר מהאתר (סטטוס: טיוטה)<br>";
            
            // Log the missing item for analysis
            sap_log_mapping_mismatch('missing_from_sap', [
                'wc_id' => $missing_item['id'],
                'sku' => $missing_item['sku'],
                'name' => $missing_item['name'],
                'type' => $missing_item['type'],
                'action_taken' => 'unpublished'
            ]);
            
            $processed_count++;
        } elseif ($action === 'delete') {
            // Delete the product/variation
            wp_delete_post($missing_item['id'], true);
            echo " &gt; נמחק לחלוטין<br>";
            
            // Log the deletion
            sap_log_mapping_mismatch('missing_from_sap', [
                'wc_id' => $missing_item['id'],
                'sku' => $missing_item['sku'],
                'name' => $missing_item['name'],
                'type' => $missing_item['type'],
                'action_taken' => 'deleted'
            ]);
            
            $processed_count++;
        } else {
            echo " &gt; זוהה כחסר (לא בוצעה פעולה)<br>";
            
            // Log as detected but no action taken
            sap_log_mapping_mismatch('missing_from_sap', [
                'wc_id' => $missing_item['id'],
                'sku' => $missing_item['sku'],
                'name' => $missing_item['name'],
                'type' => $missing_item['type'],
                'action_taken' => 'detected_only'
            ]);
        }
        
        echo "</li>";
    }
    
    echo "</ul>";
    
    if ($action === 'unpublish') {
        echo "<p style='color: green;'>הסתרת {$processed_count} פריטים שנעדרו מ-SAP הושלמה.</p>";
    } elseif ($action === 'delete') {
        echo "<p style='color: red;'>מחיקת {$processed_count} פריטים שנעדרו מ-SAP הושלמה.</p>";
    } else {
        echo "<p style='color: blue;'>זוהו {$processed_count} פריטים חסרים. לא בוצעה פעולה.</p>";
    }
    
    return ob_get_clean();
}

/**
 * Log SAP mapping mismatches for future analysis and correction
 *
 * @param string $type Type of mismatch ('variation', 'simple_product', 'missing')
 * @param array $data Mismatch data containing relevant information
 */
function sap_log_mapping_mismatch($type, $data) {
    $log_entry = [
        'timestamp' => current_time('mysql'),
        'type' => $type,
        'data' => $data
    ];
    
    // Log to WordPress error log
    error_log('SAP Mapping Mismatch (' . $type . '): ' . wp_json_encode($log_entry));
    
    // Optionally store in database for future analysis
    $existing_mismatches = get_option('sap_mapping_mismatches', []);
    $existing_mismatches[] = $log_entry;
    
    // Keep only last 1000 entries to prevent database bloat
    if (count($existing_mismatches) > 1000) {
        $existing_mismatches = array_slice($existing_mismatches, -1000);
    }
    
    update_option('sap_mapping_mismatches', $existing_mismatches);
}

/**
 * Get mapping mismatch report for analysis
 *
 * @param string|null $type Filter by mismatch type
 * @param int $limit Number of entries to return
 * @return array Array of mismatch entries
 */
function sap_get_mapping_mismatches($type = null, $limit = 100) {
    $mismatches = get_option('sap_mapping_mismatches', []);
    
    if ($type) {
        $mismatches = array_filter($mismatches, function($mismatch) use ($type) {
            return $mismatch['type'] === $type;
        });
    }
    
    // Sort by timestamp (newest first)
    usort($mismatches, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return array_slice($mismatches, 0, $limit);
}

/**
 * Clear mapping mismatch log
 */
function sap_clear_mapping_mismatches() {
    delete_option('sap_mapping_mismatches');
}

/**
 * Send message to Telegram
 *
 * @param string $message Message to send
 * @return bool|WP_Error True on success, WP_Error on failure
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
 * Send SAP import summary to Telegram
 *
 * @param array $import_stats Import statistics
 * @param array $mismatches Array of mismatches found
 * @param array $missing_items Array of missing items
 */
function sap_send_import_telegram_notification($import_stats, $mismatches = [], $missing_items = []) {
    $status = (empty($mismatches) && empty($missing_items)) ? "✓" : "✗";
    $message = $status . " SAP Import Finished\n\n";
    
    // Import statistics
    $message .= "Statistics:\n";
    $message .= "Items processed: " . ($import_stats['processed'] ?? 0) . "\n";
    $message .= "Variations updated: " . ($import_stats['variations_updated'] ?? 0) . "\n";
    $message .= "Simple products updated: " . ($import_stats['simple_updated'] ?? 0) . "\n";
    
    // Mismatches
    if (!empty($mismatches)) {
        $message .= "\nMapping Mismatches (" . count($mismatches) . "):\n";
        foreach (array_slice($mismatches, 0, 5) as $mismatch) { // Show first 5
            $data = $mismatch['data'];
            if ($mismatch['type'] === 'variation') {
                $message .= $data['item_code'] . " (" . $data['item_name'] . ")\n";
                $message .= "Expected ID: " . $data['expected_site_item_id'] . " | Found: " . $data['found_variation_id'] . "\n";
            } elseif ($mismatch['type'] === 'simple_product') {
                $message .= $data['item_code'] . " (" . $data['item_name'] . ")\n";
                $message .= "Expected Group: " . $data['expected_site_group_id'] . " | Found: " . $data['found_product_id'] . "\n";
            }
        }
        if (count($mismatches) > 5) {
            $message .= "... and " . (count($mismatches) - 5) . " more\n";
        }
    }
    
    // Missing items
    if (!empty($missing_items)) {
        $message .= "\nMissing from SAP (" . count($missing_items) . "):\n";
        foreach (array_slice($missing_items, 0, 5) as $missing) { // Show first 5
            $data = $missing['data'];
            $message .= $data['sku'] . " (" . $data['name'] . ")\n";
            $message .= "Type: " . $data['type'] . " | Action: " . $data['action_taken'] . "\n";
        }
        if (count($missing_items) > 5) {
            $message .= "... and " . (count($missing_items) - 5) . " more\n";
        }
    }
    
    $message .= "\nTime: " . current_time('Y-m-d H:i:s');
    
    // Send the message
    return sap_send_telegram_message($message);
}

/**
 * Test function to validate the import logic with example data
 * Can be called via shortcode [sap_test_import] or admin page
 */
function sap_test_import_logic() {
    if (!current_user_can('manage_options')) {
        return 'Unauthorized access';
    }
    
    // Load the example data from item_payload.json
    $json_file = plugin_dir_path(__FILE__) . 'item_payload.json';
    if (!file_exists($json_file)) {
        return '<p style="color: red;">Test JSON file not found: ' . $json_file . '</p>';
    }
    
    $json_content = file_get_contents($json_file);
    $test_item = json_decode($json_content, true);
    
    if (!$test_item) {
        return '<p style="color: red;">Failed to parse test JSON file</p>';
    }
    
    ob_start();
    
    echo '<div style="background: #f9f9f9; padding: 20px; margin: 20px 0; border: 1px solid #ddd;">';
    echo '<h3>🧪 SAP Import Logic Test</h3>';
    echo '<p><strong>Testing with example data from item_payload.json:</strong></p>';
    
    // Display the test item info
    echo '<h4>Test Item Details:</h4>';
    echo '<ul>';
    echo '<li><strong>ItemCode:</strong> ' . esc_html($test_item['ItemCode'] ?? 'N/A') . '</li>';
    echo '<li><strong>ItemName:</strong> ' . esc_html($test_item['ItemName'] ?? 'N/A') . '</li>';
    echo '<li><strong>U_SiteItemID:</strong> ' . esc_html($test_item['U_SiteItemID'] ?? 'N/A') . '</li>';
    echo '<li><strong>U_SiteGroupID:</strong> ' . esc_html($test_item['U_SiteGroupID'] ?? 'N/A') . '</li>';
    
    // Check stock data
    $stock = 'N/A';
    if (isset($test_item['ItemWarehouseInfoCollection'][0]['InStock'])) {
        $stock = $test_item['ItemWarehouseInfoCollection'][0]['InStock'];
    }
    echo '<li><strong>Stock (InStock):</strong> ' . esc_html($stock) . '</li>';
    
    // Check price data
    $price = 'N/A';
    if (isset($test_item['ItemPrices'])) {
        foreach ($test_item['ItemPrices'] as $price_entry) {
            if ($price_entry['PriceList'] === 1) {
                $price = $price_entry['Price'] ?? 'null';
                break;
            }
        }
    }
    echo '<li><strong>Price (PriceList 1):</strong> ' . esc_html($price) . '</li>';
    echo '</ul>';
    
    echo '<h4>Import Test Results:</h4>';
    
    // Test the import logic with single item
    $test_result = sap_update_variations_from_api($test_item['ItemCode']);
    
    echo '<div style="background: white; padding: 15px; border-left: 4px solid #0073aa;">';
    echo $test_result;
    echo '</div>';
    
    echo '<h4>Mapping Mismatches (Last 10):</h4>';
    $mismatches = sap_get_mapping_mismatches(null, 10);
    if (empty($mismatches)) {
        echo '<p>No mapping mismatches found.</p>';
    } else {
        echo '<ul>';
        foreach ($mismatches as $mismatch) {
            echo '<li>';
            echo '<strong>' . esc_html($mismatch['type']) . '</strong> - ';
            echo '<em>' . esc_html($mismatch['timestamp']) . '</em><br>';
            echo '<small>' . esc_html(wp_json_encode($mismatch['data'])) . '</small>';
            echo '</li>';
        }
        echo '</ul>';
    }
    
    echo '</div>';
    
    return ob_get_clean();
}

// Register shortcode for testing
add_shortcode('sap_test_import', 'sap_test_import_logic');

/**
 * Test Telegram notification
 * Can be called via shortcode [sap_test_telegram]
 */
function sap_test_telegram_notification() {
    if (!current_user_can('manage_options')) {
        return 'Unauthorized access';
    }
    
    $test_stats = [
        'processed' => 5,
        'variations_updated' => 3,
        'simple_updated' => 2,
        'skipped' => 0
    ];
    
    $test_mismatches = [
        [
            'type' => 'variation',
            'data' => [
                'item_code' => 'TEST001',
                'item_name' => 'Test Product 1',
                'expected_site_item_id' => '12345',
                'found_variation_id' => '54321'
            ]
        ]
    ];
    
    $result = sap_send_import_telegram_notification($test_stats, $test_mismatches, []);
    
    if (is_wp_error($result)) {
        return '<p style="color: red;">✗ Telegram test failed: ' . $result->get_error_message() . '</p>';
    } else {
        return '<p style="color: green;">✓ Telegram test message sent successfully!</p>';
    }
}

// Register test shortcode
add_shortcode('sap_test_telegram', 'sap_test_telegram_notification');

/**
 * Schedules the daily import cron job.
 */
if (!function_exists('sap_schedule_daily_import')) {
    function sap_schedule_daily_import() {
    if ( ! wp_next_scheduled( 'sap_daily_import_event' ) ) {
        // Schedule the action to run once daily at 02:00 (or any chosen time)
        wp_schedule_event( strtotime( '02:00:00' ), 'daily', 'sap_daily_import_event' );
    }
}
}
// add_action( 'wp', 'sap_schedule_daily_import' );

/**
 * Function to be executed by the cron job - updates all linked items.
 */
function sap_run_daily_import_task() {
    // Call the main import function for all linked items (no range limitation)
    $log_output = sap_update_variations_from_api();
    
    // You can save the log_output to a file or send by email for monitoring
    error_log('Daily SAP Import Log: ' . strip_tags($log_output));
    
    return $log_output; // In case of manual execution, this will return the output
}
// add_action( 'sap_daily_import_event', 'sap_run_daily_import_task' );