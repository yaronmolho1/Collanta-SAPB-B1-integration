<?php
/**
 * SAP Product Creator - Create new WooCommerce products from SAP items
 *
 * @package My_SAP_Importer
 * @subpackage Includes
 */

// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ensure Action Scheduler can trigger the product creation
if (!function_exists('sap_create_products_async_handler')) {
function sap_create_products_async_handler() {
    if (function_exists('sap_create_products_from_api')) {
        return sap_create_products_from_api();
    }
}
}
add_action('sap_create_products_async', 'sap_create_products_async_handler');

// Public helper to enqueue the async job (can be called from admin/UI)
if (!function_exists('sap_enqueue_create_products')) {
function sap_enqueue_create_products() {
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('sap_create_products_async');
        return true;
    }
    return false;
}
}

// Telegram notification configuration for product creation
define('SAP_CREATOR_TELEGRAM_BOT_TOKEN', '8309945060:AAHKHfGtTf6D_U_JnapGrTHxOLcuht9ULA4');
define('SAP_CREATOR_TELEGRAM_CHAT_ID', '5418067438');

/**
 * Main function to create new products from SAP API
 * Pulls all items from SAP, then processes only items where U_SiteGroupID OR U_SiteItemID is null
 * This allows better handling of SWW groups where some items may already be imported
 *
 * @return string HTML output of the creation status
 */
if (!function_exists('sap_create_products_from_api')) {
    function sap_create_products_from_api()
    {
        // Ensure WooCommerce functions are available
        if (!function_exists('wc_get_product')) {
            return "<p style='color: red;'>שגיאה: ווקומרס אינו פעיל. אנא וודא שווקומרס מותקן ומופעל.</p>";
        }

        ob_start();

        echo "<h2>מתחיל תהליך יצירת מוצרים חדשים מ-SAP...</h2>";
        
        $start_time = microtime(true);
        $start_message = "תחילת יצירת מוצרים מ-SAP\n";
        $start_message .= "זמן: " . current_time('Y-m-d H:i:s');
        $telegram_start_result = sap_creator_send_telegram_message($start_message);
        
        if (is_wp_error($telegram_start_result)) {
            error_log("SAP Creator: Failed to send start notification: " . $telegram_start_result->get_error_message());
        } else {
            error_log("SAP Creator: Start notification sent successfully");
        }

        // 1. Connect and get token
        echo "<p>⏳ מתחבר ל-SAP API...</p>";
        flush();
        
        $token = sap_get_auth_token();
        
        if (!$token) {
            echo "<p style='color: red;'>שגיאה: נכשל בחיבור ל-SAP API. בדוק את פרטי ההתחברות.</p>";
            return ob_get_clean();
        }

        echo "<p style='color: green;'>✅ התחברות ל-SAP API בוצעה בהצלחה.</p>";

        // 2. Retrieve all items from SAP (no filtering)
        // This allows us to see complete SWW groups and handle cases where
        // some items in a group are already imported while others are not
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
                    "orderField" => "SWW",
                    "sortType" => "ASC"
                ],
                [
                    "orderField" => "ItemCode",
                    "sortType" => "ASC"
                ]
            ]
        ];

        echo "<p>⏳ שולח בקשה ל-SAP API לשליפת כל הפריטים...</p>";
        echo "<p><strong>ממתין לתגובת SAP מזורמת (עשוי לקחת עד 30 שניות)...</strong></p>";
        flush();

        $itemsResponse = sap_api_post('Items/get', $itemsRequest, $token);
        
        if (is_wp_error($itemsResponse)) {
            echo "<p style='color: red;'>❌ <strong>שגיאה בתגובת הזרמה:</strong> " . esc_html($itemsResponse->get_error_message()) . "</p>";
            
            $error_message = "יצירת מוצרים מ-SAP נכשלה\n";
            $error_message .= "Error: " . $itemsResponse->get_error_message() . "\n";
            $error_message .= "Time: " . current_time('Y-m-d H:i:s');
            sap_creator_send_telegram_message($error_message);
            
            return ob_get_clean();
        } else {
            echo "<p style='color: green;'>✅ <strong>תגובת הזרמה התקבלה בהצלחה!</strong></p>";
        }
        flush();

        // 3. Parse response (same logic as product import)
        $items = sap_parse_items_response($itemsResponse);
        
        if (empty($items)) {
            echo "<p style='color: orange;'>לא נמצאו פריטים ב-SAP.</p>";
            
            $empty_message = "יצירת מוצרים מ-SAP - לא נמצאו פריטים\n";
            $empty_message .= "תוצאה: לא נמצאו פריטים ב-API\n";
            $empty_message .= "Time: " . current_time('Y-m-d H:i:s');
            sap_creator_send_telegram_message($empty_message);
            
            return ob_get_clean();
        }

        echo "<p>נמצאו " . count($items) . " פריטים ב-SAP API.</p>";
        
        // 4. Filter items - only process items where at least one of U_SiteGroupID or U_SiteItemID is null/empty
        $filtered_items = [];
        $skipped_count = 0;
        
        foreach ($items as $item) {
            $site_group_id = $item['U_SiteGroupID'] ?? '';
            $site_item_id = $item['U_SiteItemID'] ?? '';
            
            // Process if at least one ID is empty/null (meaning not fully imported)
            if (empty($site_group_id) || empty($site_item_id)) {
                $filtered_items[] = $item;
            } else {
                $skipped_count++;
            }
        }
        
        echo "<p>מסנן פריטים: " . count($filtered_items) . " פריטים לעיבוד, " . $skipped_count . " פריטים שכבר מיובאים.</p>";
        
        if (empty($filtered_items)) {
            echo "<p style='color: orange;'>כל הפריטים כבר מיובאים ב-WooCommerce.</p>";
            
            $empty_message = "יצירת מוצרים מ-SAP - כל הפריטים כבר מיובאים\n";
            $empty_message .= "Result: All " . count($items) . " items already have U_SiteGroupID and U_SiteItemID\n";
            $empty_message .= "Time: " . current_time('Y-m-d H:i:s');
            sap_creator_send_telegram_message($empty_message);
            
            return ob_get_clean();
        }
        
        echo "<h3>פרטי יצירה:</h3>";

        // 5. Group filtered items by SWW
        $sww_groups = [];
        foreach ($filtered_items as $item) {
            $sww = $item['SWW'] ?? '';
            if (empty($sww)) {
                error_log("SAP Creator: Item {$item['ItemCode']} has empty SWW, skipping");
                continue;
            }
            
            if (!isset($sww_groups[$sww])) {
                $sww_groups[$sww] = [];
            }
            $sww_groups[$sww][] = $item;
        }

        echo "<p>מקבץ פריטים ל-" . count($sww_groups) . " קבוצות SWW.</p>";
        echo "<ul style='list-style-type: disc; margin-left: 20px;'>";

        // Statistics tracking
        $creation_stats = [
            'simple_created' => 0,
            'variable_created' => 0,
            'variations_created' => 0,
            'failed' => 0,
            'sap_update_failed' => 0
        ];
        
        $creation_log = [];
        $error_log = [];

        // 6. Process each SWW group
        $total_groups = count($sww_groups);
        $processed_groups = 0;
        
        foreach ($sww_groups as $sww => $group_items) {
            $processed_groups++;
            echo "<li><strong>SWW: " . esc_html($sww) . "</strong> (" . count($group_items) . " פריטים) - קבוצה {$processed_groups}/{$total_groups}<br>";
            
            // Send progress notification every 10 groups or for large groups
            if ($processed_groups % 10 === 0 || count($group_items) > 15) {
                $progress_message = "מעבד קבוצה {$processed_groups}/{$total_groups}\n";
                $progress_message .= "SWW: {$sww} ({" . count($group_items) . "} פריטים)\n";
                $progress_message .= "זמן: " . current_time('H:i:s');
                
                $progress_result = sap_creator_send_telegram_message($progress_message);
                if (is_wp_error($progress_result)) {
                    error_log("SAP Creator: Failed to send progress notification: " . $progress_result->get_error_message());
                }
            }
            
            if (count($group_items) === 1) {
                // Single item → Create simple product
                $result = sap_create_simple_product($group_items[0], $token);
                
                if (is_wp_error($result)) {
                    echo "<span style='color: red;'>✗ שגיאה ביצירת מוצר פשוט: " . esc_html($result->get_error_message()) . "</span><br>";
                    $creation_stats['failed']++;
                    $error_log[] = "✗ SKU: {$group_items[0]['ItemCode']} - {$result->get_error_message()}";
                } else {
                    echo "<span style='color: green;'>✓ מוצר פשוט נוצר בהצלחה (ID: {$result['product_id']})</span><br>";
                    $creation_stats['simple_created']++;
                    $creation_log[] = "מוצר פשוט - SKU: {$group_items[0]['ItemCode']}";
                    
                    if (!$result['sap_updated']) {
                        $creation_stats['sap_update_failed']++;
                        $error_log[] = "עדכון SAP נכשל - SKU: {$group_items[0]['ItemCode']}";
                    }
                }
            } else {
                // Multiple items → Check for existing parent or create variable product
                $result = sap_create_variable_product($group_items, $sww, $token);
                
                if (is_wp_error($result)) {
                    echo "<span style='color: red;'>✗ שגיאה ביצירת מוצר משתנה: " . esc_html($result->get_error_message()) . "</span><br>";
                    $creation_stats['failed'] += count($group_items);
                    $error_log[] = "✗ SWW: {$sww} - {$result->get_error_message()}";
                } else {
                    echo "<span style='color: green;'>✓ מוצר משתנה נוצר בהצלחה (Parent ID: {$result['parent_id']}, {$result['variations_count']} וריאציות)</span><br>";
                    $creation_stats['variable_created']++;
                    $creation_stats['variations_created'] += $result['variations_count'];
                    $creation_log[] = "מוצר משתנה - SWW: {$sww} ({$result['variations_count']} וריאציות)";
                    
                    if ($result['sap_update_failed'] > 0) {
                        $creation_stats['sap_update_failed'] += $result['sap_update_failed'];
                        $error_log[] = "עדכון SAP נכשל ל-{$result['sap_update_failed']} פריטים ב-SWW: {$sww}";
                    }
                }
            }
            
            echo "</li>";
            flush();
        }

        echo "</ul>";
        echo "<p style='color: green;'>תהליך יצירת מוצרים SAP הסתיים.</p>";

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);

        // PHASE 1 COMPLETE: Send creation completion notification
        try {
            error_log("SAP Creator: PHASE 1 COMPLETE - Sending creation summary notification");
            $creation_message = sap_creator_send_creation_completion_notification($creation_stats, $creation_log, $error_log, $duration);
            
            if (is_wp_error($creation_message)) {
                echo "<p style='color: orange;'>אזהרה: שליחת התראת יצירה נכשלה: " . $creation_message->get_error_message() . "</p>";
                error_log("SAP Creator: Creation notification failed: " . $creation_message->get_error_message());
        } else {
                echo "<p style='color: green;'>✅ התראת השלמת יצירה נשלחה בהצלחה.</p>";
                error_log("SAP Creator: Creation completion notification sent successfully");
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>שגיאה בשליחת הודעת יצירה: " . $e->getMessage() . "</p>";
            error_log("SAP Creator: Exception sending creation notification: " . $e->getMessage());
        }

        // Output collected SAP PATCH data
        sap_creator_output_collected_patch_data($creation_stats);
        
        // PHASE 2: Process SAP updates individually
        echo "<hr style='margin: 20px 0;'>";
        echo "<h3>🔄 שלב 2: עדכון SAP</h3>";
        echo "<p>מתחיל עדכון SAP עבור הפריטים שנוצרו...</p>";
        flush();
        
        $sap_update_results = sap_creator_process_sap_updates_individually($token);
        
        // PHASE 2 COMPLETE: Send SAP update summary notification
        try {
            error_log("SAP Creator: PHASE 2 COMPLETE - Sending SAP update summary notification");
            $sap_summary_result = sap_creator_send_sap_update_summary_notification($sap_update_results, $creation_stats);
            
            if (is_wp_error($sap_summary_result)) {
                echo "<p style='color: orange;'>אזהרה: שליחת סיכום עדכון SAP נכשלה: " . $sap_summary_result->get_error_message() . "</p>";
                error_log("SAP Creator: SAP summary notification failed: " . $sap_summary_result->get_error_message());
            } else {
                echo "<p style='color: green;'>✅ סיכום עדכון SAP נשלח בהצלחה.</p>";
                error_log("SAP Creator: SAP update summary notification sent successfully");
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>שגיאה בשליחת סיכום SAP: " . $e->getMessage() . "</p>";
            error_log("SAP Creator: Exception sending SAP summary: " . $e->getMessage());
        }

        return ob_get_clean();
    }
}

