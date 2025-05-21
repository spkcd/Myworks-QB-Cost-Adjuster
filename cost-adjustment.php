<?php
/**
 * Cost adjustment logic for MyWorks QuickBooks Cost Adjuster
 *
 * @package MyWorks_QB_Cost_Adjuster
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cost adjustment class
 */
class MWQBCA_Cost_Adjustment {
    
    /**
     * Settings instance
     */
    private $settings;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = new MWQBCA_Settings();
        
        // Initialize logger
        require_once MWQBCA_PLUGIN_PATH . 'includes/class-mwqbca-logger.php';
        $this->logger = new MWQBCA_Logger();
        
        // Hook into MyWorks Sync filter with much higher priority (999) to ensure our modifications run last
        add_filter('myworks_sync_product_data_before_sync', array($this, 'modify_product_data_before_sync'), 999, 2);
        
        // Add hook for variations with much higher priority
        add_filter('myworks_wc_qbo_sync_variation_data_before_sync', array($this, 'qb_cost_adjuster'), 999, 2);
        
        // Add hook for bulk sync with much higher priority
        add_filter('myworks_wc_qbo_sync_product_push_data', array($this, 'process_bulk_product_sync'), 999, 2);
        
        // Hook into QuickBooks API request/response
        add_action('myworks_wc_qbo_sync_after_product_push', array($this, 'log_quickbooks_api_transaction'), 999, 3);
        
