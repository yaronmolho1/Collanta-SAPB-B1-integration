<?php
/**
 * SAP Order Integration Functions
 *
 * @package My_SAP_Importer
 * @subpackage Includes
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include the SAP Sync Logger class
require_once plugin_dir_path(__FILE__) . 'class-sap-sync-logger.php';

// --- API Configuration ---
// Constants are defined in main plugin file

/**
 * Gets authentication token from SAP API using username and password.
 * Token is cached in transient to prevent repeated and unnecessary calls.
 *
 * @return string|WP_Error Bearer Token or WP_Error object if failed.
 */
if (!function_exists('sap_get_auth_token')) {
    function sap_get_auth_token() {
        $token_key = 'sap_api_auth_token';
        $token_expiration_key = 'sap_api_token_expiration';

        $existing_token = get_transient($token_key); // Use transients for caching
        $expiration_time = get_transient($token_expiration_key);

        // Check if token is still valid
        // Refresh 5 minutes before expiration to prevent token expiration during use
        if ($existing_token && $expiration_time && time() < $expiration_time - 300) {
            error_log('SAP API Auth: Using cached token (expires in ' . ($expiration_time - time()) . ' seconds).');
            return $existing_token;
        }

        // If no token or expired, make new authentication request
        $auth_url = SAP_API_BASE . '/Login/login';

        $credentials = [
            'Username' => SAP_API_USERNAME, // API expects 'Username' field
            'password' => SAP_API_PASSWORD,
        ];

        $args = [
            'method'      => 'POST',
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => json_encode($credentials),
            'timeout'     => 30,
            'sslverify'   => defined('WP_DEBUG') && WP_DEBUG ? false : true, // Change to true in production with valid SSL!
        ];

        error_log('SAP API Auth Request URL: ' . $auth_url);
        // Warning: Don't print passwords to logs in production! This is only for debugging.
        // error_log('SAP API Auth Request Body: ' . json_encode($credentials));

        $response = wp_remote_post($auth_url, $args);

        if (is_wp_error($response)) {
            error_log('SAP API Auth Error: ' . $response->get_error_message());
            return new WP_Error('auth_error', 'SAP API authentication error: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        error_log('SAP API Auth Response Body (HTTP ' . $http_code . '): ' . $body); // Print full response

        $data = json_decode($body, true);

        if (isset($data['token']) && isset($data['expiration'])) {
            $token = $data['token'];
            $expiration_datetime = new DateTime($data['expiration']);
            $expiration_timestamp = $expiration_datetime->getTimestamp();

            // Save token and expiration time (minus a few seconds for safety)
            // Transient TTL should be less than actual expiration time
            $transient_ttl = $expiration_timestamp - time() - 60; // Expire 1 minute before actual time
            if ($transient_ttl < 0) {
                $transient_ttl = 0;
            }

            set_transient($token_key, $token, $transient_ttl);
            set_transient($token_expiration_key, $expiration_timestamp, $transient_ttl);

            error_log('SAP API Auth: Successfully received and cached new token. Expires at: ' . date('Y-m-d H:i:s', $expiration_timestamp));
            return $token;
        } else {
            error_log('SAP API Auth: Invalid token response. Expected "token" and "expiration" keys. Full response: ' . print_r($data, true));
            return new WP_Error('auth_invalid_response', 'SAP API authentication error: Invalid token response. Missing token or expiration data.');
        }
    }
}

/**
 * Sends POST request to SAP API.
 *
 * @param string $endpoint API endpoint.
 * @param array  $data Payload data to send.
 * @param string|null $token Authentication token (if provided, otherwise retrieved automatically).
 * @return array|WP_Error Decoded API response or WP_Error object in case of failure.
 */
if (!function_exists('sap_api_post')) {
    function sap_api_post($endpoint, $data = [], $token = null) {
        // If no token provided, try to get one
        if (is_null($token)) {
            $token = sap_get_auth_token();
            if (is_wp_error($token)) {
                return $token; // Return authentication error
            }
        }

        $url = SAP_API_BASE . '/' . $endpoint;

        $headers = [
            'Content-Type'  => 'application/json',
            'Accept'        => '*/*',
            'Authorization' => 'Bearer ' . $token,
        ];

        $args = [
            'method'      => 'POST',
            'headers'     => $headers,
            'body'        => json_encode($data),
            'timeout'     => 180, // Timeout in seconds
            'data_format' => 'body',
            'sslverify'   => defined('WP_DEBUG') && WP_DEBUG ? false : true, // Change to true in production with valid SSL!
        ];

        error_log('SAP API Post Request URL: ' . $url);
        error_log('SAP API Post Request Headers: ' . json_encode($headers, JSON_PRETTY_PRINT));
        error_log('SAP API Post Request Body: ' . json_encode($data, JSON_PRETTY_PRINT));

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            error_log('SAP API Post Error (' . $endpoint . '): ' . $response->get_error_message());
            // Try to extract response body in case of WP_Error to identify SAP logical errors
            $error_data = [
                'response_code' => wp_remote_retrieve_response_code($response),
                'response_body' => json_decode(wp_remote_retrieve_body($response), true), // Try to decode body
            ];
            return new WP_Error('api_error', 'SAP API error for ' . $endpoint . ': ' . $response->get_error_message(), $error_data);
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        error_log('SAP API Post Response Body (' . $endpoint . ', HTTP ' . $http_code . '): ' . $body); // Print full response

        // Handle empty response body (200 status but no content)
        if (empty($body) || trim($body) === '') {
            error_log('SAP API Post: Empty response body received for ' . $endpoint . ' (HTTP ' . $http_code . ') - returning empty array');
            return []; // Return empty array for empty responses
        }

        $decoded_body = json_decode($body, true);

        // Handle JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('SAP API Post: JSON decode error for ' . $endpoint . ': ' . json_last_error_msg() . '. Raw body: ' . $body);
            
            // Try to clean the response and decode again
            $cleaned_body = trim($body);
            if (substr($cleaned_body, 0, 1) !== '{' && substr($cleaned_body, 0, 1) !== '[') {
                // Response doesn't start with JSON, try to find JSON part
                $json_start = strpos($cleaned_body, '{');
                if ($json_start !== false) {
                    $cleaned_body = substr($cleaned_body, $json_start);
                    $decoded_body = json_decode($cleaned_body, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        error_log('SAP API Post: Successfully decoded cleaned JSON for ' . $endpoint);
                        return $decoded_body;
                    }
                }
            }
            
            return new WP_Error('json_decode_error', 'Failed to decode JSON response from SAP API: ' . json_last_error_msg() . '. Raw response: ' . substr($body, 0, 200));
        }

        // **Fix: Handle nested JSON in 'data' field**
        // This is critical for responses like creating new customer where 'data' contains JSON as string
        if (isset($decoded_body['apiResponse']['result']['data']) && is_string($decoded_body['apiResponse']['result']['data'])) {
            $inner_data_json = $decoded_body['apiResponse']['result']['data'];
            $inner_decoded_data = json_decode($inner_data_json, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                // If decoding succeeded, replace string with decoded object
                $decoded_body['apiResponse']['result']['data'] = $inner_decoded_data;
                error_log('SAP API Post: Successfully decoded inner JSON for ' . $endpoint);
            } else {
                error_log('Failed to decode inner JSON data from SAP API response for ' . $endpoint . ': ' . json_last_error_msg() . '. Original inner data: ' . $inner_data_json);
            }
        }
        // **End of fix**

        return $decoded_body;
    }
}

/**
 * Sends POST request to SAP API with extended timeout for OrderFlow operations.
 *
 * @param string $endpoint API endpoint.
 * @param array  $data Payload data to send.
 * @param string|null $token Authentication token (if provided, otherwise retrieved automatically).
 * @param int $timeout_seconds Custom timeout in seconds (default 180 for OrderFlow).
 * @return array|WP_Error Decoded API response or WP_Error object in case of failure.
 */
if (!function_exists('sap_api_post_orderflow')) {
    function sap_api_post_orderflow($endpoint, $data = [], $token = null, $timeout_seconds = 180) {
        // If no token provided, try to get one
        if (is_null($token)) {
            $token = sap_get_auth_token();
            if (is_wp_error($token)) {
                return $token; // Return authentication error
            }
        }

        $url = SAP_API_BASE . '/' . $endpoint;

        $headers = [
            'Content-Type'  => 'application/json',
            'Accept'        => '*/*',
            'Authorization' => 'Bearer ' . $token,
        ];

        $args = [
            'method'      => 'POST',
            'headers'     => $headers,
            'body'        => json_encode($data),
            'timeout'     => $timeout_seconds, // Extended timeout for OrderFlow
            'data_format' => 'body',
            'sslverify'   => defined('WP_DEBUG') && WP_DEBUG ? false : true,
        ];

        error_log('SAP OrderFlow API Post Request URL: ' . $url);
        error_log('SAP OrderFlow API Post Request Headers: ' . json_encode($headers, JSON_PRETTY_PRINT));
        error_log('SAP OrderFlow API Post Request Body: ' . json_encode($data, JSON_PRETTY_PRINT));
        error_log('SAP OrderFlow API Post Timeout: ' . $timeout_seconds . ' seconds');

        // Temporarily increase WordPress HTTP timeout for OrderFlow operations
        add_filter('http_request_timeout', function($timeout) use ($timeout_seconds) {
            return max($timeout, $timeout_seconds);
        }, 10, 1);

        $response = wp_remote_post($url, $args);

        // Remove the filter after the request
        remove_filter('http_request_timeout', function($timeout) use ($timeout_seconds) {
            return max($timeout, $timeout_seconds);
        }, 10);

        if (is_wp_error($response)) {
            error_log('SAP OrderFlow API Post Error (' . $endpoint . '): ' . $response->get_error_message());
            // Try to extract response body in case of WP_Error to identify SAP logical errors
            $error_data = [
                'response_code' => wp_remote_retrieve_response_code($response),
                'response_body' => json_decode(wp_remote_retrieve_body($response), true), // Try to decode body
            ];
            return new WP_Error('api_error', 'SAP OrderFlow API error for ' . $endpoint . ': ' . $response->get_error_message(), $error_data);
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        error_log('SAP OrderFlow API Post Response Body (' . $endpoint . ', HTTP ' . $http_code . '): ' . $body);

        // Handle empty response body (200 status but no content)
        if (empty($body) || trim($body) === '') {
            error_log('SAP OrderFlow API Post: Empty response body received for ' . $endpoint . ' (HTTP ' . $http_code . ') - returning empty array');
            return []; // Return empty array for empty responses
        }

        $decoded_body = json_decode($body, true);

        // Handle JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('SAP OrderFlow API Post: JSON decode error for ' . $endpoint . ': ' . json_last_error_msg() . '. Raw body: ' . $body);
            
            // Try to clean the response and decode again
            $cleaned_body = trim($body);
            if (substr($cleaned_body, 0, 1) !== '{' && substr($cleaned_body, 0, 1) !== '[') {
                // Response doesn't start with JSON, try to find JSON part
                $json_start = strpos($cleaned_body, '{');
                if ($json_start !== false) {
                    $cleaned_body = substr($cleaned_body, $json_start);
                    $decoded_body = json_decode($cleaned_body, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        error_log('SAP OrderFlow API Post: Successfully decoded cleaned JSON for ' . $endpoint);
                        return $decoded_body;
                    }
                }
            }
            
            return new WP_Error('json_decode_error', 'Failed to decode JSON response from SAP OrderFlow API: ' . json_last_error_msg() . '. Raw response: ' . substr($body, 0, 200));
        }

        // **Fix: Handle nested JSON in 'data' field**
        // This is critical for responses like creating new customer where 'data' contains JSON as string
        if (isset($decoded_body['apiResponse']['result']['data']) && is_string($decoded_body['apiResponse']['result']['data'])) {
            $inner_data_json = $decoded_body['apiResponse']['result']['data'];
            $inner_decoded_data = json_decode($inner_data_json, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                // If decoding succeeded, replace string with decoded object
                $decoded_body['apiResponse']['result']['data'] = $inner_decoded_data;
                error_log('SAP OrderFlow API Post: Successfully decoded inner JSON for ' . $endpoint);
            } else {
                error_log('Failed to decode inner JSON data from SAP OrderFlow API response for ' . $endpoint . ': ' . json_last_error_msg() . '. Original inner data: ' . $inner_data_json);
            }
        }
        // **End of fix**

        return $decoded_body;
    }
}

/**
 * Searches for existing customer in SAP by email address and phone number.
 *
 * @param string $email Customer email address to search for.
 * @param string $phone Customer phone number to search for (optional).
 * @param string|null $token Authentication token (if provided, otherwise retrieved automatically).
 * @return string|null Returns CardCode if customer found, null if not found, WP_Error on API error.
 */
if (!function_exists('sap_search_customer_by_email_and_phone')) {
    function sap_search_customer_by_email_and_phone($email, $phone = '', $token = null) {
                // If no token provided, try to get one
        if (is_null($token)) {
            $token = sap_get_auth_token();
            if (is_wp_error($token)) {
                return $token; // Return authentication error
            }
        }

        // Build filter objects with proper OR logic
        $filter_objects = [
            [
                "field" => "EmailAddress",
                "fieldType" => "string",
                "fieldValue" => $email,
                "operator" => "="
            ]
        ];
        
        // Add phone search if phone number is provided (with OR logic)
        if (!empty($phone)) {
            $filter_objects[] = [
                "field" => "Phone1",
                "fieldType" => "string", 
                "fieldValue" => $phone,
                "operator" => "=",
                "logic" => "or"
            ];
        }
        
        $search_payload = [
            "selectObjects" => [
                ["field" => "CardCode"]
            ],
            "filterObjects" => $filter_objects,
            "orderByObjects" => [
                [
                    "orderField" => "CardCode",
                    "orderByType" => 0
                ]
            ]
        ];


        // Validate request payload
        if (empty($email)) {
            error_log('SAP Customer Search: Email parameter is empty, cannot search');
            return null;
        }
        
        if (empty($search_payload['filterObjects'])) {
            error_log('SAP Customer Search: No filter objects created, cannot search');
            return null;
        }
        
        error_log('SAP Customer Search: Searching for email: ' . $email . (!empty($phone) ? ' OR phone: ' . $phone : ''));
        error_log('SAP Customer Search: Request URL: ' . SAP_API_BASE . '/Customers/get');
        error_log('SAP Customer Search: Request Payload: ' . json_encode($search_payload, JSON_PRETTY_PRINT));
        error_log('SAP Customer Search: Auth Token (first 20 chars): ' . substr($token, 0, 20) . '...');
        
        $search_response = sap_api_post('Customers/get', $search_payload, $token);

        if (is_wp_error($search_response)) {
            error_log('SAP Customer Search Error: ' . $search_response->get_error_message());
            // Log additional error details if available
            $error_data = $search_response->get_error_data();
            if ($error_data) {
                error_log('SAP Customer Search Error Data: ' . print_r($error_data, true));
            }
            return $search_response;
        }

        // Handle empty response (200 status but no content)
        if (empty($search_response) || $search_response === null || $search_response === '') {
            error_log('SAP Customer Search: Empty response received (200 status but no content) - treating as no matching records');
            return null; // No customer found
        }

        // Handle empty array response
        if (is_array($search_response) && empty($search_response)) {
            error_log('SAP Customer Search: Empty array response received - treating as no matching records');
            return null; // No customer found
        }

        error_log('SAP Customer Search Response (Full): ' . print_r($search_response, true));

        // Handle direct customer object response (your API returns single customer directly)
        // Note: Response may only contain CardCode and @odata.etag, not EmailAddress
        if (is_array($search_response) && isset($search_response['CardCode']) && !empty($search_response['CardCode'])) {
            error_log('SAP Customer Search: Found direct customer object with CardCode: ' . $search_response['CardCode']);
            return $search_response['CardCode'];
        }
        
        // Handle array of customers (if API returns multiple results)
        if (is_array($search_response) && isset($search_response[0]) && isset($search_response[0]['CardCode'])) {
            error_log('SAP Customer Search: Found customer array, using first result with CardCode: ' . $search_response[0]['CardCode']);
            return $search_response[0]['CardCode'];
        }
        
        // Handle wrapped response with items array
        if (is_array($search_response) && isset($search_response['items']) && !empty($search_response['items'])) {
            error_log('SAP Customer Search: Found items array with ' . count($search_response['items']) . ' items');
            $customer = $search_response['items'][0];
            if (isset($customer['CardCode'])) {
                error_log('SAP Customer Search: Found existing customer with CardCode: ' . $customer['CardCode']);
                return $customer['CardCode'];
            }
        }
        
        // Handle OData-style response with value array
        if (is_array($search_response) && isset($search_response['value']) && !empty($search_response['value'])) {
            error_log('SAP Customer Search: Found OData value array with ' . count($search_response['value']) . ' items');
            $customer = $search_response['value'][0];
            if (isset($customer['CardCode'])) {
                error_log('SAP Customer Search: Found existing customer with CardCode: ' . $customer['CardCode']);
                return $customer['CardCode'];
            }
        }
        
        // Handle complex wrapped response (legacy support)
        if (isset($search_response['apiResponse']['status']) && $search_response['apiResponse']['status'] === 0) {
            $search_data = isset($search_response['apiResponse']['result']['data']) ? $search_response['apiResponse']['result']['data'] : null;
            
            if (is_string($search_data)) {
                $search_data = json_decode($search_data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log('SAP Customer Search: Failed to decode nested JSON data: ' . json_last_error_msg());
                    return null;
                }
            }
            
            if (is_array($search_data) && isset($search_data['items']) && !empty($search_data['items'])) {
                $customer = $search_data['items'][0];
                if (isset($customer['CardCode']) && !empty($customer['CardCode'])) {
                    error_log('SAP Customer Search: Found existing customer with CardCode: ' . $customer['CardCode']);
                    return $customer['CardCode'];
                }
            }
            
            // Handle direct data response without items wrapper
            if (is_array($search_data) && isset($search_data['CardCode']) && !empty($search_data['CardCode'])) {
                error_log('SAP Customer Search: Found existing customer in wrapped data with CardCode: ' . $search_data['CardCode']);
                return $search_data['CardCode'];
            }
        }
        
        // Log what we actually received for debugging
        error_log('SAP Customer Search: No customer found. Response structure: ' . 
                 (is_array($search_response) ? 'Array with keys: ' . implode(', ', array_keys($search_response)) : gettype($search_response)));
        
        // Check for API errors
        if (isset($search_response['error'])) {
            error_log('SAP Customer Search: API Error: ' . print_r($search_response['error'], true));
        }

        error_log('SAP Customer Search: No existing customer found for email: ' . $email . (!empty($phone) ? ' or phone: ' . $phone : ''));
        return null; // Customer not found
    }
}

/**
 * Handles SAP integration for an order.
 * Checks for customer existence, creates if not found, then sends the order.
 *
 * @param int $order_id The ID of the WooCommerce order.
 */
function sap_handle_order_integration($order_id) {
    // CRITICAL FIX: Add execution lock to prevent duplicate processing
    $lock_key = 'sap_processing_order_' . $order_id;
    $lock_value = get_transient($lock_key);
    
    if ($lock_value) {
        error_log('SAP Integration: Order ' . $order_id . ' is already being processed (locked). Skipping duplicate execution.');
        return;
    }
    
    // Set lock for 5 minutes to prevent duplicate processing
    set_transient($lock_key, time(), 300);
    
    $order = wc_get_order($order_id);

    if (!$order) {
        error_log('SAP Integration: Order not found for ID ' . $order_id);
        delete_transient($lock_key); // Release lock
        return;
    }

    // CRITICAL FIX: Only allow processing status AND validate payment completion
    $current_status = $order->get_status();

    if ($current_status !== 'processing') {
        error_log('SAP Integration: BLOCKED - Order ' . $order_id . ' has status "' . $current_status . '", only processing orders allowed for SAP integration');
        
        if (class_exists('SAP_Sync_Logger')) {
            SAP_Sync_Logger::log_sync_blocked($order_id, "Order status '{$current_status}' not allowed - only processing status accepted");
        }
        
        $order->add_order_note('SAP Integration blocked: Only processing orders can be sent to SAP. Current status: "' . $current_status . '"');
        delete_transient($lock_key); // Release lock
        return;
    }

    // Check if user has admin privileges or affiliates role to bypass payment validation
    $user_id = $order->get_customer_id();
    $user = $user_id ? get_user_by('id', $user_id) : null;
    $bypass_payment_validation = false;
    
    if ($user) {
        // Check if user is admin or has affiliates role
        if (user_can($user, 'manage_options') || in_array('affiliates', $user->roles)) {
            $bypass_payment_validation = true;
            $bypass_reason = user_can($user, 'manage_options') ? 'Admin user' : 'Affiliates role';
            $bypass_reason_hebrew = user_can($user, 'manage_options') ? 'משתמש אדמין' : 'תפקיד שותפים';
            
            error_log('SAP Integration: Payment validation bypassed for Order ' . $order_id . ' - Reason: ' . $bypass_reason . ' (User ID: ' . $user_id . ')');
            $order->add_order_note('אישור תשלום דולג עבור משתמש מורשה (' . $bypass_reason_hebrew . ') - הזמנה תישלח ל-SAP ללא אימות Yaad.');
            
            // Hebrew Telegram logging message
            $telegram_message = "🔓 דילוג על אימות תשלום\n" .
                              "הזמנה #{$order_id}\n" .
                              "סיבה: {$bypass_reason_hebrew}\n" .
                              "משתמש: {$user->display_name} (ID: {$user_id})\n" .
                              "הזמנה תישלח ל-SAP ללא אימות Yaad";
            
            error_log('SAP Integration Telegram: ' . str_replace("\n", " | ", $telegram_message));
        }
    }

    // CRITICAL FIX: MANDATORY Yaad payment validation - NO order can be sent to SAP without valid Yaad payment token
    // UNLESS user is admin or has affiliates role
    $payment_method = $order->get_payment_method();
    $yaad_payment_data = $order->get_meta('yaad_credit_card_payment');
    
    if (!$bypass_payment_validation) {
        // Block if no Yaad payment data exists at all
        if (empty($yaad_payment_data)) {
            error_log('SAP Integration: BLOCKED - Order ' . $order_id . ' missing yaad_credit_card_payment data (payment not completed). Payment method: ' . $payment_method);
            
            if (class_exists('SAP_Sync_Logger')) {
                SAP_Sync_Logger::log_sync_blocked($order_id, "Missing yaad_credit_card_payment data - payment not completed. No order can be sent to SAP without Yaad payment token.");
            }
            
            $order->add_order_note('SAP Integration blocked: Payment not completed (missing Yaad payment data). Orders can only be sent to SAP after successful Yaad payment processing.');
            delete_transient($lock_key); // Release lock
            return;
        }
        
        // Parse Yaad payment data to check CCode (0 = success) and ACode (approval code)
        parse_str($yaad_payment_data, $yaad_parsed);
        $ccode = isset($yaad_parsed['CCode']) ? $yaad_parsed['CCode'] : null;
        $acode = isset($yaad_parsed['ACode']) ? $yaad_parsed['ACode'] : null;
        
        // Block if payment was not successful
        if ($ccode !== '0' || empty($acode)) {
            error_log('SAP Integration: BLOCKED - Order ' . $order_id . ' payment failed or incomplete (CCode: ' . $ccode . ', ACode: ' . ($acode ?: 'empty') . ')');
            
            if (class_exists('SAP_Sync_Logger')) {
                SAP_Sync_Logger::log_sync_blocked($order_id, "Payment failed or incomplete - CCode: {$ccode}, ACode: " . ($acode ?: 'empty') . ". CCode must be '0' and ACode must exist.");
            }
            
            $order->add_order_note('SAP Integration blocked: Payment failed or incomplete (CCode: ' . $ccode . ', ACode: ' . ($acode ?: 'missing') . '). Orders can only be sent to SAP after successful payment.');
            delete_transient($lock_key); // Release lock
            return;
        }
        
        // Log successful validation
        error_log('SAP Integration: Order ' . $order_id . ' validated successfully - Status: processing, Payment: completed (CCode: 0, ACode: ' . $acode . ')');
    } else {
        // Log bypass validation
        error_log('SAP Integration: Order ' . $order_id . ' validated successfully - Status: processing, Payment validation bypassed for authorized user');
    }

    // Check if order should be synced (prevents duplicates) - NO RETRIES
    if (class_exists('SAP_Sync_Logger')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sap_order_sync_log';
        $sync_record = $wpdb->get_row($wpdb->prepare(
            "SELECT sync_status FROM $table_name WHERE order_id = %d",
            $order_id
        ));
        
        // Only skip if already successful or in progress (no retry logic)
        if ($sync_record && in_array($sync_record->sync_status, ['success', 'in_progress'])) {
            error_log('SAP Integration: Order ' . $order_id . ' already synced or in progress. Skipping.');
            delete_transient($lock_key); // Release lock
            return;
        }
    }

    // Log sync start
    if (class_exists('SAP_Sync_Logger')) {
        SAP_Sync_Logger::log_sync_start($order_id);
    }

    $customer_email = $order->get_billing_email();
    if (empty($customer_email)) {
        $error_msg = 'Customer email is empty for order ID ' . $order_id . '. Cannot proceed.';
        error_log('SAP Integration: ' . $error_msg);
        $order->add_order_note('שגיאה: כתובת מייל של הלקוח חסרה. לא ניתן לשלוח ל-SAP.');
        if (class_exists('SAP_Sync_Logger')) {
            SAP_Sync_Logger::log_sync_failure($order_id, $error_msg);
        }
        delete_transient($lock_key); // Release lock
        return;
    }

    $customer_phone = $order->get_billing_phone();
    $customer_first_name = $order->get_billing_first_name();
    $customer_last_name = $order->get_billing_last_name();
    $customer_full_name = trim($customer_first_name . ' ' . $customer_last_name);
    if (empty($customer_full_name)) {
        $customer_full_name = 'לקוח ווקומרס #' . $order->get_customer_id();
    }

    // WordPress user ID - will be used as U_WebSiteId for tracking
    $wp_user_id = $order->get_customer_id();
    
    // Note: CardCode will be auto-assigned by SAP B1 based on Series (71)
    error_log("SAP Integration: Customer creation will use Series 71 for auto-assigned CardCode");

    $customer_vat_id = $order->get_meta('_billing_vat_id'); // Assuming there's a custom field for VAT ID

    // Get customer note for address comments
    $customer_note = $order->get_customer_note();

    // Billing address details
    $billing_address_1 = $order->get_billing_address_1();
    $billing_address_2 = $order->get_billing_address_2(); // House number - built-in WordPress field
    $billing_city = $order->get_billing_city();
    $billing_postcode = $order->get_billing_postcode();
    $billing_country = $order->get_billing_country();
    

    // Shipping address details for SAP U_ fields
    $shipping_address_1 = $order->get_shipping_address_1() ?: $billing_address_1; // Fallback to billing if shipping not set
    $shipping_address_2 = $order->get_shipping_address_2() ?: $order->get_billing_address_2(); // Fallback to billing if shipping not set
    $shipping_city = $order->get_shipping_city() ?: $billing_city; // Fallback to billing if shipping not set
    $shipping_postcode = $order->get_shipping_postcode() ?: $billing_postcode; // Fallback to billing if shipping not set
    
    // Create address name combining customer name and city
    $address_name = $customer_full_name . " - " . $billing_city;

    $sap_customer_code = null;

    // --- Step 1: Search for existing customer by email first ---
    error_log('SAP Integration: Starting customer workflow for email: ' . $customer_email);
    
    // Get auth token - no retries
    $auth_token = sap_get_auth_token();
    
    if (is_wp_error($auth_token) || !$auth_token) {
        $error_msg = 'Failed to get SAP auth token: ' . ($auth_token ? $auth_token->get_error_message() : 'Unknown error');
        error_log('SAP Integration: ' . $error_msg);
        if (class_exists('SAP_Sync_Logger')) {
            SAP_Sync_Logger::log_sync_failure($order_id, $error_msg);
        }
        return;
    }
    
          $existing_customer_code = sap_search_customer_by_email_and_phone($customer_email, $customer_phone, $auth_token);
    
    if (is_wp_error($existing_customer_code)) {
        $error_msg = 'Error searching for existing customer: ' . $existing_customer_code->get_error_message();
        error_log('SAP Integration: ' . $error_msg);
        if (class_exists('SAP_Sync_Logger')) {
            SAP_Sync_Logger::log_sync_failure($order_id, $error_msg);
        }
        return;
    }
    
    if ($existing_customer_code) {
        // Customer found - use existing CardCode
        $sap_customer_code = $existing_customer_code;
        error_log('SAP Integration: Using existing customer with CardCode: ' . $sap_customer_code);
        $order->add_order_note('לקוח קיים נמצא ב-SAP (CardCode: ' . $sap_customer_code . ').');
    } else {
        // Customer not found - proceed with creation
        error_log('SAP Integration: Customer not found, creating new customer with email: ' . $customer_email);

        $customer_payload_for_upsert = [
        // DO NOT send CardCode - let SAP B1 auto-assign based on Series
        "CardName"        => $customer_full_name,
        "Series"          => 71, // SAP B1 will auto-assign next CardCode based on this Series
        "CardType"        => "cCustomer",
        "Phone1"          => $customer_phone,
        "PriceListNum"    => 1, // Ensure this is correct PriceListNum
        "SalesPersonCode" => 2, // Sales person code
        "EmailAddress"    => $customer_email,
        "ShipToDefault"   => $address_name,
        "VatIDNum"        => $customer_vat_id,
        "BPAddresses"     => [
            [
                "AddressName" => $address_name,
                "Street"      => $billing_address_1,
                "ZipCode"     => $billing_postcode,
                "City"        => $billing_city,
                "Country"     => "IL", // Note: Yaron set this to IL permanently
                "AddressType" => "bo_ShipTo",
                "StreetNo"     => $billing_address_2,
            ],
        ],
        "ContactEmployees" => [ // Add contact persons block as specified
            [
                "Name"        => $customer_full_name,
                "MobilePhone" => $customer_phone,
                "Active"      => "tYES",
                "FirstName"   => $customer_first_name,
                "LastName"    => $customer_last_name,
                "E_Mail"      => $customer_email,
            ],
        ],
        ];

        $upsert_customer_response = sap_api_post('Customers', $customer_payload_for_upsert, $auth_token);

        // Debug: Log the full response for troubleshooting
        error_log('SAP Customer Upsert Response: ' . print_r($upsert_customer_response, true));

        // Always check if response is WP_Error first (communication error/timeout/HTTP not 200/201)
        if (is_wp_error($upsert_customer_response)) {
            // If there's WP_Error, we still need to check if it contains "email already exists" message
            $error_data = $upsert_customer_response->get_error_data();
            $response_body_from_error = isset($error_data['response_body']) ? $error_data['response_body'] : [];
            $sap_error_message = isset($response_body_from_error['apiResponse']['result']['message']) ? $response_body_from_error['apiResponse']['result']['message'] : '';

            if (preg_match('/\[(\d+)\]/', $sap_error_message, $matches)) {
                // If email already exists, extract CardCode from error message
                $sap_customer_code = $matches[1];
                error_log('SAP Integration: Customer with email ' . $customer_email . ' already exists in SAP. CardCode extracted from error message: ' . $sap_customer_code);
                $order->add_order_note('לקוח קיים ב-SAP (CardCode: ' . $sap_customer_code . ').');
            } else {
                // This is another API error we couldn't handle
                $error_msg = 'Unhandled API error for customer upsert: ' . $upsert_customer_response->get_error_message();
                error_log('SAP Integration: ' . $error_msg);
                $order->add_order_note('שגיאה ביצירת/איתור לקוח ב-SAP: ' . $upsert_customer_response->get_error_message());
                if (class_exists('SAP_Sync_Logger')) {
                    SAP_Sync_Logger::log_sync_failure($order_id, $error_msg, $upsert_customer_response);
                }
                return;
            }
        }
        // If no WP_Error (meaning HTTP Status was 200),
        // check logical status of response
        else {
            // Case of new customer successfully created
            if (isset($upsert_customer_response['apiResponse']['status']) && $upsert_customer_response['apiResponse']['status'] === 0) {
                // Parse the JSON data string
                $data_json = $upsert_customer_response['apiResponse']['data'];
                $data_parsed = json_decode($data_json, true);
                
                if ($data_parsed && isset($data_parsed['CardCode'])) {
                    $sap_customer_code = $data_parsed['CardCode'];
                    error_log('SAP Integration: New customer created with CardCode: ' . $sap_customer_code);
                    $order->add_order_note('לקוח חדש נוצר ב-SAP (CardCode: ' . $sap_customer_code . ').');
                }
            }
            // Case of existing customer (HTTP 200 response with logical error)
            elseif (isset($upsert_customer_response['apiResponse']['result']['status']) && $upsert_customer_response['apiResponse']['result']['status'] === -10 &&
                     isset($upsert_customer_response['apiResponse']['result']['message']) &&
                     preg_match('/\[(\d+)\]/', $upsert_customer_response['apiResponse']['result']['message'], $matches)) {
                // If email already exists, extract CardCode from error message
                $sap_customer_code = $matches[1];
                error_log('SAP Integration: Customer with email ' . $customer_email . ' already exists in SAP. CardCode extracted from error message: ' . $sap_customer_code);
                $order->add_order_note('לקוח קיים ב-SAP (CardCode: ' . $sap_customer_code . ').');
            }
            else {
                $error_msg = 'Failed to retrieve CardCode after customer upsert. Unhandled logical response.';
                error_log('SAP Integration: ' . $error_msg . ' Full response: ' . print_r($upsert_customer_response, true));
                $order->add_order_note('שגיאה: לא התקבל CardCode מיצירת/איתור לקוח ב-SAP או שגיאה לוגית לא מטופלת.');
                if (class_exists('SAP_Sync_Logger')) {
                    SAP_Sync_Logger::log_sync_failure($order_id, $error_msg, $upsert_customer_response);
                }
                return; // Cannot continue without CardCode
            }
        }
    } // End of customer creation else block

    // --- Step 2: Prepare Order, Invoice, Payment data for OrderFlow ---
    if ($sap_customer_code) {
        $order_lines_for_sap = [];
        $invoice_lines_for_sap = [];
        $item_line_index = 0; // For BaseLine handling

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) {
                error_log('SAP Integration: Product not found for order item ID ' . $item_id . ' in order ' . $order_id);
                continue;
            }

            $product_sku = $product->get_sku();
            if (empty($product_sku)) {
                error_log('SAP Integration: Product SKU is empty for product ID ' . $product->get_id() . '. Skipping item.');
                continue;
            }

            $item_quantity = $item->get_quantity();
            $item_total = (float) $item->get_total(); // Total for item, including VAT if relevant (usually get_total() includes)
            $item_subtotal = (float) $item->get_subtotal(); // Total for item, before discounts and taxes
            $item_price_per_unit = $item_subtotal / $item_quantity; // Unit price before line discounts (if any)

            // Delivery date example - 14 days from today
            $ship_date = (new DateTime('now', new DateTimeZone('Asia/Jerusalem')))->add(new DateInterval('P14D'))->format('Y-m-d\TH:i:s\Z');

            // Order line data
            $order_lines_for_sap[] = [
                "ItemCode"        => $product_sku,
                "Quantity"        => (float) $item_quantity, // Ensure this is float
                "Price"           => (float) number_format($item_price_per_unit, 2, '.', ''), // Unit price before VAT and line discounts
                "BarCode"         => $product->get_meta('_barcode') ?: '', // Barcode - ensure field and mapping
                "LineTotal"       => (float) number_format($item_total, 2, '.', ''), // Line total including VAT (or as API expects)
            ];

            // Invoice line data (BaseLine needs to match line position in Order DocumentLines)
            $invoice_lines_for_sap[] = [
                "BaseType" => 17, // Base type - 17 is Order
                "BaseEntry" => 0, // This will be filled later with Order DocEntry
                "BaseLine" => $item_line_index, // Line number in order
            ];
            $item_line_index++;
        }

        if (empty($order_lines_for_sap)) {
            $error_msg = 'No valid order lines to send for order ID ' . $order_id;
            error_log('SAP Integration: ' . $error_msg);
            $order->add_order_note('שגיאה: אין פריטים חוקיים בהזמנה לשליחה ל-SAP.');
            if (class_exists('SAP_Sync_Logger')) {
            SAP_Sync_Logger::log_sync_failure($order_id, $error_msg);
        }
            return;
        }

        // DocDueDate - reference date (assume today, or any agreed date)
        $doc_due_date = (new DateTime('now', new DateTimeZone('Asia/Jerusalem')))->format('Y-m-d\TH:i:s\Z');

        // --- Payment data mapping ---
        $payment_data_for_sap = null;
        $order_payment_method = $order->get_payment_method();
        $order_total = (float) number_format($order->get_total(), 2, '.', '');
        $payment_transaction_id = $order->get_transaction_id(); // Reference / transaction number

        $credit_card_data = null;
        
        // Process payment data if we have valid Yaad payment data (regardless of user role)
        if (($order_payment_method === 'yaad_sarig_credit_card' || $order_payment_method === 'yaadpay') && !empty($yaad_payment_data)) {
            
            $last_4_digits = '0000';
            $card_exp_month = '12';
            $card_exp_year = '2025';
            $voucher_num = $payment_transaction_id ?: 'WC_ORDER_' . $order_id;
            
            // Parse the yaad payment string to extract values
            parse_str($yaad_payment_data, $yaad_parsed);
            
            // Extract values from parsed data
            $last_4_digits = $yaad_parsed['L4digit'] ?? '0000';
            $card_exp_month = $yaad_parsed['Tmonth'] ?? '12';
            $card_exp_year = $yaad_parsed['Tyear'] ?? '2025';
            
            // Extract ACode for VoucherNum - this is the approval code from Yaad
            $voucher_num = $yaad_parsed['ACode'] ?? ($payment_transaction_id ?: 'WC_ORDER_' . $order_id);

            // Expiration date in YYYY-MM-01T00:00:00Z format
            $card_valid_until = sprintf('%s-%s-01T00:00:00Z', $card_exp_year, str_pad($card_exp_month, 2, '0', STR_PAD_LEFT));

            // Determine credit card type - default to Visa Cal (1) for now
            // Available codes from SAP: 1=ויזה כאל, 2=ישראכרד, 3=אמריקן אקספרס, 4=לאומי כארד, 5=דיינרס
            $sap_credit_card_code = "1"; // Try as string instead of integer
            
            // Log credit card configuration for debugging
            error_log('SAP Integration: Credit card configuration - Code: ' . $sap_credit_card_code . ' (string), Last4: ' . $last_4_digits . ', VoucherNum: ' . $voucher_num);
            error_log('SAP Integration: Card expiration: ' . $card_valid_until . ', Order total: ' . $order_total);
            
            $credit_card_data = [
                "CreditCard"        => $sap_credit_card_code, // Valid SAP credit card code as string
                "CreditAcct"        => "1001", // Credit account matches SAP configuration
                "CreditCardNumber"  => $last_4_digits, // Last 4 digits only
                "CardValidUntil"    => $card_valid_until,
                "VoucherNum"        => $voucher_num, // Use ACode from Yaad payment data
                "PaymentMethodCode" => 2, // Payment method code, verify this is valid in OCTG table
                "NumOfPayments"     => 1, // Single payment
                "FirstPaymentDue"   => $doc_due_date,
                "FirstPaymentSum"   => $order_total,
                "CreditSum"         => $order_total,
                "CreditType"        => "cr_Regular", // Regular credit type
                "SplitPayments"     => "tNO", // No split payments
            ];
            
            $payment_data_for_sap = [
                "CardCode"      => $sap_customer_code,
                "Reference1"    => (string) $order->get_order_number(), // Use Reference1 for order number
                "JournalRemarks" => "Incoming Payments - " . $sap_customer_code,
                "Series"        => 81, // Payment series for incoming payments
                "PaymentInvoices" => [ // Invoice DocEntry will be filled after invoice creation
                    [
                        "DocEntry"    => 0, // This will be filled after invoice creation
                        "InvoiceType" => "it_Invoice",
                    ]
                ],
                "PaymentCreditCards" => [$credit_card_data],
            ];
            
            error_log('SAP Integration: Payment data configured - Series: 81, Customer: ' . $sap_customer_code . ', OrderRef: ' . $order->get_order_number());
            error_log('SAP Integration: Credit card data: ' . json_encode($credit_card_data, JSON_PRETTY_PRINT));
        } else {
            if ($bypass_payment_validation) {
                // Admin/Affiliates user sending order without payment data
                $woo_order_number = $order->get_order_number();
                $user_roles = $user ? $user->roles : [];
                $user_role_display = !empty($user_roles) ? implode(', ', $user_roles) : 'guest';
                
                error_log('SAP Integration: Payment data skipped for authorized user (admin/affiliates) - Order ' . $order_id . ' will be sent without payment block');
                $order->add_order_note('הזמנה נשלחת ל-SAP ללא בלוק תשלום (משתמש מורשה) - ייתכן שחשבונית לא תיפתח אוטומטית.');
                
                // Detailed Hebrew Telegram notification for order sent without payment data
                $telegram_no_payment_message = "⚠️ הזמנה נשלחת ללא נתוני תשלום\n" .
                                              "הזמנה WooCommerce: #{$woo_order_number}\n" .
                                              "קוד לקוח SAP: {$sap_customer_code}\n" .
                                              "אימייל לקוח: {$customer_email}\n" .
                                              "שם לקוח: {$customer_full_name}\n" .
                                              "תפקיד משתמש: {$user_role_display}\n" .
                                              "סיבה: משתמש מורשה ללא נתוני תשלום\n" .
                                              "סטטוס: הזמנה תישלח, חשבונית עלולה להיכשל";
                
                error_log('SAP Integration Telegram No Payment: ' . str_replace("\n", " | ", $telegram_no_payment_message));
                
            } else {
                error_log('SAP Integration: No credit card data or payment method not supported for order ' . $order_id);
                $order->add_order_note('אזהרה: פרטי תשלום באשראי אינם זמינים או נתמכים עבור SAP.');
            }
            $payment_data_for_sap = null; // Don't send Payment block if no credit card data
        }

        // --- Build unified payload for OrderFlow ---
        $order_flow_payload = [
            "Order" => [
                "DocDueDate"      => $doc_due_date,
                "CardCode"        => $sap_customer_code,
                "DocTotal"        => $order_total,
                "ImportFileNum"   => (string) $order->get_order_number(),
                "Comments"        => $customer_note,
                "JournalMemo"     => "WooCommerce Order #" . $order->get_order_number() . " from " . $customer_email,
                "SalesPersonCode" => 2,
                "Series"          => 77, // Order series
                "DocumentsOwner"  => 1,
                "DocumentLines"   => $order_lines_for_sap,
            ],
            "Invoice" => [
                "DocumentLines" => $invoice_lines_for_sap, // BaseEntry will be filled after order creation
            ],
        ];

        // Add Payment block only if relevant data exists
        if ($payment_data_for_sap) {
            $order_flow_payload['Payment'] = $payment_data_for_sap;
        }

        error_log('SAP Integration: Sending OrderFlow to SAP for order ID: ' . $order_id);
        error_log('SAP Integration: OrderFlow payload: ' . json_encode($order_flow_payload, JSON_PRETTY_PRINT));
        error_log('SAP Integration: Using extended timeout (180 seconds) for OrderFlow operation');
        $order_flow_response = sap_api_post_orderflow('OrderFlow', $order_flow_payload, $auth_token, 180);
        error_log('SAP Integration: OrderFlow response: ' . print_r($order_flow_response, true));
        
        // Log HTTP details if response is empty
        if (empty($order_flow_response)) {
            error_log('SAP Integration: Empty response detected. This suggests server-side API issue or timeout.');
        }

        if (is_wp_error($order_flow_response)) {
            $error_msg = 'Error sending OrderFlow to SAP: ' . $order_flow_response->get_error_message();
            error_log('SAP Integration: ' . $error_msg);
            $order->add_order_note('שגיאה בשליחת OrderFlow ל-SAP: ' . $order_flow_response->get_error_message());
            
            // Hebrew Telegram notification for OrderFlow error
            $telegram_error_message = "❌ שגיאה בשליחת OrderFlow ל-SAP\n" .
                                    "הזמנה #{$order_id}\n" .
                                    "לקוח: {$customer_full_name}\n" .
                                    "שגיאה: " . $order_flow_response->get_error_message() . "\n" .
                                    "סטטוס: נכשל";
            
            error_log('SAP Integration Telegram Error: ' . str_replace("\n", " | ", $telegram_error_message));
            
            // Actually send the Telegram message
            if (class_exists('SAP_Sync_Logger')) {
                SAP_Sync_Logger::send_telegram_message($telegram_error_message);
            }
            
            if (class_exists('SAP_Sync_Logger')) {
                SAP_Sync_Logger::log_sync_failure($order_id, $error_msg, $order_flow_response);
            }
        }
        // Access path to DocEntries in OrderFlow response
        elseif (
            isset($order_flow_response['apiResponse']['result']['status']) &&
            $order_flow_response['apiResponse']['result']['status'] === 0 &&
            isset($order_flow_response['apiResponse']['result']['message'])
        ) {
            // Success with new format - no DocEntries returned
            $success_message = $order_flow_response['apiResponse']['result']['message'];
            
            $order->update_meta_data('_sap_sync_status', 'success');
            $order->update_meta_data('_sap_sync_message', $success_message);
            
            // Change order status to custom "received" status
            $order->update_status('received', 'Order successfully sent to SAP - Status changed to Received');
            $order->save();
            
            // Log success without specific DocEntries
            $doc_entries = [
                'customer_doc_entry' => $sap_customer_code,
                'order_doc_entry' => 'created',
                'invoice_doc_entry' => $payment_data_for_sap ? 'created' : 'skipped_no_payment', 
                'payment_doc_entry' => $payment_data_for_sap ? 'created' : 'skipped_no_payment'
            ];
            
            if (class_exists('SAP_Sync_Logger')) {
                SAP_Sync_Logger::log_sync_success($order_id, $order_flow_response, $doc_entries);
            }
            
            $hebrew_note = $bypass_payment_validation ? 
                'הזמנה נשלחה בהצלחה ל-SAP (משתמש מורשה) ושונתה לסטטוס "התקבלה": ' . $success_message :
                'הזמנה נשלחה בהצלחה ל-SAP ושונתה לסטטוס "התקבלה": ' . $success_message;
            
            error_log('SAP Integration: OrderFlow successfully sent to SAP. Order status changed to received. ' . $success_message . ($bypass_payment_validation ? ' (Payment validation bypassed for authorized user)' : ''));
            $order->add_order_note($hebrew_note);
            
            // Hebrew Telegram logging for successful processing
            if ($bypass_payment_validation && !$payment_data_for_sap) {
                // Admin/Affiliates user sent order without payment data - this is the scenario you requested
                $woo_order_number = $order->get_order_number();
                $user_roles = $user ? $user->roles : [];
                $user_role_display = !empty($user_roles) ? implode(', ', $user_roles) : 'guest';
                
                $telegram_success_no_payment = "✅ הזמנה נוצרה ב-SAP ללא תשלום\n" .
                                              "הזמנה WooCommerce: #{$woo_order_number}\n" .
                                              "קוד לקוח SAP: {$sap_customer_code}\n" .
                                              "אימייל לקוח: {$customer_email}\n" .
                                              "שם לקוח: {$customer_full_name}\n" .
                                              "תפקיד משתמש: {$user_role_display}\n" .
                                              "סטטוס: הזמנה נוצרה, חשבונית ותשלום דולגו\n" .
                                              "הודעה: {$success_message}";
                
                error_log('SAP Integration Telegram Success No Payment: ' . str_replace("\n", " | ", $telegram_success_no_payment));
                
                // Actually send the Telegram message
                if (class_exists('SAP_Sync_Logger')) {
                    SAP_Sync_Logger::send_telegram_message($telegram_success_no_payment);
                }
                
            } elseif ($bypass_payment_validation) {
                // Admin/Affiliates user with payment data
                $telegram_success_message = "✅ הזמנה נשלחה בהצלחה ל-SAP (דילוג אימות)\n" .
                                          "הזמנה #{$order_id}\n" .
                                          "סטטוס: התקבלה\n" .
                                          "משתמש מורשה: {$user->display_name}\n" .
                                          "הודעה: {$success_message}";
                
                error_log('SAP Integration Telegram Success: ' . str_replace("\n", " | ", $telegram_success_message));
                
                // Actually send the Telegram message
                if (class_exists('SAP_Sync_Logger')) {
                    SAP_Sync_Logger::send_telegram_message($telegram_success_message);
                }
            }
        } 
        // Handle partial success cases for admin/affiliates users (order created but invoice/payment might fail)
        elseif ($bypass_payment_validation && 
                isset($order_flow_response['apiResponse']['result']) &&
                (strpos(strtolower($order_flow_response['apiResponse']['result']['message'] ?? ''), 'order') !== false ||
                 $order_flow_response['apiResponse']['result']['status'] === 0)) {
            
            // For admin/affiliates users, consider it successful if order was created, even if invoice/payment failed
            $partial_success_message = $order_flow_response['apiResponse']['result']['message'] ?? 'Order created in SAP';
            
            $order->update_meta_data('_sap_sync_status', 'partial_success');
            $order->update_meta_data('_sap_sync_message', $partial_success_message);
            
            // Change order status to custom "received" status
            $order->update_status('received', 'Order created in SAP (authorized user) - Status changed to Received');
            $order->save();
            
            $doc_entries = [
                'customer_doc_entry' => $sap_customer_code,
                'order_doc_entry' => 'created',
                'invoice_doc_entry' => 'may_have_failed', 
                'payment_doc_entry' => 'skipped_no_payment'
            ];
            
            if (class_exists('SAP_Sync_Logger')) {
                SAP_Sync_Logger::log_sync_success($order_id, $order_flow_response, $doc_entries);
            }
            
            error_log('SAP Integration: Order created in SAP for authorized user (admin/affiliates). Invoice may not have opened. Order status changed to received. ' . $partial_success_message);
            $order->add_order_note('הזמנה נוצרה בהצלחה ב-SAP (משתמש מורשה) ושונתה לסטטוס "התקבלה". ייתכן שחשבונית לא נפתחה: ' . $partial_success_message);
            
            // Hebrew Telegram logging for partial success
            $telegram_partial_message = "⚠️ הזמנה נוצרה ב-SAP (הצלחה חלקית)\n" .
                                      "הזמנה #{$order_id}\n" .
                                      "סטטוס: התקבלה\n" .
                                      "משתמש מורשה: {$user->display_name}\n" .
                                      "הערה: ייתכן שחשבונית לא נפתחה\n" .
                                      "הודעה: {$partial_success_message}";
            
            error_log('SAP Integration Telegram Partial: ' . str_replace("\n", " | ", $telegram_partial_message));
        } else {
            // Enhanced error detection for payment and invoice issues
            $response_message = $order_flow_response['apiResponse']['result']['message'] ?? '';
            $response_status = $order_flow_response['apiResponse']['result']['status'] ?? null;
            
            // Get user role information for detailed reporting
            $user_roles = $user ? $user->roles : [];
            $user_role_display = !empty($user_roles) ? implode(', ', $user_roles) : 'guest';
            
            // Check if order was created but invoice/payment failed
            $order_created = (stripos($response_message, 'order') !== false && 
                            (stripos($response_message, 'created') !== false || 
                             stripos($response_message, 'success') !== false));
            
            // Detect specific payment/invoice issues
            $is_payment_issue = (stripos($response_message, 'payment') !== false || 
                               stripos($response_message, 'credit') !== false ||
                               stripos($response_message, 'card') !== false);
            
            $is_invoice_issue = (stripos($response_message, 'invoice') !== false ||
                               stripos($response_message, 'document') !== false);
            
            // CASE 1: Order created but invoice/payment failed
            if ($order_created && ($is_payment_issue || $is_invoice_issue)) {
                $woo_order_number = $order->get_order_number();
                
                if ($is_payment_issue) {
                    $error_msg = 'Order created in SAP but payment processing failed: ' . $response_message;
                    $hebrew_error = 'הזמנה נוצרה ב-SAP אך התשלום נכשל: ' . $response_message;
                    
                    // Detailed Hebrew Telegram notification for order created but payment failed
                    $telegram_detailed_error = "⚠️ הזמנה נוצרה ב-SAP - תשלום נכשל\n" .
                                             "הזמנה WooCommerce: #{$woo_order_number}\n" .
                                             "קוד לקוח SAP: {$sap_customer_code}\n" .
                                             "אימייל לקוח: {$customer_email}\n" .
                                             "שם לקוח: {$customer_full_name}\n" .
                                             "תפקיד משתמש: {$user_role_display}\n" .
                                             "שגיאת תשלום: {$response_message}\n" .
                                             "סטטוס: הזמנה נוצרה, תשלום נכשל";
                    
                } elseif ($is_invoice_issue) {
                    $error_msg = 'Order created in SAP but invoice creation failed: ' . $response_message;
                    $hebrew_error = 'הזמנה נוצרה ב-SAP אך החשבונית נכשלה: ' . $response_message;
                    
                    // Detailed Hebrew Telegram notification for order created but invoice failed
                    $telegram_detailed_error = "⚠️ הזמנה נוצרה ב-SAP - חשבונית נכשלה\n" .
                                             "הזמנה WooCommerce: #{$woo_order_number}\n" .
                                             "קוד לקוח SAP: {$sap_customer_code}\n" .
                                             "אימייל לקוח: {$customer_email}\n" .
                                             "שם לקוח: {$customer_full_name}\n" .
                                             "תפקיד משתמש: {$user_role_display}\n" .
                                             "שגיאת חשבונית: {$response_message}\n" .
                                             "סטטוס: הזמנה נוצרה, חשבונית נכשלה";
                }
                
                error_log('SAP Integration Telegram Detailed Error: ' . str_replace("\n", " | ", $telegram_detailed_error));
                
                // Still change order status to received since order was created in SAP
                $order->update_status('received', 'Order created in SAP but invoice/payment failed - Status changed to Received');
                $order->save();
                
            } 
            // CASE 2: Pure payment issue (no order creation)
            elseif ($is_payment_issue) {
                $error_msg = 'Payment processing failed in SAP OrderFlow: ' . $response_message;
                $hebrew_error = 'שגיאה בעיבוד התשלום ב-SAP: ' . $response_message;
                
                // Hebrew Telegram notification for payment issue
                $telegram_payment_error = "💳 שגיאה בעיבוד תשלום ב-SAP\n" .
                                        "הזמנה #{$order_id}\n" .
                                        "לקוח: {$customer_full_name}\n" .
                                        "אימייל: {$customer_email}\n" .
                                        "תפקיד: {$user_role_display}\n" .
                                        "שגיאת תשלום: {$response_message}\n" .
                                        "סטטוס: נכשל בתשלום";
                
                error_log('SAP Integration Telegram Payment Error: ' . str_replace("\n", " | ", $telegram_payment_error));
                
            } 
            // CASE 3: Pure invoice issue (no order creation)
            elseif ($is_invoice_issue) {
                $error_msg = 'Invoice creation failed in SAP OrderFlow: ' . $response_message;
                $hebrew_error = 'שגיאה ביצירת חשבונית ב-SAP: ' . $response_message;
                
                // Hebrew Telegram notification for invoice issue
                $telegram_invoice_error = "🧾 שגיאה ביצירת חשבונית ב-SAP\n" .
                                        "הזמנה #{$order_id}\n" .
                                        "לקוח: {$customer_full_name}\n" .
                                        "אימייל: {$customer_email}\n" .
                                        "תפקיד: {$user_role_display}\n" .
                                        "שגיאת חשבונית: {$response_message}\n" .
                                        "סטטוס: נכשל בחשבונית";
                
                error_log('SAP Integration Telegram Invoice Error: ' . str_replace("\n", " | ", $telegram_invoice_error));
                
            } 
            // CASE 4: General error
            else {
                $error_msg = 'Failed to send OrderFlow to SAP or retrieve all DocEntries. Response structure unexpected.';
                $hebrew_error = 'שגיאה לא ידועה בשליחת OrderFlow ל-SAP או בקבלת כל ה-DocEntries.';
                
                // Hebrew Telegram notification for general error
                $telegram_general_error = "⚠️ שגיאה כללית ב-OrderFlow\n" .
                                        "הזמנה #{$order_id}\n" .
                                        "לקוח: {$customer_full_name}\n" .
                                        "אימייל: {$customer_email}\n" .
                                        "תפקיד: {$user_role_display}\n" .
                                        "שגיאה: {$response_message}\n" .
                                        "סטטוס: נכשל";
                
                error_log('SAP Integration Telegram General Error: ' . str_replace("\n", " | ", $telegram_general_error));
            }
            
            error_log('SAP Integration: ' . $error_msg . ' Full response: ' . print_r($order_flow_response, true));
            $order->add_order_note($hebrew_error);
            
            if (class_exists('SAP_Sync_Logger')) {
                SAP_Sync_Logger::log_sync_failure($order_id, $error_msg, $order_flow_response);
            }
        }
    } else {
        $error_msg = 'No SAP customer code available for order ' . $order_id . '. Skipping OrderFlow creation.';
        error_log('SAP Integration: ' . $error_msg);
        $order->add_order_note('שגיאה: לא ניתן לשלוח OrderFlow ל-SAP ללא קוד לקוח תקף.');
        if (class_exists('SAP_Sync_Logger')) {
            SAP_Sync_Logger::log_sync_failure($order_id, $error_msg);
        }
    }
    
    // Release lock at the end of processing (success or failure)
    delete_transient($lock_key);
}