/**
 * Create a simple product from SAP item
 *
 * @param array $item SAP item data
 * @param string $token SAP auth token
 * @return array|WP_Error Product data on success, WP_Error on failure
 */
function sap_create_simple_product($item, $token) {
    $item_code = $item['ItemCode'] ?? '';
    $item_name = $item['ItemName'] ?? '';
    $sww = $item['SWW'] ?? '';
    
    if (empty($item_code) || empty($sww)) {
        return new WP_Error('invalid_data', 'ItemCode או SWW חסרים');
    }
    
    // Create simple product
    $product = new WC_Product_Simple();
    $product->set_name($sww); // Name = SWW value
    $product->set_sku($item_code);
    $product->set_status('pending'); // NOT published
    
    // Set price (SAP price × 1.18)
    $price_result = sap_set_product_price($product, $item);
    if (is_wp_error($price_result)) {
        error_log("SAP Creator: Price error for {$item_code}: " . $price_result->get_error_message());
    }
    
    // Set stock
    $stock_result = sap_set_product_stock($product, $item);
    if (is_wp_error($stock_result)) {
        error_log("SAP Creator: Stock error for {$item_code}: " . $stock_result->get_error_message());
    }
    
    // Set attributes (non-variation attributes)
    $attributes_result = sap_set_product_attributes($product, $item, false);
    if (is_wp_error($attributes_result)) {
        error_log("SAP Creator: Attributes error for {$item_code}: " . $attributes_result->get_error_message());
    }
    
    // Save product
    $product_id = $product->save();
    
    if (!$product_id) {
        return new WP_Error('save_failed', 'נכשל בשמירת מוצר פשוט');
    }
    
    // Update SAP with product ID
    // U_SiteGroupID = Product ID, U_SiteItemID = Product ID
    $sap_updated = sap_update_item_ids($item_code, $product_id, $product_id, $token);
    
    return [
        'product_id' => $product_id,
        'sap_updated' => $sap_updated
    ];
}

/**
 * Create a variable product with variations from SAP items
 * Creates proper variations with SKU, price*1.18, and correct attributes
 *
 * @param array $items Array of SAP item data (all same SWW)
 * @param string $sww SWW value for parent product name
 * @param string $token SAP auth token
 * @return array|WP_Error Product data on success, WP_Error on failure
 */
function sap_create_variable_product($items, $sww, $token) {
    error_log("SAP Creator: === Starting variable product creation for SWW: {$sww} ===");
    error_log("SAP Creator: Received " . count($items) . " items for SWW: {$sww}");
    
    if (empty($items) || empty($sww)) {
        error_log("SAP Creator: ERROR - Invalid data: items count=" . count($items) . ", SWW={$sww}");
        return new WP_Error('invalid_data', 'פריטים או SWW חסרים');
    }
    
    // Debug: Log first item structure
    if (!empty($items[0])) {
        $first_item = $items[0];
        error_log("SAP Creator: First item structure - ItemCode: " . ($first_item['ItemCode'] ?? 'not_set') . 
                 ", U_ssize: " . ($first_item['U_ssize'] ?? 'not_set') . 
                 ", U_scolor: " . ($first_item['U_scolor'] ?? 'not_set') . 
                 ", U_SiteItemID: " . ($first_item['U_SiteItemID'] ?? 'not_set'));
    }
    
    // Pre-calculate parent attributes for both new and existing parents
    $parent_attributes_to_apply = sap_create_variation_attributes($items);
    
    // Check if any item has U_SiteGroupID filled (existing parent)
    $existing_parent_id = null;
    foreach ($items as $item) {
        if (!empty($item['U_SiteGroupID']) && is_numeric($item['U_SiteGroupID'])) {
            $existing_parent_id = (int)$item['U_SiteGroupID'];
            break;
        }
    }
    
    $parent_id = null;
    
    if ($existing_parent_id) {
        // Use existing parent
        $parent_product = wc_get_product($existing_parent_id);
        if ($parent_product && $parent_product->is_type('variable')) {
            $parent_id = $existing_parent_id;
            error_log("SAP Creator: Using existing parent {$parent_id} for SWW {$sww}");
        } else {
            error_log("SAP Creator: U_SiteGroupID {$existing_parent_id} points to invalid parent, creating new");
        }
    }
    
    // Fallback: Try to find existing parent by custom meta (_sap_sww)
    if (!$parent_id) {
        $existing_by_meta = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_key'       => '_sap_sww',
            'meta_value'     => $sww,
            'fields'         => 'ids',
        ]);
        
        if (!empty($existing_by_meta)) {
            $maybe_parent = wc_get_product($existing_by_meta[0]);
            if ($maybe_parent && $maybe_parent->is_type('variable')) {
                $parent_product = $maybe_parent;
                $parent_id = $maybe_parent->get_id();
                error_log("SAP Creator: Found existing parent {$parent_id} for SWW {$sww} via _sap_sww meta");
            }
        }
    }
    
    // Fallback: Try to find existing parent by product title (SWW)
    if (!$parent_id) {
        $existing_product = get_page_by_title($sww, OBJECT, 'product');
        if ($existing_product) {
            $maybe_parent = wc_get_product($existing_product->ID);
            if ($maybe_parent && $maybe_parent->is_type('variable')) {
                $parent_product = $maybe_parent;
                $parent_id = $maybe_parent->get_id();
                error_log("SAP Creator: Found existing parent {$parent_id} for SWW {$sww} by title lookup");
            }
        }
    }
    
    // If parent already exists, ensure attributes include new values
    if ($parent_id && isset($parent_product) && $parent_product instanceof WC_Product_Variable) {
        if (!empty($parent_attributes_to_apply)) {
            $existing_attributes = $parent_product->get_attributes();
            if (!is_array($existing_attributes)) {
                $existing_attributes = [];
            }
            
            foreach ($parent_attributes_to_apply as $taxonomy => $new_attribute) {
                if (isset($existing_attributes[$taxonomy]) && $existing_attributes[$taxonomy] instanceof WC_Product_Attribute) {
                    $existing_attribute = $existing_attributes[$taxonomy];
                    $existing_options = array_map('strval', (array)$existing_attribute->get_options());
                    $new_options = array_map('strval', (array)$new_attribute->get_options());
                    $merged_options = array_unique(array_merge($existing_options, $new_options));
                    
                    $existing_attribute->set_options($merged_options);
                    $existing_attribute->set_visible(true);
                    $existing_attribute->set_variation(true);
                    $existing_attributes[$taxonomy] = $existing_attribute;
                    
                    error_log("SAP Creator: Updated existing parent attribute {$taxonomy} with options: " . implode(', ', $merged_options));
                } else {
                    $existing_attributes[$taxonomy] = $new_attribute;
                    error_log("SAP Creator: Added missing parent attribute {$taxonomy} for existing parent {$parent_id}");
                }
            }
            
            $parent_product->set_attributes($existing_attributes);
            $parent_product->save();
        }
    }
    
    // Create new parent if needed
    if (!$parent_id) {
        $parent_product = new WC_Product_Variable();
        $parent_product->set_name($sww); // Name = SWW value
        $parent_product->set_status('pending'); // NOT published - may need to publish for frontend visibility
        
        // Set price range for parent product based on variations
        $min_price = null;
        $max_price = null;
        foreach ($items as $item) {
            if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
                foreach ($item['ItemPrices'] as $price_entry) {
                    if (isset($price_entry['PriceList']) && $price_entry['PriceList'] === 1) {
                        $base_price = (float)($price_entry['Price'] ?? 0);
                        if ($base_price > 0) {
                            $price_with_vat = $base_price * 1.18;
                            $final_price = (float)(floor($price_with_vat) . '.9');
                            
                            if ($min_price === null || $final_price < $min_price) {
                                $min_price = $final_price;
                            }
                            if ($max_price === null || $final_price > $max_price) {
                                $max_price = $final_price;
                            }
                        }
                        break;
                    }
                }
            }
        }
        
        // Set price range on parent
        if ($min_price !== null && $max_price !== null) {
            $parent_product->set_price($min_price);
            $parent_product->set_regular_price($min_price);
            if ($min_price !== $max_price) {
                error_log("SAP Creator: Set parent price range {$min_price} - {$max_price} for SWW {$sww}");
            } else {
                error_log("SAP Creator: Set parent price {$min_price} for SWW {$sww}");
            }
        }
        
        // Set parent attributes (for variation use)
        if (!empty($parent_attributes_to_apply)) {
            $parent_product->set_attributes($parent_attributes_to_apply);
            error_log("SAP Creator: Set " . count($parent_attributes_to_apply) . " attributes on parent product for SWW {$sww}");
        } else {
            error_log("SAP Creator: WARNING - No parent attributes created for SWW {$sww}");
        }
        
        // Store SWW meta to help future lookups
        $parent_product->update_meta_data('_sap_sww', $sww);
        
        $parent_id = $parent_product->save();
        
        if (!$parent_id) {
            return new WP_Error('parent_save_failed', 'נכשל בשמירת מוצר אב');
        }
        
        error_log("SAP Creator: Created new variable parent {$parent_id} for SWW {$sww}");
    }
    
    // OPTIMIZED: Create variations in batch for much better performance
    $variations_created = 0;
    $sap_update_failed = 0;
    
    error_log("SAP Creator: Starting BATCH variation creation for " . count($items) . " items in SWW: {$sww}");

    // Filter items that need processing
    $items_to_process = [];
    foreach ($items as $item) {
        $item_code = $item['ItemCode'] ?? '';
        
        if (empty($item_code)) {
            error_log("SAP Creator: Skipping item with empty ItemCode");
            continue;
        }
        
        // Skip if variation already exists (has U_SiteItemID)
        if (!empty($item['U_SiteItemID'])) {
            error_log("SAP Creator: Skipping {$item_code} - already has U_SiteItemID: " . $item['U_SiteItemID']);
            continue;
        }
        
        $items_to_process[] = $item;
    }
    
    if (!empty($items_to_process)) {
        // Use batch creation for much better performance
        $batch_result = sap_create_variations_batch_optimized($items_to_process, $parent_id, $token);
        
        if (is_wp_error($batch_result)) {
            error_log("SAP Creator: BATCH creation failed: " . $batch_result->get_error_message());
            $sap_update_failed = count($items_to_process);
        } else {
            $variations_created = $batch_result['created_count'];
            $sap_update_failed = $batch_result['sap_update_failed'];
            error_log("SAP Creator: BATCH creation completed - Created: {$variations_created}, SAP Update Failed: {$sap_update_failed}");
        }
    }

    error_log("SAP Creator: Variation creation completed - Created: {$variations_created}, SAP Update Failed: {$sap_update_failed}");
    
    // CRITICAL: Clear product cache to ensure variations are visible (like creation_old.php)
    if ($variations_created > 0) {
        error_log("SAP Creator: Starting post-creation sync for parent {$parent_id}");
        
        // Clear all caches first
        wc_delete_product_transients($parent_id);
        wp_cache_delete($parent_id, 'posts');
        wp_cache_delete($parent_id, 'post_meta');
        
        // Get fresh parent product
        $parent_product = wc_get_product($parent_id);
        if ($parent_product && $parent_product->is_type('variable')) {
            error_log("SAP Creator: Starting sync for parent {$parent_id}");
            
            // Log current children before sync
            $children_before = $parent_product->get_children();
            error_log("SAP Creator: Parent {$parent_id} children before sync: " . count($children_before));
            
            // Debug: Check if variations exist in database
            global $wpdb;
            $db_variations = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_status FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                $parent_id
            ));
            error_log("SAP Creator: Database shows " . count($db_variations) . " variations for parent {$parent_id}");
            
            // Log each variation found in DB
            foreach ($db_variations as $db_var) {
                error_log("SAP Creator: DB Variation ID {$db_var->ID}, Status: {$db_var->post_status}, Title: {$db_var->post_title}");
            }
            
            // Force refresh parent product from database
            wp_cache_delete($parent_id, 'posts');
            clean_post_cache($parent_id);
            $parent_product = wc_get_product($parent_id);
            
            $parent_product->sync(false); // Sync variation prices to parent
            $parent_product->save(); // Save after sync
            
            // Clear cache again after sync
            wc_delete_product_transients($parent_id);
            wp_cache_delete($parent_id, 'posts');
            
            // Verify after sync with fresh object
            $parent_product = wc_get_product($parent_id); // Refresh again
            $children_after = $parent_product->get_children();
            $parent_attributes = $parent_product->get_attributes();
            
            error_log("SAP Creator: Parent {$parent_id} children after sync: " . count($children_after));
            error_log("SAP Creator: Parent {$parent_id} attributes: " . implode(', ', array_keys($parent_attributes)));
            
            // Verify each attribute is visible and for variations
            foreach ($parent_attributes as $attr_name => $attr) {
                $is_visible = $attr->get_visible();
                $is_variation = $attr->get_variation();
                error_log("SAP Creator: Attribute {$attr_name}: visible={$is_visible}, variation={$is_variation}");
            }
            
            // If still no children, try manual refresh
            if (count($children_after) === 0 && count($db_variations) > 0) {
                error_log("SAP Creator: CRITICAL - Variations exist in DB but parent doesn't see them. Attempting manual refresh.");
                
                // Try to manually trigger variation detection
                delete_transient('wc_product_children_' . $parent_id);
                wp_cache_delete('wc_product_children_' . $parent_id);
                
                // Force WooCommerce to rebuild variation cache
                $parent_product->sync_attributes();
                $parent_product->save();
                
                // Final check
                $parent_product = wc_get_product($parent_id);
                $final_children = $parent_product->get_children();
                error_log("SAP Creator: After manual refresh, parent {$parent_id} children: " . count($final_children));
            }
        }
        
        error_log("SAP Creator: Completed sync for parent product {$parent_id} with {$variations_created} variations");
    }
    
    return [
        'parent_id' => $parent_id,
        'variations_count' => $variations_created,
        'variations_updated' => 0,
        'sap_update_failed' => $sap_update_failed
    ];
}

