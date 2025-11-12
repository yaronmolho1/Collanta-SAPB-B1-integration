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
define('SAP_CREATOR_TELEGRAM_BOT_TOKEN', '8456245551:AAFv07KtOAA4OFTp1y1oGru8Q2egh9CWEJo');
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
        $start_message = "✓ SAP Product Creation Started\n";
        $start_message .= "Time: " . current_time('Y-m-d H:i:s');
        sap_creator_send_telegram_message($start_message);

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
            
            $error_message = "✗ SAP Product Creation Failed\n";
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
            
            $empty_message = "✓ SAP Product Creation - No Items Found\n";
            $empty_message .= "Result: No items found in SAP API\n";
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
            
            $empty_message = "✓ SAP Product Creation - All Items Already Imported\n";
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
        foreach ($sww_groups as $sww => $group_items) {
            echo "<li><strong>SWW: " . esc_html($sww) . "</strong> (" . count($group_items) . " פריטים)<br>";
            
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
                    $creation_log[] = "✓ מוצר פשוט - SKU: {$group_items[0]['ItemCode']}";
                    
                    if (!$result['sap_updated']) {
                        $creation_stats['sap_update_failed']++;
                        $error_log[] = "✗ עדכון SAP נכשל - SKU: {$group_items[0]['ItemCode']}";
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
                    $creation_log[] = "✓ מוצר משתנה - SWW: {$sww} ({$result['variations_count']} וריאציות)";
                    
                    if ($result['sap_update_failed'] > 0) {
                        $creation_stats['sap_update_failed'] += $result['sap_update_failed'];
                        $error_log[] = "✗ עדכון SAP נכשל ל-{$result['sap_update_failed']} פריטים ב-SWW: {$sww}";
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

        // Send Telegram summary
        $telegram_result = sap_creator_send_summary_notification($creation_stats, $creation_log, $error_log, $duration);
        if (is_wp_error($telegram_result)) {
            echo "<p style='color: orange;'>אזהרה: שליחת התראת טלגרם נכשלה: " . $telegram_result->get_error_message() . "</p>";
        } else {
            echo "<p style='color: green;'>התראת טלגרם נשלחה בהצלחה.</p>";
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
 * Enhanced with batch variation creation like the blueprint
 *
 * @param array $items Array of SAP item data (all same SWW)
 * @param string $sww SWW value for parent product name
 * @param string $token SAP auth token
 * @return array|WP_Error Product data on success, WP_Error on failure
 */
function sap_create_variable_product($items, $sww, $token) {
    if (empty($items) || empty($sww)) {
        return new WP_Error('invalid_data', 'פריטים או SWW חסרים');
    }
    
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
    
    // Create new parent if needed
    if (!$parent_id) {
        $parent_product = new WC_Product_Variable();
        $parent_product->set_name($sww); // Name = SWW value
        $parent_product->set_status('pending'); // NOT published
        
        // Set parent attributes (for variation use)
        $parent_attributes = sap_create_variation_attributes($items);
        if (!empty($parent_attributes)) {
            $parent_product->set_attributes($parent_attributes);
        }
        
        $parent_id = $parent_product->save();
        
        if (!$parent_id) {
            return new WP_Error('parent_save_failed', 'נכשל בשמירת מוצר אב');
        }
        
        error_log("SAP Creator: Created new variable parent {$parent_id} for SWW {$sww}");
    }
    
    // Separate items into those that need creation vs update
    $items_to_create = [];
    $items_to_update = [];
    
    foreach ($items as $item) {
        if (empty($item['U_SiteItemID'])) {
            $items_to_create[] = $item;
        } else {
            $items_to_update[] = $item;
        }
    }
    
    $variations_created = 0;
    $variations_updated = 0;
    $sap_update_failed = 0;
    
    // Batch create new variations (like blueprint pattern)
    if (!empty($items_to_create)) {
        $create_result = sap_batch_create_variations($parent_id, $items_to_create, $token);
        if (is_wp_error($create_result)) {
            error_log("SAP Creator: Batch variation creation failed: " . $create_result->get_error_message());
            $sap_update_failed += count($items_to_create);
        } else {
            $variations_created = $create_result['created_count'];
            $sap_update_failed += $create_result['sap_update_failed'];
        }
    }
    
    // Batch update existing variations (like blueprint pattern)
    if (!empty($items_to_update)) {
        $update_result = sap_batch_update_variations($parent_id, $items_to_update, $token);
        if (is_wp_error($update_result)) {
            error_log("SAP Creator: Batch variation update failed: " . $update_result->get_error_message());
        } else {
            $variations_updated = $update_result['updated_count'];
        }
    }
    
    return [
        'parent_id' => $parent_id,
        'variations_count' => $variations_created,
        'variations_updated' => $variations_updated,
        'sap_update_failed' => $sap_update_failed
    ];
}

/**
 * Create a product variation
 *
 * @param array $item SAP item data
 * @param int $parent_id Parent product ID
 * @param string $token SAP auth token
 * @return array|WP_Error Variation data on success, WP_Error on failure
 */
function sap_create_variation($item, $parent_id, $token) {
    $item_code = $item['ItemCode'] ?? '';
    $item_name = $item['ItemName'] ?? '';
    
    if (empty($item_code)) {
        return new WP_Error('invalid_data', 'ItemCode חסר');
    }
    
    $variation = new WC_Product_Variation();
    $variation->set_parent_id($parent_id);
    $variation->set_name($item_name); // Name from ItemName
    $variation->set_sku($item_code);
    $variation->set_status('pending'); // NOT published
    
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
    
    // Set variation attributes (only size and color)
    $variation_attributes = [];
    
    if (!empty($item['U_ssize'])) {
        $size_slug = sanitize_title($item['U_ssize']);
        sap_ensure_term_exists('pa_size', $item['U_ssize'], $size_slug);
        $variation_attributes['pa_size'] = $size_slug;
    }
    
    if (!empty($item['U_scolor'])) {
        $color_slug = sanitize_title($item['U_scolor']);
        sap_ensure_term_exists('pa_color', $item['U_scolor'], $color_slug);
        $variation_attributes['pa_color'] = $color_slug;
    }
    
    if (!empty($variation_attributes)) {
        $variation->set_attributes($variation_attributes);
    }
    
    // Save variation
    $variation_id = $variation->save();
    
    if (!$variation_id) {
        return new WP_Error('save_failed', 'נכשל בשמירת וריאציה');
    }
    
    // Update SAP with IDs
    // U_SiteGroupID = Parent ID, U_SiteItemID = Variation ID
    $sap_updated = sap_update_item_ids($item_code, $parent_id, $variation_id, $token);
    
    return [
        'variation_id' => $variation_id,
        'sap_updated' => $sap_updated
    ];
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
        $b2c_final_price = ceil($b2c_price_with_vat);
        
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
 * Rule: Skip missing attributes gracefully
 *
 * @param array $items Array of items in same SWW group
 * @return array Array of WC_Product_Attribute objects
 */
function sap_create_variation_attributes($items) {
    $attributes_array = [];
    $size_values = [];
    $color_values = [];
    
    // Collect all unique size and color values from items
    foreach ($items as $item) {
        if (!empty($item['U_ssize'])) {
            $size_slug = sanitize_title($item['U_ssize']);
            $size_values[$size_slug] = $item['U_ssize'];
        }
        if (!empty($item['U_scolor'])) {
            $color_slug = sanitize_title($item['U_scolor']);
            $color_values[$color_slug] = $item['U_scolor'];
        }
    }
    
    // Size attribute (ID 4)
    if (!empty($size_values) && taxonomy_exists('pa_size')) {
        // Ensure all terms exist
        foreach ($size_values as $slug => $value) {
            sap_ensure_term_exists('pa_size', $value, $slug);
        }
        
        $size_attribute = new WC_Product_Attribute();
        $size_attribute->set_id(4); // Attribute ID 4
        $size_attribute->set_name('pa_size');
        $size_attribute->set_options(array_keys($size_values));
        $size_attribute->set_position(0);
        $size_attribute->set_visible(true);
        $size_attribute->set_variation(true);
        
        $attributes_array['pa_size'] = $size_attribute;
    } elseif (!empty($size_values)) {
        error_log('SAP Creator: pa_size attribute missing (ID 4 expected) - skipping');
    }
    
    // Color attribute (ID 3)
    if (!empty($color_values) && taxonomy_exists('pa_color')) {
        // Ensure all terms exist
        foreach ($color_values as $slug => $value) {
            sap_ensure_term_exists('pa_color', $value, $slug);
        }
        
        $color_attribute = new WC_Product_Attribute();
        $color_attribute->set_id(3); // Attribute ID 3
        $color_attribute->set_name('pa_color');
        $color_attribute->set_options(array_keys($color_values));
        $color_attribute->set_position(1);
        $color_attribute->set_visible(true);
        $color_attribute->set_variation(true);
        
        $attributes_array['pa_color'] = $color_attribute;
    } elseif (!empty($color_values)) {
        error_log('SAP Creator: pa_color attribute missing (ID 3 expected) - skipping');
    }
    
    return $attributes_array;
}

/**
 * Batch create variations using WooCommerce API (like blueprint)
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
        
        // Set attributes - only if taxonomies exist (skip missing gracefully)
        if (!empty($item['U_ssize']) && taxonomy_exists('pa_size')) {
            $size_slug = sanitize_title($item['U_ssize']);
            sap_ensure_term_exists('pa_size', $item['U_ssize'], $size_slug);
            $variation_data['attributes'][] = [
                'id' => 4,
                'option' => $item['U_ssize']
            ];
        }
        
        if (!empty($item['U_scolor']) && taxonomy_exists('pa_color')) {
            $color_slug = sanitize_title($item['U_scolor']);
            sap_ensure_term_exists('pa_color', $item['U_scolor'], $color_slug);
            $variation_data['attributes'][] = [
                'id' => 3,
                'option' => $item['U_scolor']
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
 * Batch update existing variations using WooCommerce API (like blueprint)
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
 * SAP API PATCH request
 * Used for updating SAP items with WooCommerce IDs
 *
 * @param string $endpoint API endpoint
 * @param array $data Request data
 * @param string $token SAP auth token
 * @return array|WP_Error Response data or error
 */
function sap_api_patch($endpoint, $data = [], $token = null) {
    $url = trailingslashit(SAP_API_BASE) . ltrim($endpoint, '/');
    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    if ($token) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    $args = [
        'method'      => 'PATCH',
        'headers'     => $headers,
        'body'        => wp_json_encode($data),
        'timeout'     => 30,
        'data_format' => 'body',
        'sslverify'   => false,
    ];

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        error_log('SAP API Patch Error (' . $endpoint . '): ' . $response->get_error_message());
        return new WP_Error('api_error', 'SAP API Patch Error (' . $endpoint . '): ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $http_code = wp_remote_retrieve_response_code($response);
    
    if ($http_code !== 200 && $http_code !== 204) {
        error_log("SAP API HTTP Error {$http_code} for {$endpoint}: " . substr($body, 0, 500));
        return new WP_Error('sap_api_http_error', "HTTP {$http_code} for {$endpoint}");
    }

    // Parse response if there's a body (200), or return true for no content (204)
    if (empty($body)) {
        return ['success' => true];
    }
    
    return sap_parse_api_response($body, $endpoint);
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
    // Validate inputs
    if (empty($item_code) || empty($token)) {
        error_log("SAP Creator: Invalid parameters for SAP update - ItemCode: {$item_code}");
        return false;
    }
    
    $update_data = [
        'U_SiteGroupID' => (string)$site_group_id,
        'U_SiteItemID' => (string)$site_item_id
    ];
    
    // Use POST method instead of PATCH for better compatibility
    $endpoint = 'items'; // Use batch endpoint
    $batch_data = [
        [
            'itemCode' => $item_code,
            'U_SiteGroupID' => (string)$site_group_id,
            'U_SiteItemID' => (string)$site_item_id
        ]
    ];
    
    $response = sap_api_post($endpoint, $batch_data, $token);
    
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        
        // Handle specific error types
        if (strpos($error_message, '403') !== false) {
            error_log("SAP Creator: HTTP 403 (Forbidden) for {$item_code} - Check API permissions and token validity");
            
            // Send critical error notification
            sap_creator_send_critical_error_notification(
                "HTTP 403 Error",
                "Failed to update SAP item {$item_code} - Permission denied. Check API credentials and permissions."
            );
        } else {
            error_log("SAP Creator: Failed to update SAP for {$item_code}: {$error_message}");
        }
        
        return false;
    }
    
    error_log("SAP Creator: Updated SAP for {$item_code} - GroupID: {$site_group_id}, ItemID: {$site_item_id}");
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
    if (empty(SAP_CREATOR_TELEGRAM_BOT_TOKEN) || empty(SAP_CREATOR_TELEGRAM_CHAT_ID)) {
        return new WP_Error('telegram_config', 'Telegram configuration missing');
    }
    
    $url = "https://api.telegram.org/bot" . SAP_CREATOR_TELEGRAM_BOT_TOKEN . "/sendMessage";
    
    $data = [
        'chat_id' => SAP_CREATOR_TELEGRAM_CHAT_ID,
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
        error_log('SAP Creator Telegram failed: ' . $response->get_error_message());
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
    $total_success = $stats['simple_created'] + $stats['variable_created'];
    $total_failed = $stats['failed'];
    
    $status = ($total_failed === 0 && $stats['sap_update_failed'] === 0) ? "✓" : "✗";
    $message = $status . " יצירת מוצרים מ-SAP הסתיימה\n\n";
    
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
    
    // Success log (first 10 items)
    if (!empty($success_log)) {
        $message .= "\nהצלחות:\n";
        foreach (array_slice($success_log, 0, 10) as $log_entry) {
            $message .= $log_entry . "\n";
        }
        if (count($success_log) > 10) {
            $message .= "...ועוד " . (count($success_log) - 10) . "\n";
        }
    }
    
    // Error log (first 10 items)
    if (!empty($error_log)) {
        $message .= "\nשגיאות:\n";
        foreach (array_slice($error_log, 0, 10) as $log_entry) {
            $message .= $log_entry . "\n";
        }
        if (count($error_log) > 10) {
            $message .= "...ועוד " . (count($error_log) - 10) . "\n";
        }
    }
    
    $message .= "\nזמן ביצוע: {$duration}s\n";
    $message .= "זמן: " . current_time('Y-m-d H:i:s');
    
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
    $message = "🚨 SAP Product Creator - Critical Error\n\n";
    $message .= "Error Type: {$error_type}\n";
    $message .= "Message: {$error_message}\n";
    $message .= "Time: " . current_time('Y-m-d H:i:s') . "\n";
    $message .= "Server: " . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'Unknown');
    
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
    $start_message = "⏰ Weekly SAP Product Creation Started\n";
    $start_message .= "Time: " . current_time('Y-m-d H:i:s') . "\n";
    $start_message .= "Mode: Automated Weekly Run";
    sap_creator_send_telegram_message($start_message);
    
    // Use the same Action Scheduler method as manual execution for consistency
    if (function_exists('as_enqueue_async_action')) {
        // Queue the job via Action Scheduler
        as_enqueue_async_action('sap_create_products_async');
        error_log('SAP Creator: Weekly job queued via Action Scheduler');
        
        // Send queued notification
        $queued_message = "📋 Weekly Product Creation Queued\n";
        $queued_message .= "The job has been queued for background processing.\n";
        $queued_message .= "You'll receive another notification when complete.";
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
        $status = $success ? "✅ SUCCESS" : "❌ FAILED";
        
        $end_message = "{$status} Weekly SAP Product Creation Completed\n\n";
        $end_message .= "Duration: {$duration}s\n";
        $end_message .= "Time: " . current_time('Y-m-d H:i:s') . "\n\n";
        
        if ($success) {
            $end_message .= "Products were created successfully. Check admin panel for details.";
        } else {
            $end_message .= "Errors occurred. Output preview:\n" . substr(strip_tags($result), 0, 200) . "...";
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


