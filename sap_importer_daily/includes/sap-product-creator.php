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

// Telegram notification configuration for product creation
define('SAP_CREATOR_TELEGRAM_BOT_TOKEN', '8456245551:AAFv07KtOAA4OFTp1y1oGru8Q2egh9CWEJo');
define('SAP_CREATOR_TELEGRAM_CHAT_ID', '5418067438');

/**
 * Main function to create new products from SAP API
 * Only processes items where U_SiteGroupID OR U_SiteItemID is null (not yet in WooCommerce)
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

        // 2. Retrieve items where U_SiteGroupID OR U_SiteItemID is null
        // CRITICAL ISSUE: This filter only gets items with NULL IDs
        // If there are other items with the SAME SWW that already have IDs assigned,
        // they won't be included in this response, which could cause:
        // - Incomplete variable product creation (missing variations)
        // - Creating duplicate parents when variations already exist
        // TODO: Consider doing a secondary check per SWW group to verify all items are included
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
                ],
                [
                    "field" => "U_SiteGroupID",
                    "fieldType" => "string",
                    "operator" => "=",
                    "fieldValue" => null
                ],
                [
                    "logicalOperator" => "OR"
                ],
                [
                    "field" => "U_SiteItemID",
                    "fieldType" => "string",
                    "operator" => "=",
                    "fieldValue" => null
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

        echo "<p>⏳ שולח בקשה ל-SAP API לשליפת פריטים חדשים...</p>";
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
            echo "<p style='color: orange;'>לא נמצאו פריטים חדשים ליצירה ב-SAP.</p>";
            
            $empty_message = "✓ SAP Product Creation - No New Items\n";
            $empty_message .= "Result: No items found with NULL U_SiteGroupID or U_SiteItemID\n";
            $empty_message .= "Time: " . current_time('Y-m-d H:i:s');
            sap_creator_send_telegram_message($empty_message);
            
            return ob_get_clean();
        }

        echo "<p>נמצאו " . count($items) . " פריטים חדשים ב-SAP API.</p>";
        echo "<h3>פרטי יצירה:</h3>";

        // 4. Group items by SWW
        $sww_groups = [];
        foreach ($items as $item) {
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

        // 5. Process each SWW group
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
    
    // Create variations
    $variations_created = 0;
    $sap_update_failed = 0;
    
    foreach ($items as $item) {
        $variation_result = sap_create_variation($item, $parent_id, $token);
        
        if (is_wp_error($variation_result)) {
            error_log("SAP Creator: Failed to create variation for {$item['ItemCode']}: " . $variation_result->get_error_message());
            $sap_update_failed++;
        } else {
            $variations_created++;
            
            if (!$variation_result['sap_updated']) {
                $sap_update_failed++;
            }
        }
    }
    
    return [
        'parent_id' => $parent_id,
        'variations_count' => $variations_created,
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
    
    if (is_numeric($sap_quantity_on_hand) && $sap_quantity_on_hand >= 0) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity($sap_quantity_on_hand);
        $product->set_stock_status($sap_quantity_on_hand > 0 ? 'instock' : 'outofstock');
        
        return true;
    }
    
    $product->set_stock_status('outofstock');
    $product->set_manage_stock(false);
    
    return new WP_Error('invalid_stock', 'כמות מלאי לא תקינה');
}

/**
 * Set product attributes (non-variation) - danier only
 * Attributes MUST already exist (ID 3=color, 4=size, 5=danier)
 *
 * @param WC_Product $product Product object
 * @param array $item SAP item data
 * @param bool $for_variations Whether these are for variations
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function sap_set_product_attributes($product, $item, $for_variations = false) {
    $attributes_array = [];
    $errors = [];
    
    // Danier attribute (NOT for variations, NOT visible)
    if (!empty($item['U_sdanier'])) {
        if (!taxonomy_exists('pa_danier')) {
            $errors[] = 'Attribute pa_danier does not exist (ID 5 expected)';
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
    
    if (!empty($errors)) {
        return new WP_Error('attribute_errors', implode('; ', $errors));
    }
    
    return true;
}

/**
 * Create variation attributes for parent variable product
 * Only creates size and color attributes (for variations)
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
    if (!empty($size_values)) {
        if (!taxonomy_exists('pa_size')) {
            error_log('SAP Creator: pa_size attribute missing (ID 4 expected) - skipping');
        } else {
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
        }
    }
    
    // Color attribute (ID 3)
    if (!empty($color_values)) {
        if (!taxonomy_exists('pa_color')) {
            error_log('SAP Creator: pa_color attribute missing (ID 3 expected) - skipping');
        } else {
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
        }
    }
    
    return $attributes_array;
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
 * Update SAP item with WooCommerce IDs
 * TODO: Recheck endpoint - currently using Items/update, verify correct endpoint and payload structure
 *
 * @param string $item_code SAP ItemCode
 * @param int $site_group_id WooCommerce parent/product ID
 * @param int $site_item_id WooCommerce variation/product ID
 * @param string $token SAP auth token
 * @return bool True on success, false on failure
 */
function sap_update_item_ids($item_code, $site_group_id, $site_item_id, $token) {
    $update_data = [
        'ItemCode' => $item_code,
        'U_SiteGroupID' => (string)$site_group_id,
        'U_SiteItemID' => (string)$site_item_id
    ];
    
    $response = sap_api_post('Items/update', $update_data, $token);
    
    if (is_wp_error($response)) {
        error_log("SAP Creator: Failed to update SAP for {$item_code}: " . $response->get_error_message());
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
 * Schedule weekly product creation cron job
 */
function sap_schedule_weekly_product_creation() {
    if (!wp_next_scheduled('sap_weekly_product_creation_event')) {
        // Schedule for every Sunday at 03:00
        wp_schedule_event(strtotime('next Sunday 03:00:00'), 'weekly', 'sap_weekly_product_creation_event');
    }
}
add_action('wp', 'sap_schedule_weekly_product_creation');

/**
 * Cron job handler
 */
function sap_run_weekly_product_creation() {
    $log_output = sap_create_products_from_api();
    error_log('Weekly SAP Product Creation: ' . strip_tags($log_output));
    return $log_output;
}
add_action('sap_weekly_product_creation_event', 'sap_run_weekly_product_creation');