/**
 * OPTIMIZED: Create multiple variations in batch for much better performance
 *
 * @param array $items Array of SAP item data
 * @param int $parent_id Parent product ID
 * @param string $token SAP auth token
 * @return array|WP_Error Batch result on success, WP_Error on failure
 */
function sap_create_variations_batch_optimized($items, $parent_id, $token) {
    $created_count = 0;
    $sap_update_failed = 0;
    $created_variations = [];
    
    error_log("SAP Creator: Starting OPTIMIZED batch creation of " . count($items) . " variations for parent {$parent_id}");
    
    // PERFORMANCE: Process in smaller chunks to prevent timeouts
    $chunk_size = 10; // Process 10 variations at a time
    $item_chunks = array_chunk($items, $chunk_size);
    $total_chunks = count($item_chunks);
    
    error_log("SAP Creator: Processing {$total_chunks} chunks of {$chunk_size} items each");
    
    try {
        foreach ($item_chunks as $chunk_index => $chunk_items) {
            $chunk_num = $chunk_index + 1;
            error_log("SAP Creator: Processing chunk {$chunk_num}/{$total_chunks} with " . count($chunk_items) . " items");
            
            // Prepare variations data for this chunk
            $variations_to_create = [];
            foreach ($chunk_items as $item) {
                $item_code = $item['ItemCode'] ?? '';
                $item_name = $item['ItemName'] ?? '';
                
                if (empty($item_code)) {
                    continue;
                }
                
                // Pre-calculate all data
                $variation_data = [
                    'item_code' => $item_code,
                    'item_name' => $item_name,
                    'item' => $item
                ];
                
                // Pre-calculate price
                if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
                    foreach ($item['ItemPrices'] as $price_entry) {
                        if (isset($price_entry['PriceList']) && $price_entry['PriceList'] === 1) {
                            $raw_price = $price_entry['Price'] ?? 0;
                            if (is_numeric($raw_price) && $raw_price > 0) {
                                $price_with_vat = $raw_price * 1.18;
                                $variation_data['price'] = floor($price_with_vat) . '.9';
                            }
                            break;
                        }
                    }
                }
                
                // Pre-calculate stock
                $variation_data['stock'] = 0;
                if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
                    foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
                        if (isset($warehouse_info['InStock']) && is_numeric($warehouse_info['InStock'])) {
                            $variation_data['stock'] = max(0, (int)$warehouse_info['InStock']);
                            break;
                        }
                    }
                }
                
                // Pre-calculate attributes (OPTIMIZE: Create terms once per chunk)
                $variation_data['attributes'] = [];
                if (!empty($item['U_ssize'])) {
                    $size_value = trim($item['U_ssize']);
                    $size_slug = sanitize_title($size_value);
                    sap_ensure_term_exists('pa_size', $size_value, $size_slug);
                    $variation_data['attributes']['pa_size'] = $size_slug;
                }
                if (!empty($item['U_scolor'])) {
                    $color_value = trim($item['U_scolor']);
                    $color_slug = sanitize_title($color_value);
                    sap_ensure_term_exists('pa_color', $color_value, $color_slug);
                    $variation_data['attributes']['pa_color'] = $color_slug;
                }
                
                $variations_to_create[] = $variation_data;
            }
            
            // Create variations for this chunk
            foreach ($variations_to_create as $var_data) {
                try {
                    // Create variation object with all pre-calculated data
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id($parent_id);
                    $variation->set_name($var_data['item_name']);
                    $variation->set_sku($var_data['item_code']);
                    $variation->set_status('private'); // CRITICAL: Use 'private' status for variations
                    
                    // Set pre-calculated price
                    if (isset($var_data['price'])) {
                        $variation->set_regular_price($var_data['price']);
                        $variation->set_price($var_data['price']);
                    }
                    
                    // Set pre-calculated stock
                    $variation->set_manage_stock(true);
                    $variation->set_stock_quantity($var_data['stock']);
                    $variation->set_stock_status($var_data['stock'] > 0 ? 'instock' : 'outofstock');
                    
                    // Set pre-calculated attributes
                    if (!empty($var_data['attributes'])) {
                        $variation->set_attributes($var_data['attributes']);
                    }
                    
                    // Save variation
                    $variation_id = $variation->save();
                    
                    if ($variation_id) {
                        $created_count++;
                        $created_variations[] = [
                            'variation_id' => $variation_id,
                            'item_code' => $var_data['item_code']
                        ];
                        
                        // Log every 10th creation to reduce log spam (performance optimization)
                        if ($created_count % 10 === 0) {
                            error_log("SAP Creator: BATCH created {$created_count} variations so far...");
                        }
                    } else {
                        error_log("SAP Creator: BATCH failed to create variation for {$var_data['item_code']}");
                    }
                } catch (Exception $e) {
                    error_log("SAP Creator: Exception creating variation for {$var_data['item_code']}: " . $e->getMessage());
                }
            }
            
            // PERFORMANCE: Clear memory after each chunk
            unset($variations_to_create);
            
            // PERFORMANCE: Brief pause between chunks to prevent server overload
            if ($chunk_num < $total_chunks) {
                usleep(100000); // 0.1 second pause
            }
        }
        
        error_log("SAP Creator: Completed variation creation - Created: {$created_count} variations");
        
        // Batch update SAP with all created variations
        if (!empty($created_variations)) {
            error_log("SAP Creator: Starting BATCH SAP updates for " . count($created_variations) . " variations");
            
            // Process SAP updates in smaller batches to avoid timeouts
            $sap_batch_size = 3; // Reduced from 5 to 3 for better reliability
            $sap_batches = array_chunk($created_variations, $sap_batch_size);
            
            foreach ($sap_batches as $batch_index => $batch) {
                // Log only every 3rd batch to reduce spam (performance optimization)
                if (($batch_index + 1) % 3 === 0 || $batch_index === 0 || $batch_index === count($sap_batches) - 1) {
                    error_log("SAP Creator: Processing SAP batch " . ($batch_index + 1) . "/" . count($sap_batches));
                }
                
                foreach ($batch as $created_var) {
                    try {
                        $sap_updated = sap_update_item_ids($created_var['item_code'], $parent_id, $created_var['variation_id'], $token);
                        if (!$sap_updated) {
                            $sap_update_failed++;
                        }
                    } catch (Exception $e) {
                        // Only log exceptions, not routine collection
                        if (strpos($e->getMessage(), 'collection') === false) {
                            error_log("SAP Creator: Exception updating SAP for {$created_var['item_code']}: " . $e->getMessage());
                        }
                        $sap_update_failed++;
                    }
                }
                
                // Brief pause between SAP batches (reduced from 0.2s to 0.1s)
                usleep(100000); // 0.1 second pause
            }
        }
        
    } catch (Exception $e) {
        error_log("SAP Creator: CRITICAL Exception in batch creation: " . $e->getMessage());
        return new WP_Error('batch_creation_failed', 'Batch creation failed: ' . $e->getMessage());
    }
    
    error_log("SAP Creator: OPTIMIZED batch creation completed - Created: {$created_count}, SAP Update Failed: {$sap_update_failed}");
    
    return [
        'created_count' => $created_count,
        'sap_update_failed' => $sap_update_failed,
        'variations' => $created_variations
    ];
}

/**
 * Create a product variation (LEGACY - kept for compatibility)
 *
 * @param array $item SAP item data
 * @param int $parent_id Parent product ID
 * @param string $token SAP auth token
 * @return array|WP_Error Variation data on success, WP_Error on failure
 */