// --- WooCommerce Hooks for SAP Integration ---

// Background-enabled SAP integration wrapper
function sap_handle_order_integration_background($order_id) {
    // CRITICAL FIX: Prevent duplicate hook execution with transient lock
    $hook_lock_key = 'sap_hook_processing_order_' . $order_id;
    $hook_lock_value = get_transient($hook_lock_key);
    
    if ($hook_lock_value) {
        $current_hook = current_filter();
        error_log('SAP Integration: Order ' . $order_id . ' hook already triggered recently (locked at ' . date('H:i:s', $hook_lock_value) . '). Skipping duplicate hook execution from: ' . $current_hook);
        return;
    }
    
    // Set hook lock for 60 seconds to prevent duplicate hook triggers
    $lock_time = time();
    set_transient($hook_lock_key, $lock_time, 60);
    
    $current_hook = current_filter();
    error_log('SAP Integration: Order ' . $order_id . ' hook lock set by: ' . $current_hook . ' at ' . date('H:i:s', $lock_time));
    
    // CRITICAL FIX: Re-validate order status before queuing
    $order = wc_get_order($order_id);
    if (!$order || $order->get_status() !== 'processing') {
        error_log('SAP Integration: Background job cancelled - Order ' . $order_id . ' status is "' . ($order ? $order->get_status() : 'not found') . '", only processing orders allowed');
        delete_transient($hook_lock_key); // Release hook lock
        return;
    }
    
    // Check for emergency instant mode (bypasses Action Scheduler)
    $emergency_mode = get_option('sap_emergency_instant_mode', false);
    
    if ($emergency_mode) {
        error_log("SAP Integration: Emergency instant mode enabled - processing order {$order_id} synchronously");
        sap_handle_order_integration($order_id);
        delete_transient($hook_lock_key); // Release hook lock
        return;
    }
    
    // Try background processing first
    if (class_exists('SAP_Background_Processor') && SAP_Background_Processor::is_action_scheduler_available()) {
        $job_id = SAP_Background_Processor::queue_order_integration($order_id);
        if ($job_id) {
            error_log("SAP Integration: Order {$order_id} queued for background processing (Job ID: {$job_id})");
            delete_transient($hook_lock_key); // Release hook lock
            return;
        }
    }
    
    // Fallback to synchronous processing
    error_log("SAP Integration: Background processing unavailable for order {$order_id}, falling back to synchronous");
    sap_handle_order_integration($order_id);
    delete_transient($hook_lock_key); // Release hook lock
}