        // Hook for adding QuickBooks API logging support
        add_filter('myworks_wc_qbo_sync_log_data', array($this, 'hook_quickbooks_api_log'), 999, 3);
    }
    
    /**
     * Get the multiplier value
     * 
     * @return float The multiplier value
     */
    public function get_multiplier() {
        // First try to get the specified option name
        $multiplier = get_option('qb_cost_multiplier', false);
        
        // If not set or invalid, try the settings class method
        if ($multiplier === false || !is_numeric($multiplier) || floatval($multiplier) <= 0) {
            // Fall back to plugin settings
            if (method_exists($this->settings, 'get_multiplier')) {
                $multiplier = $this->settings->get_multiplier();
            } else {
                // Default value if all else fails
                $multiplier = 1.65;
            }
        } else {
            $multiplier = floatval($multiplier);
        }
        
        return $multiplier;
    }
    
    /**
     * Calculate cost based on regular price
     * 
     * @param float $regular_price The product's regular price
     * @param float $multiplier The multiplier value
     * @return float The calculated cost
     */
    private function calculate_cost_from_price($regular_price, $multiplier) {
        if (empty($regular_price) || !is_numeric($regular_price) || floatval($regular_price) <= 0) {
            return false;
        }
        
        // Calculate cost as Regular Price / Multiplier
        $cost = floatval($regular_price) / $multiplier;
        
        // Round to 2 decimal places
        return round($cost, 2);
    }
    
    /**
     * Log product data before it's sent to QuickBooks
     * 
     * @param array $product_data The product data being pushed to QuickBooks
     * @param int $product_id The WooCommerce product ID
     */
    private function log_product_data_before_sync($product_data, $product_id) {
        // Identify cost-related fields for special highlighting
        $cost_fields = array('PurchaseCost', 'UnitPrice', 'qb_p_cost', '_wc_cog_cost', '_product_cost');
        
        // Create a formatted log message with cost fields highlighted
        $log_message = "PRODUCT DATA BEFORE SYNC (Product #{$product_id}):\n";
        
        // Add summary of cost fields at the top for quick reference
        $log_message .= "COST FIELDS SUMMARY:\n";
        foreach ($cost_fields as $field) {
            $value = isset($product_data[$field]) ? $product_data[$field] : 'not set';
            $log_message .= "- {$field}: {$value}\n";
        }
        
        // Add the full product data array
        $log_message .= "\nCOMPLETE PRODUCT DATA:\n" . print_r($product_data, true);
        
        // Log to both error_log and our custom logger
        error_log('QB Cost Adjuster - ' . $log_message);
        $this->logger->log($log_message, 'debug');
        
        // Also store in a dedicated option for easy viewing in admin
        $sync_data_logs = get_option('mwqbca_product_data_logs', array());
        
        // Limit to 50 logs to prevent option from growing too large
        if (count($sync_data_logs) >= 50) {
            // Remove oldest logs
            $sync_data_logs = array_slice($sync_data_logs, -49);
        }
        
        // Add new log
        $sync_data_logs[] = array(
            'timestamp' => current_time('mysql'),
            'product_id' => $product_id,
            'cost_fields' => array_intersect_key($product_data, array_flip(array_filter(array_keys($product_data), function($key) use ($cost_fields) {
                return in_array($key, $cost_fields);
            }))),
            'full_data' => $product_data
        );
        
        // Update option
        update_option('mwqbca_product_data_logs', $sync_data_logs);
    }
    
    /**
     * Modify product data before pushing to QuickBooks using the specific MyWorks filter
     * 
     * @param array $product_data The product data being pushed to QuickBooks
     * @param int $product_id The WooCommerce product ID
     * @return array Modified product data
     */
    public function modify_product_data_before_sync($product_data, $product_id) {
        // DEBUG: Log the start of function execution
        $debug_msg = "DEBUG: modify_product_data_before_sync called for product #$product_id";
        error_log($debug_msg);
        $this->logger->log($debug_msg, 'debug');
        
        // Log detailed information about the incoming product data
        $this->log_variation_data($product_data, 'BEFORE', $product_id, 'Simple Product');
        
        // Log execution start with hook priority
        error_log("QB Cost Adjuster - modify_product_data_before_sync() function executed for product #{$product_id} with hook priority 999");
        
        // DEBUG: Log the incoming product data
        error_log("DEBUG: Product data received: " . print_r($product_data, true));
        
        // Check if cost adjuster is enabled
        if (!$this->is_cost_adjuster_enabled()) {
            error_log('QB Cost Adjuster - Cost adjustment disabled. Skipping product #' . $product_id);
            $this->qb_cost_adj_log(
                'Unknown',
                $product_id,
                "Product",
                false,
                null,
                null,
                'info',
                "Cost adjustment disabled. Debug check.",
                'debug_check'
            );
            return $product_data;
        }
        
        error_log('QB Cost Adjuster - Cost adjustment enabled. Processing product #' . $product_id);
        
        // Get product data
        $product = wc_get_product($product_id);
        if (!$product) {
            $this->qb_cost_adj_log(
                'Unknown',
                $product_id,
                "Unknown Product",
                false,
                null,
                null,
                'error',
                "Product not found. Skipping cost adjustment.",
                'direct_sync'
            );
            return $product_data;
        }
        
        // Get product type for logging
        $product_type = $product->get_type();
        $product_type_label = ucfirst($product_type);
        
        // DEBUG: Log the product type
        error_log("DEBUG: Product #$product_id is of type: $product_type_label");
        
        // Check if this is a variable product
        if ($product->is_type('variable')) {
            $this->qb_cost_adj_log(
                $product_type_label,
                $product_id,
                $product->get_name(),
                false,
                null,
                null,
                'info',
                "Variable product detected. Processing all variations.",
                'direct_sync'
            );
            
            // Process variable product
            $modified_data = $this->handle_variable_product($product_data, $product);
            
            // Log after processing
            $this->log_variation_data($modified_data, 'AFTER (variable product processing)', $product_id, 'Variable Product');
            
            return $modified_data;
        }
        
        // Get the multiplier
        $multiplier = $this->get_multiplier();
        
        $product_name = $product->get_name();
        $regular_price = $product->get_regular_price();
        
        // DEBUG: Log the price and multiplier
        error_log("DEBUG: Product #$product_id regular price: $regular_price, multiplier: $multiplier");
        
        // Calculate cost from regular price
        $calculated_cost = $this->calculate_cost_from_price($regular_price, $multiplier);
        
        // Skip if we couldn't calculate a valid cost
        if ($calculated_cost === false) {
            $debug_cost_msg = "DEBUG: Could not calculate valid cost for product #$product_id. Regular price: $regular_price";
            error_log($debug_cost_msg);
            $this->logger->log($debug_cost_msg, 'error');
            
            $this->qb_cost_adj_log(
                $product_type_label,
                $product_id,
                $product_name,
                false,
                $regular_price,
                $multiplier,
                'error',
                "Could not calculate valid cost. Regular price invalid or missing.",
                'direct_sync'
            );
            return $product_data;
        }
        
        // DEBUG: Log the calculated cost
        $debug_success_msg = "DEBUG: Successfully calculated cost for product #$product_id. Cost: $calculated_cost";
        error_log($debug_success_msg);
        $this->logger->log($debug_success_msg, 'debug');
        
        // Log the successful calculation
        $this->qb_cost_adj_log(
            $product_type_label,
            $product_id,
            $product_name,
            $calculated_cost,
            $regular_price,
            $multiplier,
            'success',
            "Cost calculated successfully",
            'direct_sync'
        );
        
        // Update the product data with calculated cost
        if (isset($product_data['PurchaseCost'])) {
            $product_data['PurchaseCost'] = $calculated_cost;
        }
        
        // Set cost field for QB
        $product_data['UnitPrice'] = $calculated_cost;
        
        // Update other cost fields if they exist
        if (isset($product_data['qb_p_cost'])) {
            $product_data['qb_p_cost'] = $calculated_cost;
        } else {
            $product_data['qb_p_cost'] = $calculated_cost;
        }
        
        // Update _product_cost meta key which appears in logs
        $product_data['_product_cost'] = $calculated_cost;
        
        // Update WooCommerce Cost of Goods field if present
        if (isset($product_data['_wc_cog_cost'])) {
            $product_data['_wc_cog_cost'] = $calculated_cost;
        }
        
        // Create a detailed debug log entry
        $debug_log_data = array(
            'timestamp' => current_time('mysql'),
            'product_id' => $product_id,
            'product_name' => $product_name,
            'product_type' => $product_type,
            'regular_price' => $regular_price,
            'multiplier' => $multiplier,
            'calculated_cost' => $calculated_cost,
            'sync_status' => 'success'
        );
        
        // Store debug log
        $this->store_debug_log($debug_log_data);
        
        // Log the cost fields after modification
        error_log("QB Cost Adjuster - Cost fields updated for product #{$product_id}:");
        if (isset($product_data['PurchaseCost'])) {
            error_log("  - PurchaseCost: " . $product_data['PurchaseCost']);
        }
        if (isset($product_data['UnitPrice'])) {
            error_log("  - UnitPrice: " . $product_data['UnitPrice']);
        }
        if (isset($product_data['qb_p_cost'])) {
            error_log("  - qb_p_cost: " . $product_data['qb_p_cost']);
        }
        if (isset($product_data['_product_cost'])) {
            error_log("  - _product_cost: " . $product_data['_product_cost']);
        }
        if (isset($product_data['_wc_cog_cost'])) {
            error_log("  - _wc_cog_cost: " . $product_data['_wc_cog_cost']);
        }
        
        // Log the complete product data just before it's sent to QuickBooks
        $this->log_product_data_before_sync($product_data, $product_id);
        
        // Log detailed information about the outgoing product data
        $this->log_variation_data($product_data, 'AFTER', $product_id, 'Simple Product');
        
        return $product_data;
    }
    
    /**
     * Store debug log data
     * 
     * @param array $log_data Log data to store
     */
    private function store_debug_log($log_data) {
        // Get existing debug logs
        $debug_logs = get_option('mwqbca_debug_logs', array());
        
        // Limit to 200 logs to prevent option from growing too large
        if (count($debug_logs) >= 200) {
            // Remove oldest logs
            $debug_logs = array_slice($debug_logs, -199);
        }
        
        // Add new log
        $debug_logs[] = $log_data;
        
        // Update option
        update_option('mwqbca_debug_logs', $debug_logs);
    }
    
    /**
     * Handle cost adjustment for variable products
     * 
     * @param array $product_data The product data being pushed to QuickBooks
     * @param WC_Product_Variable $product The variable product
     * @return array Modified product data
     */
    private function handle_variable_product($product_data, $product) {
        // Get all variations
        $variations = $product->get_available_variations();
        $multiplier = $this->get_multiplier();
        $product_id = $product->get_id();
        $product_name = $product->get_name();
        
        $this->qb_cost_adj_log(
            'Variable',
            $product_id,
            $product_name,
            false,
            null,
            $multiplier,
            'info',
            "Processing variable product with {$multiplier} multiplier",
            'variable_sync'
        );
        
        // Check if we have variations
        if (empty($variations)) {
            $this->qb_cost_adj_log(
                'Variable',
                $product_id,
                $product_name,
                false,
                null,
                $multiplier,
                'warning',
                "No variations found. Processing as simple product.",
                'variable_sync'
            );
            return $this->modify_product_data_before_sync($product_data, $product_id);
        }
        
        // Process the parent product first (calculate average cost from all variations)
        $total_cost = 0;
        $valid_variations = 0;
        
        // Debug log for tracking variable product processing
        $this->qb_cost_adj_log(
            'Variable',
            $product_id,
            $product_name,
            false,
            null,
            $multiplier,
            'info',
            "Found " . count($variations) . " variations",
            'variable_sync'
        );
        
        // Process each variation
        foreach ($variations as $variation_data) {
            $variation_id = $variation_data['variation_id'];
            $variation = wc_get_product($variation_id);
            
            if (!$variation) {
                $this->qb_cost_adj_log(
                    'Variation',
                    $variation_id,
                    "Unknown Variation",
                    false,
                    null,
                    $multiplier,
                    'error',
                    "Could not load variation. Skipping.",
                    'variable_sync'
                );
                continue;
            }
            
            // Get regular price for this variation
            $regular_price = $variation->get_regular_price();
            
            // Calculate cost for this variation
            $calculated_cost = $this->calculate_cost_from_price($regular_price, $multiplier);
            
            // Create variation name for logging
            $variation_attributes = $variation->get_attributes();
            $attribute_text = '';
            foreach ($variation_attributes as $attr_name => $attr_value) {
                if (!empty($attr_value)) {
                    $attribute_text .= wc_attribute_label($attr_name) . ': ' . $attr_value . ', ';
                }
            }
            $attribute_text = rtrim($attribute_text, ', ');
            $var_name = $product_name . ' - ' . $attribute_text;
            
            if ($calculated_cost !== false) {
                // Add to total cost for averaging
                $total_cost += $calculated_cost;
                $valid_variations++;
                
                // Log each variation calculation
                $this->qb_cost_adj_log(
                    'Variation',
                    $variation_id,
                    $var_name,
                    $calculated_cost,
                    $regular_price,
                    $multiplier,
                    'success',
                    "Cost calculated for variation",
                    'variable_sync'
                );
            } else {
                $this->qb_cost_adj_log(
                    'Variation',
                    $variation_id,
                    $var_name,
                    false,
                    $regular_price,
                    $multiplier,
                    'error',
                    "Could not calculate valid cost. Regular price invalid or missing.",
                    'variable_sync'
                );
            }
        }
        
        // Calculate average cost for parent product
        $parent_cost = ($valid_variations > 0) ? round($total_cost / $valid_variations, 2) : false;
        
        // Use average cost for the parent product
        if ($parent_cost !== false) {
            $this->qb_cost_adj_log(
                'Variable',
                $product_id,
                $product_name,
                $parent_cost,
                null,
                $multiplier,
                'success',
                "Using average cost (based on {$valid_variations} variations)",
                'variable_sync'
            );
            
            // Update the product data with calculated cost
            if (isset($product_data['PurchaseCost'])) {
                $product_data['PurchaseCost'] = $parent_cost;
            }
            
            // Set cost field for QB
            $product_data['UnitPrice'] = $parent_cost;
            
            // Also update _product_cost meta key
            $product_data['_product_cost'] = $parent_cost;
        } else {
            $this->qb_cost_adj_log(
                'Variable',
                $product_id,
                $product_name,
                false,
                null,
                $multiplier,
                'error',
                "Could not calculate valid cost for any variations. Skipping cost adjustment.",
                'variable_sync'
            );
        }
        
        // Log the complete product data just before it's sent to QuickBooks
        $this->log_product_data_before_sync($product_data, $product_id);
        
        return $product_data;
    }
    
    /**
     * Modify product data before pushing to QuickBooks
     * 
     * @param array $product_data The product data being pushed to QuickBooks
     * @param int $product_id The WooCommerce product ID
     * @return array Modified product data
     */
    public function modify_product_data($product_data, $product_id) {
        // Check if cost adjuster is enabled
        if (!$this->is_cost_adjuster_enabled()) {
            return $product_data;
        }
        
        // Get the multiplier
        $multiplier = $this->get_multiplier();
        
        // Get regular price from product data or product object
        $regular_price = null;
        
        // Try to get from product data first
        if (isset($product_data['_regular_price']) && is_numeric($product_data['_regular_price'])) {
            $regular_price = floatval($product_data['_regular_price']);
        } else {
            // Try to get from product object
            $product = wc_get_product($product_id);
            if ($product) {
                $regular_price = $product->get_regular_price();
            }
        }
        
        // Calculate cost from regular price
        $calculated_cost = $this->calculate_cost_from_price($regular_price, $multiplier);
        
        // Skip if we couldn't calculate a valid cost
        if ($calculated_cost === false) {
            $this->logger->log("Could not calculate valid cost for product #{$product_id}. Skipping cost adjustment.");
            return $product_data;
        }
        
        // Get product name for logging
        $product_name = isset($product_data['name']) ? $product_data['name'] : "Product #{$product_id}";
        
        // Log the calculation
        $this->logger->log(sprintf(
            "[MULTIPLIER:%.2f] Calculated cost for %s: Regular Price $%.2f / Multiplier %.2f = Cost $%.2f",
            $multiplier,
            $product_name,
            floatval($regular_price),
            $multiplier,
            $calculated_cost
        ));
        
        // Update the product data with calculated cost
        $product_data = $this->update_product_cost($product_data, $calculated_cost);
        
        return $product_data;
    }
    
    /**
     * Modify inventory data before pushing to QuickBooks
     * 
     * @param array $inventory_data The inventory data being pushed to QuickBooks
     * @param int $product_id The WooCommerce product ID
     * @return array Modified inventory data
     */
    public function modify_inventory_data($inventory_data, $product_id) {
        // Only proceed if we're supposed to update inventory data
        if (!isset($inventory_data['PurchaseCost'])) {
            return $inventory_data;
        }
        
        // Check if cost adjuster is enabled
        if (!$this->is_cost_adjuster_enabled()) {
            return $inventory_data;
        }
        
        // Get the multiplier
        $multiplier = $this->get_multiplier();
        
        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            $this->logger->log("Product #{$product_id} not found for inventory adjustment. Skipping.");
            return $inventory_data;
        }
        
        // Get regular price
        $regular_price = $product->get_regular_price();
        
        // Calculate cost from regular price
        $calculated_cost = $this->calculate_cost_from_price($regular_price, $multiplier);
        
        // Skip if we couldn't calculate a valid cost
        if ($calculated_cost === false) {
            $this->logger->log("Could not calculate valid cost for inventory of product #{$product_id}. Skipping inventory cost adjustment.");
            return $inventory_data;
        }
        
        // Log the calculation
        $product_name = $product->get_name();
        $this->logger->log(sprintf(
            "[MULTIPLIER:%.2f] Calculated inventory cost for %s (#%d): Regular Price $%.2f / Multiplier %.2f = Cost $%.2f",
            $multiplier,
            $product_name, 
            $product_id,
            floatval($regular_price),
            $multiplier,
            $calculated_cost
        ));
        
        // Update inventory data with calculated cost
        $inventory_data['PurchaseCost'] = $calculated_cost;
        
        // Also update _product_cost meta key
        $inventory_data['_product_cost'] = $calculated_cost;
        
        return $inventory_data;
    }
    
    /**
     * Update product data with adjusted cost
     * 
     * @param array $product_data The product data
     * @param float $adjusted_cost The adjusted cost
     * @return array Updated product data
     */
    private function update_product_cost($product_data, $adjusted_cost) {
        // Update the appropriate cost fields
        
        // Update WooCommerce Cost of Goods field if present or add it
        if (isset($product_data['_wc_cog_cost'])) {
            $product_data['_wc_cog_cost'] = $adjusted_cost;
        } else {
            $product_data['_wc_cog_cost'] = $adjusted_cost;
        }
        
        // Update _product_cost meta key which appears in logs
        $product_data['_product_cost'] = $adjusted_cost;
        
        // Update QB specific cost field
        if (isset($product_data['qb_p_cost'])) {
            $product_data['qb_p_cost'] = $adjusted_cost;
        } else {
            $product_data['qb_p_cost'] = $adjusted_cost;
        }
        
        // Set purchase cost for QuickBooks
        $product_data['PurchaseCost'] = $adjusted_cost;
        
        // Log cost field updates if debugging is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'Cost Adjuster - Updated cost fields in product data: WC COG: %s, Product Cost: %s, QB Cost: %s, Purchase Cost: %s',
                $adjusted_cost,
                $adjusted_cost,
                $adjusted_cost,
                $adjusted_cost
            ));
        }
        
        return $product_data;
    }
    
    /**
     * Check if cost adjuster is enabled
     * 
     * @return bool True if enabled, false otherwise
     */
    private function is_cost_adjuster_enabled() {
        $enabled = false;
        
        // First try settings class method if available
        if (method_exists($this->settings, 'is_enabled')) {
            $enabled = $this->settings->is_enabled();
            error_log('QB Cost Adjuster - Checking enabled status via settings class: ' . ($enabled ? '1 (enabled)' : '0 (disabled)'));
        } else {
            // Otherwise, check option directly (using integer 1 for enabled)
            $raw_value = get_option('mwqbca_enabled', 0);
            $enabled = (int) $raw_value === 1;
            error_log('QB Cost Adjuster - Checking enabled status directly from option: ' . $raw_value . ' -> ' . ($enabled ? '1 (enabled)' : '0 (disabled)'));
        }
        
        return $enabled;
    }
    
    /**
     * Log variation data before and after processing for QBO sync
     * 
     * @param array $variation_data The variation data array
     * @param string $stage Whether this is "before" or "after" processing
     * @param int $variation_id The variation ID
     * @param string $product_type The type of product (variation/simple)
     */
    private function log_variation_data($variation_data, $stage, $variation_id, $product_type = 'Variation') {
        $log_message = "=== DETAILED {$stage} PROCESSING LOG FOR {$product_type} #{$variation_id} ===\n\n";
        
        // Add timestamp for analysis
        $log_message .= "Timestamp: " . current_time('mysql') . "\n\n";
        
        // Extract and highlight cost-related fields
        $cost_fields = array('PurchaseCost', 'UnitPrice', 'qb_p_cost', '_wc_cog_cost', '_product_cost');
        $log_message .= "COST FIELDS SUMMARY:\n";
        foreach ($cost_fields as $field) {
            $value = isset($variation_data[$field]) ? $variation_data[$field] : 'not set';
            $log_message .= "- {$field}: {$value}\n";
        }
        
        // Add full data array for complete debugging
        $log_message .= "\nCOMPLETE DATA ARRAY:\n" . print_r($variation_data, true);
        
        // Store in WordPress error log
        error_log("QB Cost Adjuster - {$log_message}");
        
        // Log to custom logger
        $this->logger->log($log_message, 'debug');
        
        // Store in database for admin viewing
        $sync_data_logs = get_option('mwqbca_detailed_variation_logs', array());
        
        // Limit stored logs to prevent option table bloat
        if (count($sync_data_logs) >= 50) {
            $sync_data_logs = array_slice($sync_data_logs, -49);
        }
        
        // Add new log entry
        $sync_data_logs[] = array(
            'timestamp' => current_time('mysql'),
            'variation_id' => $variation_id,
            'stage' => $stage,
            'product_type' => $product_type,
            'cost_fields' => array_intersect_key($variation_data, array_flip(array_filter(array_keys($variation_data), function($key) use ($cost_fields) {
                return in_array($key, $cost_fields);
            }))),
            'full_data' => $variation_data
        );
        
        update_option('mwqbca_detailed_variation_logs', $sync_data_logs);
    }
    
    /**
     * Log QuickBooks API requests and responses
     * 
     * @param int $product_id WooCommerce product ID
     * @param mixed $qbo_response QuickBooks API response
     * @param array $extra_data Extra data about the request
     */
    public function log_quickbooks_api_transaction($product_id, $qbo_response, $extra_data = array()) {
        // Only log if cost adjuster is enabled
        if (!$this->is_cost_adjuster_enabled()) {
            return;
        }
        
        // Get product info
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "Product #{$product_id}";
        
        // Parse response to determine success/failure
        $is_success = false;
        $error_message = '';
        
        if (is_object($qbo_response) && !empty($qbo_response)) {
            $is_success = true;
        } elseif (is_array($qbo_response) && !empty($qbo_response)) {
            $is_success = true;
        } else {
            $is_success = false;
            $error_message = 'Empty or invalid response from QuickBooks API';
        }
        
        // Determine request type
        $request_type = isset($extra_data['request_type']) ? $extra_data['request_type'] : 'Product Sync';
        
        // Extract request data if available
        $request_data = isset($extra_data['request_data']) ? $extra_data['request_data'] : '';
        
        // Format request and response for logging
        $formatted_request = '';
        if (!empty($request_data)) {
            if (is_array($request_data) || is_object($request_data)) {
                $formatted_request = print_r($request_data, true);
            } else {
                $formatted_request = $request_data;
            }
        }
        
        $formatted_response = '';
        if (!empty($qbo_response)) {
            if (is_array($qbo_response) || is_object($qbo_response)) {
                $formatted_response = print_r($qbo_response, true);
            } else {
                $formatted_response = $qbo_response;
            }
        }
        
        // Create log entry
        $log_entry = sprintf(
            "[%s] QuickBooks API %s for %s (#%d) - Status: %s\n\nRequest:\n%s\n\nResponse:\n%s\n\n%s",
            current_time('mysql'),
            $request_type,
            $product_name,
            $product_id,
            $is_success ? 'SUCCESS' : 'ERROR',
            $formatted_request,
            $formatted_response,
            $error_message ? "Error: {$error_message}" : ''
        );
        
        // Log to error_log
        error_log("QB Cost Adjuster - QuickBooks API Transaction - Product #{$product_id}");
        error_log("Status: " . ($is_success ? 'SUCCESS' : 'ERROR'));
        if (!empty($error_message)) {
            error_log("Error: {$error_message}");
        }
        
        // Log to our custom log file
        $this->logger->log($log_entry, $is_success ? 'info' : 'error');
        
        // Store API logs in database for reporting
        $this->store_api_log($product_id, $formatted_request, $formatted_response, $is_success, $error_message, $request_type);
    }
    
    /**
     * Store API logs in the database for reporting
     * 
     * @param int $product_id Product ID
     * @param string $request Request data
     * @param string $response Response data
     * @param bool $is_success Whether the request was successful
     * @param string $error_message Error message if any
     * @param string $request_type Type of request
     */
    private function store_api_log($product_id, $request, $response, $is_success, $error_message, $request_type) {
        // Get existing API logs
        $api_logs = get_option('mwqbca_api_logs', array());
        
        // Limit to 100 logs to prevent option from growing too large
        if (count($api_logs) >= 100) {
            // Remove oldest logs
            $api_logs = array_slice($api_logs, -99);
        }
        
        // Add new log
        $api_logs[] = array(
            'timestamp' => current_time('mysql'),
            'product_id' => $product_id,
            'request' => $request,
            'response' => $response,
            'status' => $is_success ? 'success' : 'error',
            'error_message' => $error_message,
            'type' => $request_type
        );
        
        // Update option
        update_option('mwqbca_api_logs', $api_logs);
    }
    
    /**
     * Hook into QuickBooks API logging
     * 
     * @param array $log_data Log data
     * @param string $operation_type Operation type
     * @param mixed $response QuickBooks API response
     * @return array Modified log data
     */
    public function hook_quickbooks_api_log($log_data, $operation_type, $response) {
        // Extract product ID if available
        $product_id = 0;
        if (isset($log_data['wc_entity_id'])) {
            $product_id = intval($log_data['wc_entity_id']);
        }
        
        // Only proceed if we have a product ID and this is a product operation
        if ($product_id > 0 && (strpos($operation_type, 'Product') !== false || strpos($operation_type, 'Item') !== false)) {
            // Extract request data if available
            $request_data = '';
            if (isset($log_data['request_data'])) {
                $request_data = $log_data['request_data'];
            }
            
            // Log the API transaction
            $extra_data = array(
                'request_type' => $operation_type,
                'request_data' => $request_data
            );
            
            $this->log_quickbooks_api_transaction($product_id, $response, $extra_data);
        }
        
        return $log_data;
    }
    
    /**
     * Adjust costs for variable products and their variations
     * 
     * @param array $variation_data The variation data being pushed to QuickBooks
     * @param int $variation_id The WooCommerce variation ID
     * @return array Modified variation data
     */
    public function qb_cost_adjuster($variation_data, $variation_id) {
        // Track sync status for error logging
        $sync_status = array(
            'success' => false,
            'error_code' => '',
            'error_message' => '',
            'variation_id' => $variation_id
        );
        
        try {
            // Log detailed information about the incoming variation data
            $this->log_variation_data($variation_data, 'BEFORE', $variation_id, 'Variation');
            
            // Log execution start
            error_log("QB Cost Adjuster - qb_cost_adjuster() function executed for variation #{$variation_id} with hook priority 999");
            $this->logger->log("Function execution started for variation #{$variation_id}", 'info');
            
            // Check if cost adjuster is enabled
            if (!$this->is_cost_adjuster_enabled()) {
                $sync_status['error_code'] = 'disabled';
                $sync_status['error_message'] = 'Cost adjustment feature is disabled';
                
                $this->qb_cost_adj_log(
                    'Unknown',
                    $variation_id,
                    "Unknown Variation",
                    false,
                    null,
                    null,
                    'info',
                    "Cost adjustment disabled. Skipping.",
                    'variation_sync'
                );
                
                // Log that we're skipping due to disabled feature
                error_log("QB Cost Adjuster - Cost adjustment disabled for variation #{$variation_id}");
                return $variation_data;
            }
            
            // Get the variation object
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                $sync_status['error_code'] = 'not_found';
                $sync_status['error_message'] = "Variation #{$variation_id} not found";
                
                $this->qb_cost_adj_log(
                    'Unknown',
                    $variation_id,
                    "Unknown Variation",
                    false,
                    null,
                    null,
                    'error',
                    "Variation not found. Skipping cost adjustment.",
                    'variation_sync'
                );
                
                $this->log_variation_sync_error($variation_id, $sync_status['error_message']);
                return $variation_data;
            }
            
            // Check if this is actually a variation
            if (!$variation->is_type('variation')) {
                $sync_status['error_code'] = 'not_variation';
                $sync_status['error_message'] = "Product is not a variation (type: {$variation->get_type()})";
                
                $this->qb_cost_adj_log(
                    $variation->get_type(),
                    $variation_id,
                    $variation->get_name(),
                    false,
                    null,
                    null,
                    'warning',
                    "Product is not a variation. Using standard product handler.",
                    'variation_sync'
                );
                
                // Log type information
                error_log("QB Cost Adjuster - Product #{$variation_id} is not a variation but type: {$variation->get_type()}");
                
                // Process as a standard product
                $modified_data = $this->modify_product_data_before_sync($variation_data, $variation_id);
                
                // Log after processing
                $this->log_variation_data($modified_data, 'AFTER (via standard product handler)', $variation_id, $variation->get_type());
                
                return $modified_data;
            }
            
            // Get parent product
            $parent_id = $variation->get_parent_id();
            $parent_product = wc_get_product($parent_id);
            
            if (!$parent_product) {
                $sync_status['error_code'] = 'parent_not_found';
                $sync_status['error_message'] = "Parent product not found for variation #{$variation_id}";
                
                $this->qb_cost_adj_log(
                    'Variation',
                    $variation_id,
                    $variation->get_name(),
                    false,
                    null,
                    null,
                    'warning',
                    "Could not find parent product. Processing as standalone.",
                    'variation_sync'
                );
                
                $this->log_variation_sync_error($variation_id, $sync_status['error_message']);
                
                // Process as standalone
                $modified_data = $this->modify_product_data_before_sync($variation_data, $variation_id);
                
                // Log after processing
                $this->log_variation_data($modified_data, 'AFTER (as standalone)', $variation_id, 'Variation');
                
                return $modified_data;
            }
            
            $parent_name = $parent_product->get_name();
            
            // Process just this variation
            $multiplier = $this->get_multiplier();
            $regular_price = $variation->get_regular_price();
            
            // Log multiplier and price
            error_log("QB Cost Adjuster - Variation #{$variation_id} regular price: {$regular_price}, multiplier: {$multiplier}");
            
            // Create variation name with attributes for better identification
            $variation_attributes = $variation->get_attributes();
            $attribute_text = '';
            foreach ($variation_attributes as $attr_name => $attr_value) {
                if (!empty($attr_value)) {
                    $attribute_text .= wc_attribute_label($attr_name) . ': ' . $attr_value . ', ';
                }
            }
            $attribute_text = rtrim($attribute_text, ', ');
            
            if (empty($attribute_text)) {
                $attribute_text = $variation->get_name();
            }
            
            $variation_name = $parent_name . ' - ' . $attribute_text;
            
            // Validate regular price
            if (empty($regular_price)) {
                $sync_status['error_code'] = 'missing_regular_price';
                $sync_status['error_message'] = "Regular price is missing for variation #{$variation_id} ({$variation_name})";
                
                $this->qb_cost_adj_log(
                    'Variation',
                    $variation_id,
                    $variation_name,
                    false,
                    $regular_price,
                    $multiplier,
                    'error',
                    "Regular price is missing. Cannot calculate cost.",
                    'variation_sync'
                );
                
                $this->log_variation_sync_error($variation_id, $sync_status['error_message']);
                return $variation_data;
            }
            
            if (!is_numeric($regular_price)) {
                $sync_status['error_code'] = 'invalid_regular_price';
                $sync_status['error_message'] = "Regular price is not numeric for variation #{$variation_id} ({$variation_name}): {$regular_price}";
                
                $this->qb_cost_adj_log(
                    'Variation',
                    $variation_id,
                    $variation_name,
                    false,
                    $regular_price,
                    $multiplier,
                    'error',
                    "Regular price is not numeric. Cannot calculate cost.",
                    'variation_sync'
                );
                
                $this->log_variation_sync_error($variation_id, $sync_status['error_message']);
                return $variation_data;
            }
            
            // Calculate cost for this variation
            $calculated_cost = $this->calculate_cost_from_price($regular_price, $multiplier);
            
            // Skip if we couldn't calculate a valid cost
            if ($calculated_cost === false) {
                $sync_status['error_code'] = 'calculation_failed';
                $sync_status['error_message'] = "Cost calculation failed for variation #{$variation_id} ({$variation_name}) with price {$regular_price} and multiplier {$multiplier}";
                
                $this->qb_cost_adj_log(
                    'Variation',
                    $variation_id,
                    $variation_name,
                    false,
                    $regular_price,
                    $multiplier,
                    'error',
                    "Could not calculate valid cost. Regular price invalid or missing.",
                    'variation_sync'
                );
                
                $this->log_variation_sync_error($variation_id, $sync_status['error_message']);
                return $variation_data;
            }
            
            // Check for existing product cost meta if available
            $existing_product_cost = $variation->get_meta('_product_cost', true);
            
            // Log successful calculation
            $this->qb_cost_adj_log(
                'Variation',
                $variation_id,
                $variation_name,
                $calculated_cost,
                $regular_price,
                $multiplier,
                'success',
                "Cost calculated successfully" . (!empty($existing_product_cost) ? " (Previous cost: {$existing_product_cost})" : ""),
                'variation_sync'
            );
            
            // Update the variation data with calculated cost
            if (isset($variation_data['PurchaseCost'])) {
                $variation_data['PurchaseCost'] = $calculated_cost;
            }
            
            // Set cost field for QB
            $variation_data['UnitPrice'] = $calculated_cost;
            
            // Update other cost fields if they exist
            if (isset($variation_data['qb_p_cost'])) {
                $variation_data['qb_p_cost'] = $calculated_cost;
            } else {
                $variation_data['qb_p_cost'] = $calculated_cost;
            }
            
            // Update _product_cost meta key which appears in logs
            $variation_data['_product_cost'] = $calculated_cost;
            
            // Update WooCommerce Cost of Goods field
            if (isset($variation_data['_wc_cog_cost'])) {
                $variation_data['_wc_cog_cost'] = $calculated_cost;
            } else {
                $variation_data['_wc_cog_cost'] = $calculated_cost;
            }
            
            // Log the cost fields after modifications
            error_log("QB Cost Adjuster - Cost fields updated for variation #{$variation_id}:");
            error_log("  - PurchaseCost: " . $variation_data['PurchaseCost']);
            error_log("  - UnitPrice: " . $variation_data['UnitPrice']);
            error_log("  - qb_p_cost: " . $variation_data['qb_p_cost']);
            error_log("  - _product_cost: " . $variation_data['_product_cost']);
            error_log("  - _wc_cog_cost: " . $variation_data['_wc_cog_cost']);
            
            // Mark sync as successful
            $sync_status['success'] = true;
            
            // Store successful sync in database
            $this->log_variation_sync_success($variation_id, $variation_name, $regular_price, $calculated_cost, $multiplier);
            
            // Log the complete variation data just before it's sent to QuickBooks
            $this->log_product_data_before_sync($variation_data, $variation_id);
            
            // Log detailed information about the outgoing variation data
            $this->log_variation_data($variation_data, 'AFTER', $variation_id, 'Variation');
            
            return $variation_data;
            
        } catch (Exception $e) {
            // Log exception
            $sync_status['error_code'] = 'exception';
            $sync_status['error_message'] = "Exception during cost adjustment: " . $e->getMessage();
            
            $this->qb_cost_adj_log(
                'Variation',
                $variation_id,
                isset($variation_name) ? $variation_name : "Unknown Variation",
                false,
                isset($regular_price) ? $regular_price : null,
                isset($multiplier) ? $multiplier : null,
                'error',
                "Exception during cost adjustment: " . $e->getMessage(),
                'variation_sync'
            );
            
            $this->log_variation_sync_error($variation_id, $sync_status['error_message']);
            
            // Log the exception in detail
            error_log("QB Cost Adjuster - Exception during variation #{$variation_id} processing: " . $e->getMessage());
            error_log("Exception stack trace: " . $e->getTraceAsString());
            
            return $variation_data;
        }
    }
    
    /**
     * Log a variation sync error to the database
     * 
     * @param int $variation_id The variation ID
     * @param string $error_message The error message
     */
    private function log_variation_sync_error($variation_id, $error_message) {
        // Get existing sync errors
        $sync_errors = get_option('mwqbca_variation_sync_errors', array());
        
        // Limit to 500 errors to prevent option from growing too large
        if (count($sync_errors) >= 500) {
            // Remove oldest errors
            $sync_errors = array_slice($sync_errors, -499);
        }
        
        // Add new error
        $sync_errors[] = array(
            'variation_id' => $variation_id,
            'timestamp' => current_time('mysql'),
            'error_message' => $error_message
        );
        
        // Update option
        update_option('mwqbca_variation_sync_errors', $sync_errors);
        
        // Log to error_log if debugging is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('QB Cost Adjuster - Variation Sync Error - Variation #' . $variation_id . ': ' . $error_message);
        }
    }
    
    /**
     * Log a successful variation sync to the database
     * 
     * @param int $variation_id The variation ID
     * @param string $variation_name The variation name
     * @param float $regular_price The regular price
     * @param float $calculated_cost The calculated cost
     * @param float $multiplier The multiplier used
     */
    private function log_variation_sync_success($variation_id, $variation_name, $regular_price, $calculated_cost, $multiplier) {
        // Check if we should store success logs
        $store_success_logs = apply_filters('mwqbca_store_variation_success_logs', true);
        
        if (!$store_success_logs) {
            return;
        }
        
        // Get existing sync successes
        $sync_successes = get_option('mwqbca_variation_sync_successes', array());
        
        // Limit to 1000 logs to prevent option from growing too large
        if (count($sync_successes) >= 1000) {
            // Remove oldest logs
            $sync_successes = array_slice($sync_successes, -999);
        }
        
        // Add new success
        $sync_successes[] = array(
            'variation_id' => $variation_id,
            'variation_name' => $variation_name,
            'timestamp' => current_time('mysql'),
            'regular_price' => $regular_price,
            'calculated_cost' => $calculated_cost,
            'multiplier' => $multiplier
        );
        
        // Update option
        update_option('mwqbca_variation_sync_successes', $sync_successes);
    }
    
    /**
     * Process product data during bulk sync
     * 
     * @param array $push_data The data to be pushed to QuickBooks
     * @param int $product_id The WooCommerce product ID
     * @return array Modified push data
     */
    public function process_bulk_product_sync($push_data, $product_id) {
        // Check if cost adjuster is enabled
        if (!$this->is_cost_adjuster_enabled()) {
            $this->qb_cost_adj_log(
                'Unknown',
                $product_id,
                "Unknown Product",
                false,
                null,
                null,
                'info',
                "Cost adjustment disabled. Skipping bulk sync.",
                'bulk_sync'
            );
            return $push_data;
        }
        
        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            $this->qb_cost_adj_log(
                'Unknown',
                $product_id,
                "Unknown Product",
                false,
                null,
                null,
                'error',
                "Could not load product during bulk sync. Skipping.",
                'bulk_sync'
            );
            return $push_data;
        }
        
        // Get product type for logging
        $product_type = ucfirst($product->get_type());
        $product_name = $product->get_name();
        
        $this->qb_cost_adj_log(
            $product_type,
            $product_id,
            $product_name,
            false,
            null,
            null,
            'info',
            "Processing product in bulk sync",
            'bulk_sync'
        );
        
        // Process differently based on product type
        if ($product->is_type('variable')) {
            $this->qb_cost_adj_log(
                $product_type,
                $product_id,
                $product_name,
                false,
                null,
                null,
                'info',
                "Variable product detected in bulk sync",
                'bulk_sync'
            );
            
            // For variable products, calculate cost for parent and log
            // (variations will be processed separately via qb_cost_adjuster)
            $multiplier = $this->get_multiplier();
            
            // Get all variations to calculate average cost
            $variations = $product->get_children();
            
            if (!empty($variations)) {
                $total_cost = 0;
                $valid_variations = 0;
                
                $this->qb_cost_adj_log(
                    $product_type,
                    $product_id,
                    $product_name,
                    false,
                    null,
                    $multiplier,
                    'info',
                    "Found " . count($variations) . " variations",
                    'bulk_sync'
                );
                
                foreach ($variations as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    
                    if ($variation) {
                        $regular_price = $variation->get_regular_price();
                        $calculated_cost = $this->calculate_cost_from_price($regular_price, $multiplier);
                        
                        // Get variation info for logging
                        $variation_attributes = $variation->get_attributes();
                        $attribute_text = '';
                        foreach ($variation_attributes as $attr_name => $attr_value) {
                            if (!empty($attr_value)) {
                                $attribute_text .= wc_attribute_label($attr_name) . ': ' . $attr_value . ', ';
                            }
                        }
                        $attribute_text = rtrim($attribute_text, ', ');
                        
                        if (empty($attribute_text)) {
                            $attribute_text = $variation->get_name();
                        }
                        
                        $variation_name = $product_name . ' - ' . $attribute_text;
                        
                        if ($calculated_cost !== false) {
                            $total_cost += $calculated_cost;
                            $valid_variations++;
                            
                            $this->qb_cost_adj_log(
                                'Variation',
                                $variation_id,
                                $variation_name,
                                $calculated_cost,
                                $regular_price,
                                $multiplier,
                                'success',
                                "Calculated cost for variation during bulk sync",
                                'bulk_sync'
                            );
                        } else {
                            $this->qb_cost_adj_log(
                                'Variation',
                                $variation_id,
                                $variation_name,
                                false,
                                $regular_price,
                                $multiplier,
                                'error',
                                "Could not calculate valid cost. Regular price invalid or missing.",
                                'bulk_sync'
                            );
                        }
                    }
                }
                
                // Calculate average cost for parent product
                $parent_cost = ($valid_variations > 0) ? round($total_cost / $valid_variations, 2) : false;
                
                if ($parent_cost !== false) {
                    $this->qb_cost_adj_log(
                        $product_type,
                        $product_id,
                        $product_name,
                        $parent_cost,
                        null,
                        $multiplier,
                        'success',
                        "Set average cost for variable product (based on {$valid_variations} variations)",
                        'bulk_sync'
                    );
                    
                    // Update the product data with calculated cost
                    $push_data['PurchaseCost'] = $parent_cost;
                    $push_data['UnitPrice'] = $parent_cost;
                    
                    // Also update _product_cost meta key
                    $push_data['_product_cost'] = $parent_cost;
                    
                    // Update WooCommerce Cost of Goods field
                    $push_data['_wc_cog_cost'] = $parent_cost;
                    
                    // Log the updates in detail
                    $this->qb_cost_adj_log(
                        $product_type,
                        $product_id,
                        $product_name,
                        $parent_cost,
                        null,
                        $multiplier,
                        'success',
                        "Calculated cost during bulk sync. Updated PurchaseCost, UnitPrice, _product_cost, and _wc_cog_cost.",
                        'bulk_sync'
                    );
                } else {
                    $this->qb_cost_adj_log(
                        $product_type,
                        $product_id,
                        $product_name,
                        false,
                        null,
                        $multiplier,
                        'error',
                        "Could not calculate valid cost for any variations",
                        'bulk_sync'
                    );
                }
            } else {
                $this->qb_cost_adj_log(
                    $product_type,
                    $product_id,
                    $product_name,
                    false,
                    null,
                    $multiplier,
                    'warning',
                    "No variations found for variable product",
                    'bulk_sync'
                );
            }
        } else {
            // Simple product - get regular price and calculate cost
            $multiplier = $this->get_multiplier();
            $regular_price = $product->get_regular_price();
            
            $calculated_cost = $this->calculate_cost_from_price($regular_price, $multiplier);
            
            if ($calculated_cost !== false) {
                $this->qb_cost_adj_log(
                    $product_type,
                    $product_id,
                    $product_name,
                    $calculated_cost,
                    $regular_price,
                    $multiplier,
                    'success',
                    "Calculated cost during bulk sync",
                    'bulk_sync'
                );
                
                // Update the product data with calculated cost
                $push_data['PurchaseCost'] = $calculated_cost;
                $push_data['UnitPrice'] = $calculated_cost;
                
                // Also update _product_cost meta key
                $push_data['_product_cost'] = $calculated_cost;
                
                // Update WooCommerce Cost of Goods field
                $push_data['_wc_cog_cost'] = $calculated_cost;
                
                // Log the updates in detail
                $this->qb_cost_adj_log(
                    $product_type,
                    $product_id,
                    $product_name,
                    $calculated_cost,
                    $regular_price,
                    $multiplier,
                    'success',
                    "Calculated cost during bulk sync. Updated PurchaseCost, UnitPrice, _product_cost, and _wc_cog_cost.",
                    'bulk_sync'
                );
            } else {
                $this->qb_cost_adj_log(
                    $product_type,
                    $product_id,
                    $product_name,
                    false,
                    $regular_price,
                    $multiplier,
                    'error',
                    "Could not calculate valid cost. Regular price invalid or missing.",
                    'bulk_sync'
                );
            }
        }
        
        // Log the complete product data just before it's sent to QuickBooks
        $this->log_product_data_before_sync($push_data, $product_id);
        
        return $push_data;
    }
    
    /**
     * Log cost adjustment details
     * 
     * @param string $product_type Type of product (Simple, Variation, etc.)
     * @param int $product_id WooCommerce product ID
     * @param string $product_name Product name or description
     * @param float|bool $cost Calculated cost or false if calculation failed
     * @param float $regular_price Regular price used for calculation
     * @param float $multiplier Multiplier used for calculation
     * @param string $status Status of the sync (success, error, etc.)
     * @param string $message Additional details or error message
     * @param string $source Source of the sync request (individual, bulk, etc.)
     */
    public function qb_cost_adj_log($product_type, $product_id, $product_name, $cost, $regular_price = null, $multiplier = null, $status = 'success', $message = '', $source = 'individual') {
        // Get timestamp with timezone
        $timestamp = current_time('mysql');
        
        // Build log entry
        $log_data = array(
            'timestamp' => $timestamp,
            'product_type' => $product_type,
            'product_id' => $product_id,
            'product_name' => $product_name,
            'cost' => ($cost !== false) ? number_format((float)$cost, 2, '.', '') : 'N/A',
            'regular_price' => ($regular_price !== null) ? number_format((float)$regular_price, 2, '.', '') : 'N/A',
            'multiplier' => ($multiplier !== null) ? number_format((float)$multiplier, 4, '.', '') : 'N/A',
            'status' => $status,
            'message' => $message,
            'source' => $source
        );
        
        // Format the log message
        if ($status == 'success') {
            $log_message = sprintf(
                "[%s] [SYNC:%s] [TYPE:%s] Product: %s (#%d) | Price: $%s | Multiplier: %s | Calculated Cost: $%s | %s",
                $timestamp,
                $source,
                $product_type,
                $product_name,
                $product_id,
                $log_data['regular_price'],
                $log_data['multiplier'],
                $log_data['cost'],
                $message
            );
        } else {
            $log_message = sprintf(
                "[%s] [ERROR:%s] [TYPE:%s] Product: %s (#%d) | %s | Price: $%s | Multiplier: %s",
                $timestamp,
                $source,
                $product_type,
                $product_name,
                $product_id,
                $message,
                $log_data['regular_price'],
                $log_data['multiplier']
            );
        }
        
        // Log to WordPress error log if debugging is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('QB Cost Adjuster - ' . $log_message);
        }
        
        // Log to our custom log file
        $this->logger->log($log_message, ($status == 'success') ? 'info' : 'error');
        
        // Store sync logs in the database for reporting
        $this->store_sync_log($log_data);
        
        return $log_data;
    }
    
    /**
     * Store sync logs in the database for reporting
     * 
     * @param array $log_data Log data to store
     */
    private function store_sync_log($log_data) {
        // Check if we should store logs in the database
        $store_in_db = apply_filters('mwqbca_store_logs_in_db', true);
        
        if (!$store_in_db) {
            return;
        }
        
        // Get existing logs
        $logs = get_option('mwqbca_sync_logs', array());
        
        // Limit to 1000 logs to prevent option from growing too large
        if (count($logs) >= 1000) {
            // Remove oldest logs
            $logs = array_slice($logs, -999);
        }
        
        // Add new log
        $logs[] = $log_data;
        
        // Update option
        update_option('mwqbca_sync_logs', $logs);
    }
}