function sap_create_variation($item, $parent_id, $token) {
    $item_code = $item['ItemCode'] ?? '';
    $item_name = $item['ItemName'] ?? '';
    
    error_log("SAP Creator: === Creating variation for {$item_code} ===");
    
    if (empty($item_code)) {
        error_log("SAP Creator: ERROR - ItemCode is empty");
        return new WP_Error('invalid_data', 'ItemCode חסר');
    }
    
    error_log("SAP Creator: Creating WC_Product_Variation for {$item_code}");
    $variation = new WC_Product_Variation();
    $variation->set_parent_id($parent_id);
    $variation->set_name($item_name); // Name from ItemName
    $variation->set_sku($item_code);
    $variation->set_status('private'); // CRITICAL: Variations must be 'private' for WooCommerce to recognize them
    error_log("SAP Creator: Basic variation properties set for {$item_code}");
    
    // Set price (SAP price × 1.18)
    $price_result = sap_set_product_price($variation, $item);
    if (is_wp_error($price_result)) {
        error_log("SAP Creator: Price error for variation {$item_code}: " . $price_result->get_error_message());
    }
    
    // Set stock
    $stock_result = sap_set_product_stock($variation, $item);
    if (is_wp_error($stock_result)) {
        error_log("SAP Creator: Stock error for variation {$item_code}: " . $stock_result->get_error_message());
    }
    
    // Set variation attributes (only size and color from U_ssize and U_scolor)
    $variation_attributes = [];
    
    // Size attribute from U_ssize only
    if (!empty($item['U_ssize'])) {
        $size_value = trim($item['U_ssize']);
        $size_slug = sanitize_title($size_value);
        sap_ensure_term_exists('pa_size', $size_value, $size_slug);
        $variation_attributes['pa_size'] = $size_slug;
        error_log("SAP Creator: Set size attribute '{$size_value}' for variation {$item_code}");
    }
    
    // Color attribute from U_scolor only
    if (!empty($item['U_scolor'])) {
        $color_value = trim($item['U_scolor']);
        $color_slug = sanitize_title($color_value);
        sap_ensure_term_exists('pa_color', $color_value, $color_slug);
        $variation_attributes['pa_color'] = $color_slug;
        error_log("SAP Creator: Set color attribute '{$color_value}' for variation {$item_code}");
    }
    
    // Log if no attributes found
    if (empty($variation_attributes)) {
        error_log("SAP Creator: No attributes found for variation {$item_code} - U_ssize: " . ($item['U_ssize'] ?? 'not_set') . ", U_scolor: " . ($item['U_scolor'] ?? 'not_set'));
    } else {
        error_log("SAP Creator: Found attributes for variation {$item_code}: " . implode(', ', array_keys($variation_attributes)));
    }
    
    if (!empty($variation_attributes)) {
        $variation->set_attributes($variation_attributes);
        error_log("SAP Creator: Set attributes for {$item_code}: " . json_encode($variation_attributes));
    } else {
        error_log("SAP Creator: WARNING - No attributes set for {$item_code}");
    }
    
    // Save variation
    error_log("SAP Creator: Attempting to save variation for {$item_code}");
    $variation_id = $variation->save();
    
    if (!$variation_id) {
        error_log("SAP Creator: CRITICAL ERROR - Failed to save variation for {$item_code}");
        return new WP_Error('save_failed', 'נכשל בשמירת וריאציה');
    }
    
    error_log("SAP Creator: Successfully saved variation ID {$variation_id} for {$item_code}");
    
    // Verify variation was created correctly
    $saved_variation = wc_get_product($variation_id);
    if ($saved_variation && $saved_variation->is_type('variation')) {
        $var_parent_id = $saved_variation->get_parent_id();
        $var_attributes = $saved_variation->get_attributes();
        $var_status = $saved_variation->get_status();
        error_log("SAP Creator: Variation {$variation_id} verification - Parent: {$var_parent_id}, Status: {$var_status}, Attrs: " . json_encode($var_attributes));
        
        // CRITICAL: Verify parent_id matches what we set
        if ($var_parent_id != $parent_id) {
            error_log("SAP Creator: CRITICAL ERROR - Variation {$variation_id} parent_id mismatch! Expected: {$parent_id}, Got: {$var_parent_id}");
        }
        
        // Check if variation appears in parent's children immediately
        $parent_check = wc_get_product($parent_id);
        if ($parent_check) {
            $current_children = $parent_check->get_children();
            $is_child_found = in_array($variation_id, $current_children);
            error_log("SAP Creator: Parent {$parent_id} currently has " . count($current_children) . " children, variation {$variation_id} found: " . ($is_child_found ? 'YES' : 'NO'));
        }
    } else {
        error_log("SAP Creator: ERROR - Variation {$variation_id} not found or wrong type after save");
    }
    
    // Update SAP with IDs
    // U_SiteGroupID = Parent ID, U_SiteItemID = Variation ID
    error_log("SAP Creator: Updating SAP with GroupID={$parent_id}, ItemID={$variation_id} for {$item_code}");
    $sap_updated = sap_update_item_ids($item_code, $parent_id, $variation_id, $token);
    
    return [
        'variation_id' => $variation_id,
        'sap_updated' => $sap_updated
    ];
}

/**
 * Create variations for a variable product
 * Uses the exact same logic as creation_old.php
 *
 * @param int $product_id Parent product ID
 * @param array $items Array of SAP items
 * @return array|WP_Error Array of variation IDs mapped to ItemCode on success
 */
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
            
            // Set attributes with fallbacks and validation
            $variation_attributes = [];
            
            // Size attribute from U_ssize only (as per your requirements)
            $size_value = null;
            if (!empty($item['U_ssize'])) {
                $size_value = trim($item['U_ssize']);
            }
            
            if ($size_value) {
                $variation_attributes['pa_size'] = sanitize_title($size_value);
            }
            
            // Color attribute from U_scolor only (as per your requirements)
            $color_value = null;
            if (!empty($item['U_scolor'])) {
                $color_value = trim($item['U_scolor']);
            }
            
            if ($color_value) {
                $variation_attributes['pa_color'] = sanitize_title($color_value);
            }
            
            // Log if no attributes found
            if (empty($variation_attributes)) {
                error_log("SAP Creator: No attributes found for variation {$item_code} - U_ssize: " . ($item['U_ssize'] ?? 'not_set') . ", U_scolor: " . ($item['U_scolor'] ?? 'not_set'));
            }
            
            $variation->set_attributes($variation_attributes);
            
            // Set price with comprehensive fallback logic (like creation_old.php)
            $price_set = false;
            if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
                // Get price using PriceList 1 with fallback
                $price_result = sap_get_price_from_item($item, $item_code);
                if ($price_result['price'] !== null) {
                    $variation->set_regular_price($price_result['price']);
                    $price_set = true;
                    if ($price_result['used_fallback']) {
                        error_log("SAP Creator: Used fallback PriceList {$price_result['pricelist_used']} for variation {$item_code}");
                    }
                }
            }
            
            if (!$price_set) {
                error_log("SAP Creator: No valid price found for variation {$item_code} - skipping");
            }
            
            // Set stock (InStock - 10 from ItemWarehouseInfoCollection) - like creation_old.php
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
            
            // Set custom fields (like creation_old.php)
            $variation->update_meta_data('_sap_item_code', $item_code);
            $variation->update_meta_data('_sap_group_code', $item['ItemsGroupCode'] ?? '');
            
            // Save variation
            $variation_id = $variation->save();
            
            if ($variation_id) {
                $variation_ids[$item_code] = $variation_id;
                error_log("SAP Creator: Created variation ID {$variation_id} for SKU {$item_code}");
            } else {
                error_log("SAP Creator: Failed to create variation for SKU {$item_code}");
            }
        }
        
        // Clear product cache to ensure variations are visible (like creation_old.php)
        wc_delete_product_transients($product_id);
        
        // Update parent product price range after adding variations (like creation_old.php)
        $parent_product = wc_get_product($product_id);
        if ($parent_product && $parent_product->is_type('variable')) {
            $parent_product->sync(false); // Sync variation prices to parent
            $parent_product->save(); // Save after sync
        }
        
        return $variation_ids;
        
    } catch (Exception $e) {
        error_log('SAP Creator: Error creating variations - ' . $e->getMessage());
        return new WP_Error('variation_creation_error', 'שגיאה ביצירת וריאציות: ' . $e->getMessage());
    }
}

/**
 * Update SAP with new WooCommerce product and variation IDs
 * Based on creation_old.php logic but using existing SAP update functions
 *
 * @param array $items Original SAP items
 * @param int $product_id WooCommerce product ID
 * @param array $variation_ids Array mapping ItemCode to variation ID
 * @param string $auth_token SAP authentication token
 * @return bool True on success, false on failure
 */
function sap_update_sap_with_site_ids($items, $product_id, $variation_ids, $auth_token) {
    if (!is_array($items) || !is_array($variation_ids)) {
        error_log("SAP Creator: Invalid items or variation_ids data for SAP update");
        return false;
    }
    
    error_log('SAP Creator: Starting SAP updates for ' . count($items) . ' items');
    
    $success_count = 0;
    $error_count = 0;
    
    // Update each item individually
    foreach ($items as $item) {
        $item_code = $item['ItemCode'] ?? '';
        
        if (empty($item_code) || !isset($variation_ids[$item_code])) {
            error_log("SAP Creator: Skipping item {$item_code} - missing variation ID");
            continue;
        }
        
        $variation_id = $variation_ids[$item_code];
        
        // Use the existing sap_update_item_ids function
        $update_success = sap_update_item_ids($item_code, $product_id, $variation_id, $auth_token);
        
        if ($update_success) {
            $success_count++;
            error_log("SAP Creator: Successfully updated item {$item_code} with GroupID={$product_id}, ItemID={$variation_id}");
        } else {
            $error_count++;
            error_log("SAP Creator: Failed to update item {$item_code}");
        }
    }
    
    if ($error_count > 0) {
        error_log("SAP Creator: SAP update completed with errors: {$success_count} succeeded, {$error_count} failed");
    } else {
        error_log("SAP Creator: SAP update completed successfully: {$success_count} items updated");
    }
    
    // Return true if at least half succeeded
    return $success_count >= ($error_count + $success_count) / 2;
}