// CRITICAL FIX: Use only one primary hook to prevent multiple executions
// Triggers SAP integration when order status changes to "processing" (this covers payment completion)
add_action('woocommerce_order_status_processing', 'sap_handle_order_integration_background', 20);

// Backup hook only for completed orders (in case processing was skipped)
add_action('woocommerce_order_status_completed', 'sap_handle_order_integration_background', 20);

// Triggers integration when order is saved/updated through WooCommerce admin interface
// Handle both traditional and HPOS (High-Performance Order Storage) scenarios
function sap_handle_admin_order_save($order_id, $post = null) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }
    if (isset($_REQUEST['bulk_edit'])) { // Skip bulk edits
        return;
    }

    // CRITICAL FIX: Skip admin save hooks if this is a status change
    // Status changes are handled by the dedicated status hooks
    if (isset($_POST['order_status']) || isset($_POST['_status'])) {
        error_log('SAP Integration: Skipping admin save hook - order status change detected, handled by status hooks');
        return;
    }

    $order = wc_get_order($order_id);
    if ($order && class_exists('SAP_Sync_Logger') && SAP_Sync_Logger::should_sync_order($order_id)) {
        sap_handle_order_integration_background($order_id);
    }
}

// Traditional hook for legacy order storage
add_action('woocommerce_process_shop_order_meta', 'sap_handle_admin_order_save', 50, 2);

// HPOS-compatible hook (WooCommerce 7.1+)
add_action('woocommerce_update_order', 'sap_handle_admin_order_save', 50, 1);




