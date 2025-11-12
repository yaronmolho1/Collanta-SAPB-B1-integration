# SAP B1 Integration Plugin

WordPress plugin for integrating with SAP Business One system. This plugin provides synchronization between WooCommerce and SAP B1 for products, stock, and orders.

## Core Workflows

This plugin implements 3 main workflows:

### 1. Product Creation (`includes/sap-product-create.php`)
- **Function**: `sap_create_products_from_api()`
- **Purpose**: Creates new WooCommerce products from SAP items
- **Logic**: 
  - Pulls all items from SAP API
  - Processes only items where `U_SiteGroupID` OR `U_SiteItemID` is null
  - Groups items by SWW value
  - Creates simple products (single item) or variable products (multiple items)
  - Updates SAP with WooCommerce product IDs
- **Execution**: Manual via admin interface or weekly via cron (Sundays 03:00)
- **Notifications**: Telegram notifications for start/completion

### 2. Stock Updates (`includes/sap-products-import.php`)  
- **Function**: `sap_update_variations_from_api()`
- **Purpose**: Updates stock quantities for existing WooCommerce products
- **Logic**:
  - Pulls stock data from SAP API
  - Updates products based on SKU matching
  - Applies safety buffer (SAP stock - 10)
  - Supports single item or bulk update
- **Execution**: Manual via admin interface or daily via cron (02:00)
- **Notifications**: Telegram notifications for completion

### 3. Order Integration (`includes/class-sap-order-integration.php`)
- **Function**: `sap_handle_order_integration()`
- **Purpose**: Sends WooCommerce orders to SAP B1
- **Logic**:
  - Validates order status (processing only) and payment completion
  - Creates/finds SAP customer
  - Sends unified OrderFlow (Order + Invoice + Payment)
  - Updates order status to "received"
- **Execution**: Automatic on order status change or payment completion
- **Processing**: Background via Action Scheduler (with synchronous fallback)

## File Structure

### Core Files
- `sap_importer_daily.php` - Main plugin file with admin interface
- `includes/sap-product-create.php` - Product creation workflow
- `includes/sap-products-import.php` - Stock update workflow  
- `includes/class-sap-order-integration.php` - Order integration workflow

### Helper Files
- `includes/class-sap-background-processor.php` - Background job processing
- `includes/class-sap-sync-logger.php` - Order sync logging and tracking
- `includes/class-sap-action-scheduler-maintenance.php` - Action Scheduler cleanup
- `includes/creation_old.php` - Legacy functions (kept for compatibility)

### Configuration Files
- `category_analysis_queries.sql` - Database queries for analysis
- `fields_orderAPIi.json` - SAP API field mappings
- `order_example.json` - Sample order structure
- `order-log-table-export.sql` - Database schema export

## Admin Interface

The plugin provides a clean admin interface under **יבוא SAP** menu with:

1. **Stock Update Section** - Manual stock synchronization
2. **Product Creation Section** - Manual product creation  
3. **Bulk Order Send** - Send multiple orders to SAP
4. **Manual Order Send** - Send individual order to SAP
5. **Cron Status** - Display scheduled task status

## Configuration

### SAP API Settings
```php
define('SAP_API_BASE', 'https://cilapi.emuse.co.il:444/api');
define('SAP_API_USERNAME', 'Respect');
define('SAP_API_PASSWORD', 'Res@135!');
```

### Telegram Notifications
- Product Creation: `8309945060:AAHKHfGtTf6D_U_JnapGrTHxOLcuht9ULA4`
- Stock Updates: `8309945060:AAHKHfGtTf6D_U_JnapGrTHxOLcuht9ULA4`
- Chat ID: `5418067438`

## Cron Jobs

### Automatic Scheduling
- **Daily Stock Update**: 02:00 AM daily
- **Weekly Product Creation**: 03:00 AM every Sunday

### Background Processing
- Uses Action Scheduler for non-blocking execution
- Automatic fallback to synchronous processing if Action Scheduler unavailable
- Emergency instant mode available for troubleshooting

## Order Processing Rules

### Validation Requirements
1. Order status must be "processing"
2. Must have valid Yaad payment data (`yaad_credit_card_payment`)
3. Payment CCode must be '0' (success)
4. Payment ACode must exist (approval code)

### Processing Flow
1. Validate order and payment
2. Search/create SAP customer by email/phone
3. Build OrderFlow payload (Order + Invoice + Payment)
4. Send to SAP via extended timeout API call
5. Update order status to "received"
6. Log sync results

## Product Creation Rules

### Simple Products
- Created when SWW group contains single item
- Name = SWW value
- SKU = ItemCode
- Status = pending (not published)

### Variable Products  
- Created when SWW group contains multiple items
- Parent name = SWW value
- Variations created for each ItemCode
- Attributes: Size (U_ssize), Color (U_scolor), Danier (U_sdanier)

### Pricing
- SAP price × 1.18 (includes VAT)
- Uses PriceList 1 with fallback to other price lists
- Final price calculation: `floor(price * 1.18) . '.9'`

### Stock Management
- Source: ItemWarehouseInfoCollection.InStock
- Rule: If SAP stock ≤ 0, set WooCommerce stock = 0
- Safety buffer applied in stock updates (SAP stock - 10)

## Removed Features

During codebase cleanup, the following features were removed as they were not part of the core workflows:

- JSON testing interface for SAP orders
- Import history display functionality  
- Source codes synchronization from BusinessPartnerGroups
- Category assignment workflow
- Product renaming functionality
- Related admin UI sections and background processing code

## Dependencies

- **WordPress**: 5.0+
- **WooCommerce**: 3.0+ 
- **PHP**: 7.4+
- **Action Scheduler**: For background processing
- **cURL**: For SAP API streaming requests

## Error Handling

- Comprehensive error logging via `error_log()`
- Telegram notifications for critical failures
- Graceful degradation when services unavailable
- Retry logic for transient failures (orders only)
- Detailed sync status tracking in database

## Security Notes

- Direct file access prevention via `ABSPATH` checks
- Nonce verification for all admin actions  
- User capability verification (`manage_options`)
- Input sanitization for all user data
- SSL verification disabled in development (should be enabled in production)