/**
 * Get price from SAP item preferring PriceList 1 with fallback
 * Uses the exact same logic as creation_old.php
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
            $price_with_vat = $base_price * 1.18;
            $calculated_price = floor($price_with_vat) . '.9';
            return [
                'price' => $calculated_price,
                'used_fallback' => false,
                'pricelist_used' => 1
            ];
        }
    }
    
    // Fallback: Look for any other valid price list
    foreach ($item['ItemPrices'] as $price_entry) {
        if (isset($price_entry['PriceList']) && is_numeric($price_entry['PriceList']) &&
            isset($price_entry['Price']) && is_numeric($price_entry['Price']) && $price_entry['Price'] > 0) {
            $base_price = (float)$price_entry['Price'];
            $price_with_vat = $base_price * 1.18;
            $calculated_price = floor($price_with_vat) . '.9';
            return [
                'price' => $calculated_price,
                'used_fallback' => true,
                'pricelist_used' => (int)$price_entry['PriceList']
            ];
        }
    }
    
    return ['price' => null, 'used_fallback' => false, 'pricelist_used' => null];
}
}

/**
 * Set product price from SAP data (price × 1.18)
 *
 * @param WC_Product $product Product object
 * @param array $item SAP item data
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function sap_set_product_price($product, $item) {
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
        $b2c_price_with_vat = $b2c_raw_price * 1.18;
        // Round to nearest x.9 (e.g., 123.45 * 1.18 = 145.67 -> 145.9)
        $b2c_final_price = floor($b2c_price_with_vat) . '.9';
        
        $product->set_regular_price($b2c_final_price);
        $product->set_price($b2c_final_price);
        
        return true;
    }
    
    return new WP_Error('invalid_price', 'מחיר לא תקין או חסר מ-PriceList 1');
}

/**
 * Set product stock from SAP data
 * Rule: If source stock is 0 or negative, set to 0
 *
 * @param WC_Product $product Product object
 * @param array $item SAP item data
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function sap_set_product_stock($product, $item) {
    $sap_quantity_on_hand = null;
    
    if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
        foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
            if (isset($warehouse_info['InStock'])) {
                $sap_quantity_on_hand = $warehouse_info['InStock'];
                break;
            }
        }
    }
    
    // Handle numeric stock values
    if (is_numeric($sap_quantity_on_hand)) {
        // Rule: If stock is 0 or negative, set to 0
        $final_stock = max(0, (int)$sap_quantity_on_hand);
        
        $product->set_manage_stock(true);
        $product->set_stock_quantity($final_stock);
        $product->set_stock_status($final_stock > 0 ? 'instock' : 'outofstock');
        
        return true;
    }
    
    // Default to 0 stock if no valid stock data
    $product->set_manage_stock(true);
    $product->set_stock_quantity(0);
    $product->set_stock_status('outofstock');
    
    return true; // Don't return error for missing stock data
}

/**
 * Set product attributes (non-variation) - danier only
 * Rule: Skip missing attributes gracefully, do not create errors
 *
 * @param WC_Product $product Product object
 * @param array $item SAP item data
 * @param bool $for_variations Whether these are for variations
 * @return bool Always returns true (no errors for missing attributes)
 */
function sap_set_product_attributes($product, $item, $for_variations = false) {
    $attributes_array = [];
    
    // Danier attribute (NOT for variations, NOT visible)
    if (!empty($item['U_sdanier'])) {
        if (!taxonomy_exists('pa_danier')) {
            // Rule: Skip missing attributes gracefully
            error_log('SAP Creator: pa_danier attribute missing - skipping');
        } else {
            $danier_slug = sanitize_title($item['U_sdanier']);
            sap_ensure_term_exists('pa_danier', $item['U_sdanier'], $danier_slug);
            
            $danier_attribute = new WC_Product_Attribute();
            $danier_attribute->set_id(5); // Attribute ID 5
            $danier_attribute->set_name('pa_danier');
            $danier_attribute->set_options([$danier_slug]);
            $danier_attribute->set_position(2);
            $danier_attribute->set_visible(false); // NOT visible
            $danier_attribute->set_variation(false); // NOT for variations
            
            $attributes_array['pa_danier'] = $danier_attribute;
        }
    }
    
    if (!empty($attributes_array)) {
        $product->set_attributes($attributes_array);
    }
    
    // Always return true - no errors for missing attributes
    return true;
}

/**
 * Create variation attributes for parent variable product
 * Only creates size and color attributes (for variations)
 * Uses U_ssize and U_scolor fields only
 *
 * @param array $items Array of items in same SWW group
 * @return array Array of WC_Product_Attribute objects
 */
function sap_create_variation_attributes($items) {
    $attributes_array = [];
    $size_values = [];
    $color_values = [];
    
    // Collect all unique size and color values from items (U_ssize and U_scolor only)
    foreach ($items as $item) {
        $item_code = $item['ItemCode'] ?? 'unknown';
        
        // Size attribute from U_ssize only
        if (!empty($item['U_ssize'])) {
            $size_values[] = trim($item['U_ssize']);
            error_log("SAP Creator: Found size '{$item['U_ssize']}' in item {$item_code}");
        }
        
        // Color attribute from U_scolor only
        if (!empty($item['U_scolor'])) {
            $color_values[] = trim($item['U_scolor']);
            error_log("SAP Creator: Found color '{$item['U_scolor']}' in item {$item_code}");
        }
    }
    
    $size_values = array_unique($size_values);
    $color_values = array_unique($color_values);
    
    error_log("SAP Creator: Unique sizes found: " . implode(', ', $size_values));
    error_log("SAP Creator: Unique colors found: " . implode(', ', $color_values));
    
    // Size attribute (ID 4) - EXACTLY like creation_old.php
    if (!empty($size_values)) {
        $size_attribute = new WC_Product_Attribute();
        $size_attribute->set_id(4); // Attribute ID 4
        $size_attribute->set_name('pa_size');
        // CRITICAL: Use RAW VALUES for parent attributes (like creation_old.php)
        $size_attribute->set_options($size_values);
        $size_attribute->set_position(0);
        $size_attribute->set_visible(true); // TRUE - visible on product page
        $size_attribute->set_variation(true);
        
        $attributes_array['pa_size'] = $size_attribute;
        
        // Ensure size taxonomy exists and terms are created
        sap_ensure_attribute_terms('pa_size', 'Size', $size_values);
        error_log("SAP Creator: Created size attribute with raw values: " . implode(', ', $size_values));
    }
    
    // Color attribute (ID 3) - EXACTLY like creation_old.php
    if (!empty($color_values)) {
        $color_attribute = new WC_Product_Attribute();
        $color_attribute->set_id(3); // Attribute ID 3
        $color_attribute->set_name('pa_color');
        // CRITICAL: Use RAW VALUES for parent attributes (like creation_old.php)
        $color_attribute->set_options($color_values);
        $color_attribute->set_position(1);
        $color_attribute->set_visible(true); // TRUE - visible on product page
        $color_attribute->set_variation(true);
        
        $attributes_array['pa_color'] = $color_attribute;
        
        // Ensure color taxonomy exists and terms are created
        sap_ensure_attribute_terms('pa_color', 'Color', $color_values);
        error_log("SAP Creator: Created color attribute with raw values: " . implode(', ', $color_values));
    }
    
    error_log("SAP Creator: Returning " . count($attributes_array) . " parent attributes: " . implode(', ', array_keys($attributes_array)));
    return $attributes_array;
}

/**
 * DEPRECATED: Batch create variations using WooCommerce API (like blueprint)
 * This function is no longer used - variations are created individually
 * 
 * @param int $parent_id Parent product ID
 * @param array $items Array of SAP items to create as variations
 * @param string $token SAP auth token
 * @return array|WP_Error Result data on success, WP_Error on failure
 */
function sap_batch_create_variations($parent_id, $items, $token) {
    $variations_data = [];
    
    // Prepare variation data for batch creation
    foreach ($items as $item) {
        $variation_data = [
            'sku' => $item['ItemCode'],
            'manage_stock' => true,
            'attributes' => []
        ];
        
        // Set price
        if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
            foreach ($item['ItemPrices'] as $price_entry) {
                if (isset($price_entry['PriceList']) && $price_entry['PriceList'] === 1) {
                    $b2c_raw_price = $price_entry['Price'] ?? 0;
                    $b2c_price_with_vat = $b2c_raw_price * 1.18;
                    $b2c_final_price = ceil($b2c_price_with_vat);
                    $variation_data['regular_price'] = (string)$b2c_final_price;
                    break;
                }
            }
        }
        
        // Set stock - apply stock handling rule (0 or negative becomes 0)
        $stock_quantity = 0;
        if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
            foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
                if (isset($warehouse_info['InStock'])) {
                    $stock_quantity = max(0, (int)$warehouse_info['InStock']);
                    break;
                }
            }
        }
        $variation_data['stock_quantity'] = $stock_quantity;
        
        // Set attributes from U_ssize and U_scolor only
        // Size attribute from U_ssize
        if (!empty($item['U_ssize']) && taxonomy_exists('pa_size')) {
            $size_value = trim($item['U_ssize']);
            $size_slug = sanitize_title($size_value);
            sap_ensure_term_exists('pa_size', $size_value, $size_slug);
            $variation_data['attributes'][] = [
                'id' => 4,
                'option' => $size_value
            ];
        }
        
        // Color attribute from U_scolor
        if (!empty($item['U_scolor']) && taxonomy_exists('pa_color')) {
            $color_value = trim($item['U_scolor']);
            $color_slug = sanitize_title($color_value);
            sap_ensure_term_exists('pa_color', $color_value, $color_slug);
            $variation_data['attributes'][] = [
                'id' => 3,
                'option' => $color_value
            ];
        }
        
        $variations_data[] = $variation_data;
    }
    
    if (empty($variations_data)) {
        return new WP_Error('no_variations', 'לא נמצאו וריאציות תקינות ליצירה');
    }
    
    // Create variations using WooCommerce
    $created_count = 0;
    $sap_update_failed = 0;
    $created_variations = [];
    
    foreach ($variations_data as $index => $variation_data) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent_id);
        $variation->set_sku($variation_data['sku']);
        $variation->set_manage_stock($variation_data['manage_stock']);
        $variation->set_stock_quantity($variation_data['stock_quantity']);
        $variation->set_status('pending');
        
        if (isset($variation_data['regular_price'])) {
            $variation->set_regular_price($variation_data['regular_price']);
        }
        
        // Set variation attributes
        $variation_attributes = [];
        foreach ($variation_data['attributes'] as $attr) {
            if ($attr['id'] == 4) { // Size
                $variation_attributes['pa_size'] = sanitize_title($attr['option']);
            } elseif ($attr['id'] == 3) { // Color
                $variation_attributes['pa_color'] = sanitize_title($attr['option']);
            }
        }
        if (!empty($variation_attributes)) {
            $variation->set_attributes($variation_attributes);
        }
        
        $variation_id = $variation->save();
        
        if ($variation_id) {
            $created_count++;
            $created_variations[] = [
                'id' => $variation_id,
                'parent_id' => $parent_id,
                'sku' => $variation_data['sku']
            ];
            
            // Update SAP with IDs
            $item = $items[$index];
            $sap_updated = sap_update_item_ids($item['ItemCode'], $parent_id, $variation_id, $token);
            if (!$sap_updated) {
                $sap_update_failed++;
            }
        }
    }
    
    return [
        'created_count' => $created_count,
        'sap_update_failed' => $sap_update_failed,
        'variations' => $created_variations
    ];
}

/**
 * DEPRECATED: Batch update existing variations using WooCommerce API (like blueprint)
 * This function is no longer used - variations are handled individually
 *
 * @param int $parent_id Parent product ID  
 * @param array $items Array of SAP items to update (have U_SiteItemID)
 * @param string $token SAP auth token
 * @return array|WP_Error Result data on success, WP_Error on failure
 */
function sap_batch_update_variations($parent_id, $items, $token) {
    $updated_count = 0;
    
    foreach ($items as $item) {
        $variation_id = (int)$item['U_SiteItemID'];
        $variation = wc_get_product($variation_id);
        
        if (!$variation || !$variation->is_type('variation')) {
            continue;
        }
        
        // Update price
        if (isset($item['ItemPrices']) && is_array($item['ItemPrices'])) {
            foreach ($item['ItemPrices'] as $price_entry) {
                if (isset($price_entry['PriceList']) && $price_entry['PriceList'] === 1) {
                    $b2c_raw_price = $price_entry['Price'] ?? 0;
                    $b2c_price_with_vat = $b2c_raw_price * 1.18;
                    $b2c_final_price = ceil($b2c_price_with_vat);
                    $variation->set_regular_price($b2c_final_price);
                    break;
                }
            }
        }
        
        // Update stock - apply stock handling rule (0 or negative becomes 0)
        $stock_quantity = 0;
        if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
            foreach ($item['ItemWarehouseInfoCollection'] as $warehouse_info) {
                if (isset($warehouse_info['InStock'])) {
                    $stock_quantity = max(0, (int)$warehouse_info['InStock']);
                    break;
                }
            }
        }
        $variation->set_stock_quantity($stock_quantity);
        $variation->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
        
        $variation->save();
        $updated_count++;
    }
    
    return [
        'updated_count' => $updated_count
    ];
}