// Create includes directory if it doesn't exist
if (!file_exists(MWQBCA_PLUGIN_PATH . 'includes')) {
    mkdir(MWQBCA_PLUGIN_PATH . 'includes', 0755, true);
}

// Create logger class file if it doesn't exist
if (!file_exists(MWQBCA_PLUGIN_PATH . 'includes/class-mwqbca-logger.php')) {
    $logger_code = '<?php
/**
 * Logger class for MyWorks QuickBooks Cost Adjuster
 *
 * @package MyWorks_QB_Cost_Adjuster
 */

// Exit if accessed directly
if (!defined(\'ABSPATH\')) {
    exit;
}

/**
 * Logger class
 */
class MWQBCA_Logger {
    
    /**
     * Log directory
     */
    private $log_dir;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->log_dir = WP_CONTENT_DIR . \'/uploads/myworks-qb-cost-adjuster-logs/\';
        
        // Create log directory if it\'s not exist
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
    }
    
    /**
     * Log a message
     * 
     * @param string $message The message to log
     * @param string $level Log level (info, warning, error)
     */
    public function log($message, $level = \'info\') {
        // Always enable logging for cost adjustment operations
        // No longer check for log_enabled setting
        
        $date = date(\'Y-m-d\');
        $time = date(\'H:i:s\');
        
        $log_file = $this->log_dir . \'cost-adjuster-\' . $date . \'.log\';
        
        $log_entry = "[{$date} {$time}] [{$level}] {$message}" . PHP_EOL;
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        // Also log to error_log for easier debugging during development
        if (defined(\'WP_DEBUG\') && WP_DEBUG) {
            error_log(\'QB Cost Adjuster - \' . $message);
        }
    }
    
    /**
     * Get log files
     * 
     * @return array List of log files
     */
    public function get_log_files() {
        $files = array();
        
        if (file_exists($this->log_dir) && is_dir($this->log_dir)) {
            $files = glob($this->log_dir . \'cost-adjuster-*.log\');
            $files = array_map(\'basename\', $files);
            rsort($files); // Most recent first
        }
        
        return $files;
    }
    
    /**
     * Get log file content
     * 
     * @param string $file Log file name
     * @return string Log file content
     */
    public function get_log_content($file) {
        $file_path = $this->log_dir . $file;
        
        if (file_exists($file_path)) {
            return file_get_contents($file_path);
        }
        
        return \'\';
    }
    
    /**
     * Delete log file
     * 
     * @param string $file Log file name
     * @return bool Success status
     */
    public function delete_log($file) {
        $file_path = $this->log_dir . $file;
        
        if (file_exists($file_path)) {
            return unlink($file_path);
        }
        
        return false;
    }
    
    /**
     * Clear all logs
     * 
     * @return bool Success status
     */
    public function clear_logs() {
        $files = $this->get_log_files();
        
        $success = true;
        foreach ($files as $file) {
            if (!$this->delete_log($file)) {
                $success = false;
            }
        }
        
        return $success;
    }
}';
    
    file_put_contents(MWQBCA_PLUGIN_PATH . 'includes/class-mwqbca-logger.php', $logger_code);
} 