/**
 * Ensure taxonomy term exists, create if needed
 *
 * @param string $taxonomy Taxonomy slug
 * @param string $term_name Term name
 * @param string $term_slug Term slug
 * @return bool True if exists or created
 */
function sap_ensure_term_exists($taxonomy, $term_name, $term_slug) {
    if (!taxonomy_exists($taxonomy)) {
        error_log("SAP Creator: Taxonomy {$taxonomy} does not exist");
        return false;
    }
    
    $term = term_exists($term_slug, $taxonomy);
    
    if (!$term) {
        $result = wp_insert_term($term_name, $taxonomy, ['slug' => $term_slug]);
        
        if (is_wp_error($result)) {
            error_log("SAP Creator: Failed to create term {$term_name} in {$taxonomy}: " . $result->get_error_message());
            return false;
        }
        
        error_log("SAP Creator: Created term {$term_name} ({$term_slug}) in {$taxonomy}");
    }
    
    return true;
}

/**
 * Ensure attribute taxonomy exists and create terms
 * Based on creation_old.php logic - DOES NOT RETURN SLUGS
 *
 * @param string $taxonomy Taxonomy slug (e.g., 'pa_size')
 * @param string $label Attribute label (e.g., 'Size')
 * @param array $terms Array of term names
 */
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
            error_log("SAP Creator: Failed to create attribute {$label}: " . $created_attr_id->get_error_message());
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
                error_log("SAP Creator: Failed to insert term {$term_name} for attribute {$taxonomy}: " . $inserted_term->get_error_message());
            }
        }
    }
}

/**
 * SAP API PATCH request
 * Used for updating SAP items with WooCommerce IDs
 * Uses same pattern as working POST functions
 *
 * @param string $endpoint API endpoint
 * @param array $data Request data
 * @param string $token SAP auth token
 * @return array|WP_Error Response data or error
 */
function sap_api_patch($endpoint, $data = [], $token = null) {
    // If no token provided, try to get one (like POST functions)
    if (is_null($token)) {
        $token = sap_get_auth_token();
        if (is_wp_error($token)) {
            return $token; // Return authentication error
        }
    }
    
    $url = SAP_API_BASE . '/' . $endpoint; // Use same URL pattern as POST
    
    $headers = [
        'Content-Type'  => 'application/json',
        'Accept'        => '*/*', // Use same Accept header as POST
        'Authorization' => 'Bearer ' . $token,
    ];

    $args = [
        'method'      => 'PATCH',
        'headers'     => $headers,
        'body'        => json_encode($data), // Use json_encode like POST functions
        'timeout'     => 180, // Use same timeout as POST functions
        'data_format' => 'body',
        'sslverify'   => defined('WP_DEBUG') && WP_DEBUG ? false : true, // Same SSL setting as POST
    ];

    error_log('SAP API Patch Request URL: ' . $url);
    error_log('SAP API Patch Request Headers: ' . json_encode($headers, JSON_PRETTY_PRINT));
    error_log('SAP API Patch Request Body: ' . json_encode($data, JSON_PRETTY_PRINT));

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        error_log('SAP API Patch Error (' . $endpoint . '): ' . $response->get_error_message());
        return new WP_Error('api_error', 'SAP API Patch Error (' . $endpoint . '): ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $http_code = wp_remote_retrieve_response_code($response);
    
    error_log('SAP API Patch Response (HTTP ' . $http_code . '): ' . substr($body, 0, 500));
    
    if ($http_code !== 200 && $http_code !== 204) {
        error_log("SAP API HTTP Error {$http_code} for {$endpoint}: " . substr($body, 0, 500));
        return new WP_Error('sap_api_http_error', "HTTP {$http_code} for {$endpoint}: " . substr($body, 0, 200));
    }

    // Parse response if there's a body (200), or return success for no content (204)
    if (empty($body) || $http_code === 204) {
        return ['success' => true];
    }
    
    return sap_parse_api_response($body, $endpoint);
}

/**
 * Parse API response from SAP
 * Same function as in sap-products-import.php
 *
 * @param string $body Response body
 * @param string $endpoint Endpoint for logging
 * @return array|WP_Error Parsed response or error
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
 * Same function as in sap-products-import.php
 */
if (!function_exists('sap_clean_json_response')) {
function sap_clean_json_response($response) {
    // Remove UTF-8 BOM if present
    if (substr($response, 0, 3) === "\xEF\xBB\xBF") {
        $response = substr($response, 3);
    }
    
    // Remove ALL control characters
    $response = preg_replace('/[\x00-\x1F\x7F]/', '', $response);
    
    return trim($response);
}
}

/**
 * Fallback function to update SAP using POST method
 * Used when PATCH method is not supported (HTTP 405)
 *
 * @param string $item_code SAP ItemCode
 * @param int $site_group_id WooCommerce parent/product ID
 * @param int $site_item_id WooCommerce variation/product ID
 * @param string $token SAP auth token
 * @return bool True on success, false on failure
 */
function sap_update_item_ids_fallback($item_code, $site_group_id, $site_item_id, $token) {
    error_log("SAP Creator: Using POST fallback for {$item_code}");
    
    // Use batch endpoint with POST method
    $endpoint = 'items';
    $batch_data = [
        [
            'itemCode' => $item_code,
            'U_SiteGroupID' => (string)$site_group_id,
            'U_SiteItemID' => (string)$site_item_id
        ]
    ];
    
    $response = sap_api_post($endpoint, $batch_data, $token);
    
    if (is_wp_error($response)) {
        error_log("SAP Creator: Fallback POST also failed for {$item_code}: " . $response->get_error_message());
        return false;
    }
    
    error_log("SAP Creator: Fallback POST succeeded for {$item_code}");
    return true;
}

/**
 * Update SAP item with WooCommerce IDs
 * 
 * Uses endpoint: Items/{itemcode} with PATCH method
 * For variations: GroupID = parent ID, ItemID = variation ID
 * For simple products: GroupID = product ID, ItemID = product ID
 * Enhanced error handling for HTTP 403 and other issues
 *
 * @param string $item_code SAP ItemCode
 * @param int $site_group_id WooCommerce parent/product ID
 * @param int $site_item_id WooCommerce variation/product ID
 * @param string $token SAP auth token
 * @return bool True on success, false on failure
 */
function sap_update_item_ids($item_code, $site_group_id, $site_item_id, $token) {
    global $sap_creator_patch_data_collection;
    
    if (!isset($sap_creator_patch_data_collection)) {
        $sap_creator_patch_data_collection = [];
    }
    
    if (empty($item_code) || empty($token)) {
        error_log("SAP Creator: Invalid parameters for SAP update - ItemCode: {$item_code}");
        return false;
    }
    
    // Collect data for summary/debug output
    $sap_creator_patch_data_collection[] = [
        'ItemCode' => $item_code,
        'U_EM_SiteGroupID' => (string)$site_group_id,
        'U_EM_SiteItemID' => (string)$site_item_id
    ];
    
    $update_data = [
        'U_SiteGroupID' => (string)$site_group_id,
        'U_SiteItemID' => (string)$site_item_id
    ];
    
    $endpoint = 'Items/' . urlencode($item_code);
    
    error_log("SAP Creator: Updating SAP item {$item_code} with GroupID={$site_group_id}, ItemID={$site_item_id}");
    
    $response = sap_api_patch($endpoint, $update_data, $token);
    
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log("SAP Creator: SAP PATCH failed for {$item_code}: {$error_message}");
        
        if (strpos($error_message, '403') !== false) {
            sap_creator_send_critical_error_notification(
                "HTTP 403 Error",
                "Failed to update SAP item {$item_code} - Permission denied. Check API credentials and permissions."
            );
        }
        
        // Fallback to POST method when PATCH not allowed
        if (strpos($error_message, '405') !== false) {
            error_log("SAP Creator: HTTP 405 for {$item_code} - trying POST fallback");
            return sap_update_item_ids_fallback($item_code, $site_group_id, $site_item_id, $token);
        }
        
        return false;
    }
    
    error_log("SAP Creator: Successfully updated SAP for {$item_code}");
    return true;
}

/**
 * Parse items from SAP API response
 * Uses same logic as sap-products-import.php
 *
 * @param mixed $itemsResponse SAP API response
 * @return array Array of items
 */
function sap_parse_items_response($itemsResponse) {
    $items = [];
    
    // NEWEST FORMAT: Direct array of objects
    if (is_array($itemsResponse) && isset($itemsResponse[0]) && is_array($itemsResponse[0]) && isset($itemsResponse[0]['ItemCode'])) {
        $items = $itemsResponse;
        error_log("SAP Creator: Using CONCATENATED format - " . count($items) . " items");
    }
    // NEW FORMAT: Direct ['items'] array
    elseif (isset($itemsResponse['items']) && is_array($itemsResponse['items'])) {
        $items = $itemsResponse['items'];
        error_log("SAP Creator: Using NEW API format - " . count($items) . " items");
    }
    // NEW FORMAT: JSON string with value array
    elseif (isset($itemsResponse['apiResponse']['data']) && is_string($itemsResponse['apiResponse']['data'])) {
        $decoded_data = json_decode($itemsResponse['apiResponse']['data'], true);
        if ($decoded_data && isset($decoded_data['value']) && is_array($decoded_data['value'])) {
            $items = $decoded_data['value'];
            error_log("SAP Creator: Using JSON string format - " . count($items) . " items");
        }
    }
    // OLD FORMAT: Nested Results
    elseif (isset($itemsResponse['apiResponse']['result']['data']['Results']) && is_array($itemsResponse['apiResponse']['result']['data']['Results'])) {
        $items = $itemsResponse['apiResponse']['result']['data']['Results'];
        error_log("SAP Creator: Using OLD NESTED Results format - " . count($items) . " items");
    }
    // OLD FORMAT: Nested value
    elseif (isset($itemsResponse['apiResponse']['result']['data']['value']) && is_array($itemsResponse['apiResponse']['result']['data']['value'])) {
        $items = $itemsResponse['apiResponse']['result']['data']['value'];
        error_log("SAP Creator: Using OLD NESTED value format - " . count($items) . " items");
    }
    // OLD FORMAT: Nested data
    elseif (isset($itemsResponse['apiResponse']['result']['data']) && is_array($itemsResponse['apiResponse']['result']['data'])) {
        $items = $itemsResponse['apiResponse']['result']['data'];
        error_log("SAP Creator: Using OLD NESTED data format - " . count($items) . " items");
    }
    // Direct Results
    elseif (isset($itemsResponse['Results']) && is_array($itemsResponse['Results'])) {
        $items = $itemsResponse['Results'];
        error_log("SAP Creator: Using direct Results format - " . count($items) . " items");
    }
    // Direct data
    elseif (isset($itemsResponse['data']) && is_array($itemsResponse['data'])) {
        $items = $itemsResponse['data'];
        error_log("SAP Creator: Using direct data format - " . count($items) . " items");
    }
    
    return $items;
}

/**
 * Send message to Telegram
 *
 * @param string $message Message to send (in Hebrew)
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function sap_creator_send_telegram_message($message) {
    // Check configuration
    if (empty(SAP_CREATOR_TELEGRAM_BOT_TOKEN) || empty(SAP_CREATOR_TELEGRAM_CHAT_ID)) {
        error_log('SAP Creator: Telegram configuration missing - BOT_TOKEN or CHAT_ID empty');
        return new WP_Error('telegram_config', 'Telegram configuration missing');
    }
    
    $url = "https://api.telegram.org/bot" . SAP_CREATOR_TELEGRAM_BOT_TOKEN . "/sendMessage";
    
    $data = [
        'chat_id' => SAP_CREATOR_TELEGRAM_CHAT_ID,
        'text' => $message,
        'disable_web_page_preview' => true
    ];
    
    $args = [
        'method' => 'POST',
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($data),
        'timeout' => 30, // Increased timeout from 15 to 30 seconds
        'sslverify' => false // Add SSL verify false for potential SSL issues
    ];
    
    error_log('SAP Creator: Sending Telegram message to chat ' . SAP_CREATOR_TELEGRAM_CHAT_ID . ', length: ' . strlen($message));
    
    $response = wp_remote_post($url, $args);
    
    if (is_wp_error($response)) {
        error_log('SAP Creator Telegram failed: ' . $response->get_error_message());
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    if ($response_code !== 200) {
        $error_msg = 'Telegram API error: ' . $response_code . ' - ' . $response_body;
        error_log('SAP Creator: ' . $error_msg);
        return new WP_Error('telegram_api', $error_msg);
    }
    
    // Log successful send
    error_log('SAP Creator: Telegram message sent successfully');
    return true;
}

/**
 * Send product creation summary to Telegram
 * All messages in Hebrew, batch format, only ✓ and ✗ icons
 *
 * @param array $stats Creation statistics
 * @param array $success_log Success messages
 * @param array $error_log Error messages
 * @param float $duration Duration in seconds
 * @return bool|WP_Error
 */
function sap_creator_send_summary_notification($stats, $success_log, $error_log, $duration) {
    // Validate stats array to prevent undefined index errors
    $stats = array_merge([
        'simple_created' => 0,
        'variable_created' => 0,
        'variations_created' => 0,
        'failed' => 0,
        'sap_update_failed' => 0
    ], $stats);
    
    $total_success = $stats['simple_created'] + $stats['variable_created'];
    $total_failed = $stats['failed'];
    
    $status = ($total_failed === 0 && $stats['sap_update_failed'] === 0) ? "הושלם בהצלחה" : "הושלם עם שגיאות";
    $message = $status . " - יצירת מוצרים מ-SAP\n\n";
    
    // Summary
    $message .= "סיכום: {$total_success} הצליחו, {$total_failed} נכשלו\n\n";
    
    // Statistics
    $message .= "פרטים:\n";
    $message .= "מוצרים פשוטים: {$stats['simple_created']}\n";
    $message .= "מוצרים משתנים: {$stats['variable_created']}\n";
    $message .= "וריאציות: {$stats['variations_created']}\n";
    
    if ($stats['sap_update_failed'] > 0) {
        $message .= "עדכוני SAP נכשלו: {$stats['sap_update_failed']}\n";
    }
    
    // Limit logs to prevent message being too long (Telegram 4096 char limit)
    $max_logs = 5; // Reduced from 10 to prevent length issues
    
    // Success log (first 5 items)
    if (!empty($success_log)) {
        $message .= "\nהצלחות:\n";
        foreach (array_slice($success_log, 0, $max_logs) as $log_entry) {
            // Sanitize log entry to prevent HTML issues
            $clean_entry = strip_tags($log_entry);
            $message .= $clean_entry . "\n";
        }
        if (count($success_log) > $max_logs) {
            $message .= "ועוד " . (count($success_log) - $max_logs) . " נוספים\n";
        }
    }
    
    // Error log (first 5 items)
    if (!empty($error_log)) {
        $message .= "\nשגיאות:\n";
        foreach (array_slice($error_log, 0, $max_logs) as $log_entry) {
            // Sanitize log entry to prevent HTML issues
            $clean_entry = strip_tags($log_entry);
            $message .= $clean_entry . "\n";
        }
        if (count($error_log) > $max_logs) {
            $message .= "ועוד " . (count($error_log) - $max_logs) . " נוספות\n";
        }
    }
    
    $message .= "\nזמן ביצוע: {$duration} שניות\n";
    $message .= "זמן: " . current_time('Y-m-d H:i:s');
    
    // Check message length (Telegram limit is 4096 chars)
    if (strlen($message) > 4000) {
        // Truncate message if too long
        $message = substr($message, 0, 3900) . "\n\n[הודעה קוצרה - יותר מדי תוכן]";
        error_log("SAP Creator: Telegram message truncated due to length: " . strlen($message) . " chars");
    }
    
    error_log("SAP Creator: Sending Telegram summary (length: " . strlen($message) . " chars)");
    return sap_creator_send_telegram_message($message);
}

/**
 * Send critical error notification to Telegram
 * Used for HTTP 403 errors and other critical failures
 *
 * @param string $error_type Type of error (e.g., "HTTP 403 Error")
 * @param string $error_message Detailed error message
 * @return bool|WP_Error
 */
function sap_creator_send_critical_error_notification($error_type, $error_message) {
    $message = "שגיאה קריטית ביצירת מוצרים \n\n";
    $message .= "סוג שגיאה: {$error_type}\n";
    $message .= "הודעה: {$error_message}\n";
    $message .= "זמן: " . current_time('Y-m-d H:i:s') . "\n";
    $message .= "שרת: " . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'לא ידוע');
    
    return sap_creator_send_telegram_message($message);
}

/**
 * Schedule weekly product creation cron job
 * Uses Action Scheduler like other workflows
 */
function sap_schedule_weekly_product_creation() {
    // Use Action Scheduler instead of wp-cron for better reliability
    if (function_exists('as_next_scheduled_action')) {
        // Check if already scheduled
        $next_scheduled = as_next_scheduled_action('sap_weekly_product_creation_action');
        
        if (!$next_scheduled) {
            // Schedule for every Sunday at 03:00
            $next_sunday = strtotime('next Sunday 03:00:00');
            as_schedule_recurring_action($next_sunday, WEEK_IN_SECONDS, 'sap_weekly_product_creation_action', [], 'sap-creator');
            error_log('SAP Creator: Scheduled weekly product creation for ' . date('Y-m-d H:i:s', $next_sunday));
        }
    } else {
        // Fallback to wp-cron if Action Scheduler not available
        if (!wp_next_scheduled('sap_weekly_product_creation_event')) {
            wp_schedule_event(strtotime('next Sunday 03:00:00'), 'weekly', 'sap_weekly_product_creation_event');
        }
    }
}
add_action('wp', 'sap_schedule_weekly_product_creation');

/**
 * Cron job handler for Action Scheduler
 */
function sap_run_weekly_product_creation_action() {
    error_log('SAP Creator: Starting weekly product creation job');
    
    // Send start notification
    $start_message = "תחילת יצירת מוצרים שבועית\n";
    $start_message .= "זמן: " . current_time('Y-m-d H:i:s') . "\n";
    $start_message .= "מצב: ריצה אוטומטית שבועית";
    sap_creator_send_telegram_message($start_message);
    
    // Use the same Action Scheduler method as manual execution for consistency
    if (function_exists('as_enqueue_async_action')) {
        // Queue the job via Action Scheduler
        as_enqueue_async_action('sap_create_products_async');
        error_log('SAP Creator: Weekly job queued via Action Scheduler');
        
        // Send queued notification
        $queued_message = "יצירת מוצרים שבועית נכנסה לתור\n";
        $queued_message .= "המשימה נכנסה לתור לעיבוד ברקע.\n";
        $queued_message .= "תקבל התראה נוספת בסיום.";
        sap_creator_send_telegram_message($queued_message);
    } else {
        // Fallback: Direct execution
        error_log('SAP Creator: Action Scheduler not available, running directly');
        
        $start_time = microtime(true);
        ob_start();
        $result = sap_create_products_from_api();
        $output = ob_get_clean();
        $duration = round(microtime(true) - $start_time, 2);
        
        // Send completion notification for direct execution
        $success = !empty($result) && strpos($result, 'שגיאה') === false;
        $status = $success ? "הצליח" : "נכשל";
        
        $end_message = "{$status} - יצירת מוצרים שבועית הושלמה\n\n";
        $end_message .= "זמן ביצוע: {$duration} שניות\n";
        $end_message .= "זמן: " . current_time('Y-m-d H:i:s') . "\n\n";
        
        if ($success) {
            $end_message .= "מוצרים נוצרו בהצלחה. בדוק במסך הניהול לפרטים.";
        } else {
            $end_message .= "ארעו שגיאות. תצוגה מקדימה:\n" . substr(strip_tags($result), 0, 200) . "...";
        }
        
        sap_creator_send_telegram_message($end_message);
        
        error_log('SAP Creator: Weekly job completed in ' . $duration . 's. Success: ' . ($success ? 'Yes' : 'No'));
    }
}
add_action('sap_weekly_product_creation_action', 'sap_run_weekly_product_creation_action');

/**
 * Cron job handler (fallback for wp-cron)
 */
function sap_run_weekly_product_creation() {
    $log_output = sap_create_products_from_api();
    error_log('SAP Creator: Weekly product creation: ' . strip_tags(substr($log_output, 0, 200)));
    return $log_output;
}
add_action('sap_weekly_product_creation_event', 'sap_run_weekly_product_creation');

/**
 * Add product creation action to background processor
 * Integrates with existing SAP_Background_Processor class
 */
add_filter('sap_background_processor_actions', function($actions) {
    $actions['product_creation'] = [
        'function' => 'sap_create_products_from_api',
        'description' => 'Create new products from SAP API'
    ];
    return $actions;
});

/**
 * Handle manual product creation via background processing
 * Enqueue product creation task for background execution
 *
 * @param int $user_id User ID who initiated the task
 * @return bool|WP_Error
 */
function sap_enqueue_product_creation_task($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    if (class_exists('SAP_Background_Processor')) {
        $processor = new SAP_Background_Processor();
        $task_data = [
            'action' => 'product_creation',
            'user_id' => $user_id,
            'timestamp' => time()
        ];
        
        $processor->push_to_queue($task_data);
        $processor->save()->dispatch();
        
        error_log("SAP Creator: Product creation task queued for user {$user_id}");
        return true;
    }
    
    return new WP_Error('no_processor', 'Background processor not available');
}

/**
 * PHASE 1: Send creation completion notification
 *
 * @param array $creation_stats Creation statistics
 * @param array $creation_log Success log entries
 * @param array $error_log Error log entries
 * @param float $duration Processing duration in seconds
 * @return bool|WP_Error True on success, WP_Error on failure
 */
if (!function_exists('sap_creator_send_creation_completion_notification')) {
function sap_creator_send_creation_completion_notification($creation_stats, $creation_log, $error_log, $duration) {
    global $sap_creator_patch_data_collection;
    
    $total_simple = $creation_stats['simple_created'] ?? 0;
    $total_variable = $creation_stats['variable_created'] ?? 0;
    $total_variations = $creation_stats['variations_created'] ?? 0;
    $total_products = $total_simple + $total_variable;
    $sap_items_pending = count($sap_creator_patch_data_collection ?? []);
    
    $message = "✅ שלב 1 הושלם: יצירת מוצרים\n\n";
    $message .= "📊 סיכום יצירה:\n";
    $message .= "• מוצרים שנוצרו: {$total_products}\n";
    $message .= "• מוצרים פשוטים: {$total_simple}\n";
    $message .= "• מוצרים משתנים: {$total_variable}\n";
    $message .= "• וריאציות: {$total_variations}\n";
    $message .= "• זמן עיבוד: {$duration} שניות\n\n";
    
    if (!empty($error_log)) {
        $message .= "⚠️ שגיאות: " . count($error_log) . "\n";
        // Show first 2 errors
        foreach (array_slice($error_log, 0, 2) as $error) {
            $message .= "• " . substr($error, 0, 60) . "...\n";
        }
        $message .= "\n";
    }
    
    $message .= "🔄 שלב הבא: עדכון {$sap_items_pending} פריטים ב-SAP\n";
    $message .= "זמן: " . current_time('H:i:s');
    
    return sap_creator_send_telegram_message($message);
}
}

/**
 * PHASE 2: Process SAP updates individually (1-2 at a time)
 *
 * @param string $token SAP auth token
 * @return array Results of SAP update process
 */
if (!function_exists('sap_creator_process_sap_updates_individually')) {
function sap_creator_process_sap_updates_individually($token) {
    global $sap_creator_patch_data_collection;
    
    $results = [
        'total_items' => 0,
        'successful_updates' => 0,
        'failed_updates' => 0,
        'errors' => [],
        'start_time' => microtime(true)
    ];
    
    if (empty($sap_creator_patch_data_collection)) {
        echo "<p style='color: orange;'>אין פריטים לעדכון ב-SAP.</p>";
        return $results;
    }
    
    $results['total_items'] = count($sap_creator_patch_data_collection);
    echo "<p>מעדכן {$results['total_items']} פריטים ב-SAP (1-2 בכל פעם)...</p>";
    
    // Process items 1-2 at a time for reliability
    $batch_size = 2;
    $batches = array_chunk($sap_creator_patch_data_collection, $batch_size);
    $total_batches = count($batches);
    
    foreach ($batches as $batch_index => $batch) {
        $batch_num = $batch_index + 1;
        echo "<p>מעבד קבוצה {$batch_num}/{$total_batches} (" . count($batch) . " פריטים)...</p>";
        flush();
        
        foreach ($batch as $patch_data) {
            $item_code = $patch_data['ItemCode'];
            $site_group_id = $patch_data['U_EM_SiteGroupID'];
            $site_item_id = $patch_data['U_EM_SiteItemID'];
            
            // Prepare update data for SAP
            $update_data = [
                'U_SiteGroupID' => (string)$site_group_id,
                'U_SiteItemID' => (string)$site_item_id
            ];
            
            // Use proper PATCH endpoint for individual item updates
            $endpoint = 'Items/' . urlencode($item_code);
            
            error_log("SAP Creator: PHASE 2 - Updating {$item_code} with GroupID={$site_group_id}, ItemID={$site_item_id}");
            
            $response = sap_api_patch($endpoint, $update_data, $token);
            
            if (is_wp_error($response)) {
                $results['failed_updates']++;
                $error_message = $response->get_error_message();
                $results['errors'][] = "{$item_code}: {$error_message}";
                
                echo "<span style='color: red;'>✗ {$item_code} - {$error_message}</span><br>";
                error_log("SAP Creator: PHASE 2 FAILED - {$item_code}: {$error_message}");
                
                // Try fallback method for critical errors
                if (strpos($error_message, '405') !== false) {
                    error_log("SAP Creator: Trying fallback method for {$item_code}");
                    $fallback_result = sap_update_item_ids_fallback($item_code, $site_group_id, $site_item_id, $token);
                    if (!is_wp_error($fallback_result)) {
                        $results['successful_updates']++;
                        $results['failed_updates']--;
                        echo "<span style='color: green;'>✓ {$item_code} - הצליח עם שיטה חלופית</span><br>";
                    }
                }
            } else {
                $results['successful_updates']++;
                echo "<span style='color: green;'>✓ {$item_code}</span><br>";
                error_log("SAP Creator: PHASE 2 SUCCESS - {$item_code}");
            }
        }
        
        // Brief pause between batches (0.5 seconds)
        if ($batch_num < $total_batches) {
            echo "<p style='color: #666; font-style: italic;'>המתנה קצרה לפני הקבוצה הבאה...</p>";
            flush();
            usleep(500000); // 0.5 second pause
        }
    }
    
    $results['end_time'] = microtime(true);
    $results['duration'] = round($results['end_time'] - $results['start_time'], 2);
    
    echo "<hr>";
    echo "<h4>📊 סיכום עדכון SAP:</h4>";
    echo "<p><strong>סה\"כ פריטים:</strong> {$results['total_items']}</p>";
    echo "<p><strong>עודכנו בהצלחה:</strong> <span style='color: green;'>{$results['successful_updates']}</span></p>";
    echo "<p><strong>נכשלו:</strong> <span style='color: red;'>{$results['failed_updates']}</span></p>";
    echo "<p><strong>זמן עיבוד:</strong> {$results['duration']} שניות</p>";
    
    return $results;
}
}

/**
 * PHASE 2: Send SAP update summary notification
 *
 * @param array $sap_results SAP update results
 * @param array $creation_stats Original creation statistics
 * @return bool|WP_Error True on success, WP_Error on failure
 */
if (!function_exists('sap_creator_send_sap_update_summary_notification')) {
function sap_creator_send_sap_update_summary_notification($sap_results, $creation_stats) {
    $total_simple = $creation_stats['simple_created'] ?? 0;
    $total_variable = $creation_stats['variable_created'] ?? 0;
    $total_variations = $creation_stats['variations_created'] ?? 0;
    $total_products = $total_simple + $total_variable;
    
    $success_rate = $sap_results['total_items'] > 0 ? 
        round(($sap_results['successful_updates'] / $sap_results['total_items']) * 100, 1) : 0;
    
    $status_icon = $sap_results['failed_updates'] === 0 ? "✅" : ($success_rate >= 80 ? "⚠️" : "❌");
    
    $message = "{$status_icon} שלב 2 הושלם: עדכון SAP\n\n";
    $message .= "📊 סיכום כולל:\n";
    $message .= "• מוצרים שנוצרו: {$total_products}\n";
    $message .= "• וריאציות: {$total_variations}\n\n";
    
    $message .= "🔄 עדכון SAP:\n";
    $message .= "• סה\"כ פריטים: {$sap_results['total_items']}\n";
    $message .= "• הצליחו: {$sap_results['successful_updates']}\n";
    $message .= "• נכשלו: {$sap_results['failed_updates']}\n";
    $message .= "• אחוז הצלחה: {$success_rate}%\n";
    $message .= "• זמן עדכון: {$sap_results['duration']} שניות\n\n";
    
    if (!empty($sap_results['errors'])) {
        $message .= "❌ שגיאות עדכון SAP:\n";
        // Show first 3 errors
        foreach (array_slice($sap_results['errors'], 0, 3) as $error) {
            $message .= "• " . substr($error, 0, 50) . "...\n";
        }
        if (count($sap_results['errors']) > 3) {
            $message .= "• ועוד " . (count($sap_results['errors']) - 3) . " שגיאות...\n";
        }
        $message .= "\n";
    }
    
    $message .= "🕐 זמן סיום: " . current_time('H:i:s');
    
    return sap_creator_send_telegram_message($message);
}
}

/**
 * Output collected SAP PATCH data in tab-separated format (like creation_old.php)
 * 
 * @param array $creation_stats Creation statistics
 * @return void
 */
if (!function_exists('sap_creator_output_collected_patch_data')) {
function sap_creator_output_collected_patch_data($creation_stats = []) {
    global $sap_creator_patch_data_collection;
    
    echo "<hr style='margin: 20px 0;'>";
    echo "<h3>📊 סיכום יצירת מוצרים ונתונים לעדכון SAP</h3>";
    
    // Product creation summary
    $total_simple = $creation_stats['simple_created'] ?? 0;
    $total_variable = $creation_stats['variable_created'] ?? 0;
    $total_variations = $creation_stats['variations_created'] ?? 0;
    $total_products = $total_simple + $total_variable;
    $total_items = count($sap_creator_patch_data_collection ?? []);
    
    echo "<div style='background: #e7f3ff; padding: 15px; border: 1px solid #b3d9ff; margin: 10px 0;'>";
    echo "<h4>📈 סטטיסטיקות יצירה:</h4>";
    echo "<p><strong>סה\"כ מוצרים שנוצרו:</strong> {$total_products}</p>";
    echo "<p><strong>מוצרים פשוטים:</strong> {$total_simple}</p>";
    echo "<p><strong>מוצרים משתנים:</strong> {$total_variable}</p>";
    echo "<p><strong>וריאציות שנוצרו:</strong> {$total_variations}</p>";
    echo "<p><strong>פריטים לעדכון ב-SAP:</strong> {$total_items}</p>";
    echo "</div>";
    
    if (empty($sap_creator_patch_data_collection)) {
        echo "<p style='color: orange;'>⚠️ לא נאספו נתונים לעדכון SAP - ייתכן שכל הפריטים כבר היו מעודכנים.</p>";
        return;
    }
    
    echo "<h4>📋 נתונים שנאספו לעדכון SAP:</h4>";
    echo "<p>הנתונים הבאים נאספו ומוכנים לעדכון ב-SAP:</p>";
    
    // Output in tab-separated format for easy copying
    echo "<div style='background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; margin: 10px 0;'>";
    echo "<h5>📄 פורמט טבלה (להעתקה):</h5>";
    echo "<pre style='background: #ffffff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; font-family: monospace;'>";
    echo "ItemCode\tU_EM_SiteItemID\tU_EM_SiteGroupID\n";
    
    foreach ($sap_creator_patch_data_collection as $patch_data) {
        echo $patch_data['ItemCode'] . "\t" . 
             $patch_data['U_EM_SiteItemID'] . "\t" . 
             $patch_data['U_EM_SiteGroupID'] . "\n";
    }
    
    echo "</pre>";
    echo "</div>";
    
    // Show first few entries as preview
    echo "<h5>🔍 תצוגה מקדימה (5 פריטים ראשונים):</h5>";
    echo "<table style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
    echo "<tr style='background: #f1f3f4;'>";
    echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: right;'>ItemCode</th>";
    echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: right;'>SiteItemID</th>";
    echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: right;'>SiteGroupID</th>";
    echo "</tr>";
    
    $preview_items = array_slice($sap_creator_patch_data_collection, 0, 5);
    foreach ($preview_items as $patch_data) {
        echo "<tr>";
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; font-family: monospace;'>" . esc_html($patch_data['ItemCode']) . "</td>";
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; font-family: monospace;'>" . esc_html($patch_data['U_EM_SiteItemID']) . "</td>";
        echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: right; font-family: monospace;'>" . esc_html($patch_data['U_EM_SiteGroupID']) . "</td>";
        echo "</tr>";
    }
    
    if (count($sap_creator_patch_data_collection) > 5) {
        echo "<tr><td colspan='3' style='border: 1px solid #ddd; padding: 8px; text-align: center; font-style: italic;'>... ועוד " . (count($sap_creator_patch_data_collection) - 5) . " פריטים</td></tr>";
    }
    
    echo "</table>";
    
    // Performance summary
    error_log("SAP Creator: Performance Summary - Created {$total_products} products ({$total_simple} simple, {$total_variable} variable), {$total_variations} variations, collected {$total_items} items for SAP update");
}
}


