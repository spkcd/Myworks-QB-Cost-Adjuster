<?php
/**
 * Plugin Name: MyWorks QB Cost Adjuster
 * Plugin URI: https://myworks.software
 * Description: Adjusts product cost before syncing to QuickBooks via MyWorks Sync.
 * Version: 1.0.1
 * Author: SPARKWEB Studio
 * Author URI: https://sparkwebstudio.com
 * Text Domain: myworks-qb-cost-adjuster
 * Domain Path: /languages
 *
 * Requires at least: 5.3
 * Requires PHP: 7.0
 * 
 * WC requires at least: 3.0.0
 * WC tested up to: 7.9.0
 *
 * Copyright: © 2025 SPARKWEB Studio
 */

/**
 * Changelog:
 * 
 * = 1.0.1 - May 22, 2025 =
 * * Fixed: Added missing qb_cost_adjuster_add_settings_link function causing fatal error
 * 
 * = 1.0.0 - May 11, 2025 =
 * * Initial release
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MWQBCA_PLUGIN_FILE', __FILE__);
define('MWQBCA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MWQBCA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MWQBCA_VERSION', '1.0.1');

// Include required files
require_once MWQBCA_PLUGIN_PATH . 'settings.php';
require_once MWQBCA_PLUGIN_PATH . 'cost-adjustment.php';
require_once MWQBCA_PLUGIN_PATH . 'log-viewer.php';
require_once MWQBCA_PLUGIN_PATH . 'sync-report.php';
require_once MWQBCA_PLUGIN_PATH . 'includes/class-qb-cost-adjuster-logger.php';

/**
 * Main plugin class
 */
class MyWorks_QB_Cost_Adjuster {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Settings instance
     */
    public $settings;
    
    /**
     * Cost adjustment instance
     */
    public $cost_adjustment;
    
    /**
     * Log viewer instance
     */
    public $log_viewer;
    
    /**
     * Sync report instance
     */
    public $sync_report;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Initialize components
        $this->settings = new MWQBCA_Settings();
        $this->cost_adjustment = new MWQBCA_Cost_Adjustment();
        $this->log_viewer = new MWQBCA_Log_Viewer();
        $this->sync_report = new MWQBCA_Sync_Report();
        
        // Initialize progress data
        $this->progress_data = array(
            'total' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'recent_logs' => array(),
            'complete' => false
        );
        
        // Register activation hook
        register_activation_hook(MWQBCA_PLUGIN_FILE, array($this, 'activate'));
        
        // Register deactivation hook
        register_deactivation_hook(MWQBCA_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Add hooks
        add_action('plugins_loaded', array($this, 'init'));
        
        // Register AJAX handlers
        add_action('wp_ajax_mwqbca_force_update_all', array($this, 'ajax_force_update_all'));
        add_action('wp_ajax_mwqbca_get_progress', array($this, 'ajax_get_progress'));
        
        // Register admin assets
        add_action('admin_enqueue_scripts', array($this, 'register_admin_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_not_active_notice'));
            return;
        }
        
        // Check if MyWorks Sync is active
        if (!$this->is_myworks_sync_active()) {
            add_action('admin_notices', array($this, 'myworks_sync_not_active_notice'));
            return;
        }
        
        // Load text domain
        load_plugin_textdomain('myworks-qb-cost-adjuster', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        
        // Register settings
        $this->register_settings();
        
        // Add menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register custom hooks with MyWorks Sync
        $this->register_myworks_hooks();
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
        
        // Add debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('admin_footer', array($this, 'debug_output'));
        }
    }

    /**
     * Register plugin settings
     */
    private function register_settings() {
        // Register the settings
        register_setting(
            'mwqbca_settings', // Option group
            'mwqbca_settings', // Option name
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => array(
                    'enabled' => 0,
                    'cost_multiplier' => '1.65'
                )
            )
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Sanitize enabled setting
        $sanitized['enabled'] = isset($input['enabled']) ? 1 : 0;
        
        // Sanitize cost multiplier
        if (isset($input['cost_multiplier'])) {
            $multiplier = floatval($input['cost_multiplier']);
            $sanitized['cost_multiplier'] = number_format($multiplier, 2, '.', '');
        } else {
            $sanitized['cost_multiplier'] = '1.65';
        }
        
        return $sanitized;
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create log directory
        $log_dir = WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/';
        wp_mkdir_p($log_dir);
        
        // Initialize default settings
        $default_settings = array(
            'enabled' => 0,
            'cost_multiplier' => '1.65'
        );
        
        // Only set default settings if they don't exist
        if (!get_option('mwqbca_settings')) {
            update_option('mwqbca_settings', $default_settings);
        }
        
        // Initialize log storage
        update_option('mwqbca_sync_logs', array());
        update_option('mwqbca_api_logs', array());
        update_option('mwqbca_detailed_variation_logs', array());
        update_option('mwqbca_product_data_logs', array());
        update_option('mwqbca_error_logs', array());
        
        // Create database tables
        $db_result = $this->create_database_tables();
        if (is_wp_error($db_result)) {
            // Log the error but don't prevent activation
            $this->handle_database_error($db_result);
        }
        
        // Schedule log cleanup
        $this->schedule_log_cleanup();
        
        // Add cleanup action hook
        add_action('mwqbca_cleanup_logs', array($this, 'cleanup_old_logs'));
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Unschedule log cleanup
        $this->unschedule_log_cleanup();
        
        // Remove cleanup action hook
        remove_action('mwqbca_cleanup_logs', array($this, 'cleanup_old_logs'));
        
        // Clean up old data (but don't remove everything)
        $this->cleanup_database_tables(false);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Debug log
        error_log('QB Cost Adjuster - Adding admin menu');
        
        add_submenu_page(
            'woocommerce',
            __('QuickBooks Cost Adjuster', 'myworks-qb-cost-adjuster'),
            __('QB Cost Adjuster', 'myworks-qb-cost-adjuster'),
            'manage_woocommerce',
            'myworks-qb-cost-adjuster',
            array($this, 'render_settings_page')
        );
        
        // Debug log
        error_log('QB Cost Adjuster - Admin menu added');
    }

    public function render_settings_page() {
        // Debug log
        error_log('QB Cost Adjuster - Rendering settings page');
        
        // Check if settings exist
        $settings = get_option('mwqbca_settings', array());
        error_log('QB Cost Adjuster - Current settings: ' . print_r($settings, true));
        
        // Include the settings page template
        include MWQBCA_PLUGIN_PATH . 'templates/settings.php';
        
        // Debug log
        error_log('QB Cost Adjuster - Settings page rendered');
    }
    
    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }
    
    /**
     * Check if MyWorks Sync for QuickBooks Online is active
     */
    private function is_myworks_sync_active() {
        return class_exists('MyWorks_WC_QBO_Sync');
    }
    
    /**
     * Display WooCommerce not active notice
     */
    public function woocommerce_not_active_notice() {
        ?>
        <div class="error">
            <p><?php _e('MyWorks QuickBooks Cost Adjuster requires WooCommerce to be installed and activated.', 'myworks-qb-cost-adjuster'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Display MyWorks Sync not active notice
     */
    public function myworks_sync_not_active_notice() {
        ?>
        <div class="error">
            <p><?php _e('MyWorks QuickBooks Cost Adjuster requires MyWorks Sync for QuickBooks Online to be installed and activated.', 'myworks-qb-cost-adjuster'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Register hooks with MyWorks Sync
     */
    private function register_myworks_hooks() {
        // Primary filter for product syncing - using priority 999 to ensure our changes aren't overridden
        add_filter('myworks_sync_product_data_before_sync', array($this->cost_adjustment, 'modify_product_data_before_sync'), 999, 2);
        
        // Add hook for variations with higher priority
        add_filter('myworks_wc_qbo_sync_variation_data_before_sync', array($this->cost_adjustment, 'qb_cost_adjuster'), 999, 2);
        
        // Hooks for backward compatibility
        add_filter('myworks_wc_qbo_sync_before_product_push', array($this->cost_adjustment, 'modify_product_data'), 999, 2);
        add_filter('myworks_wc_qbo_sync_before_inventory_push', array($this->cost_adjustment, 'modify_inventory_data'), 999, 2);
    }
    
    /**
     * Handle API errors
     * 
     * @param mixed $error The error object or message
     * @param array $context Additional context about the error
     * @return void
     */
    public function handle_api_error($error, $context = array()) {
        // Log the error
        $error_message = is_string($error) ? $error : print_r($error, true);
        error_log('QB Cost Adjuster API Error: ' . $error_message);
        
        // Add context to error log if provided
        if (!empty($context)) {
            error_log('QB Cost Adjuster API Error Context: ' . print_r($context, true));
        }
        
        // Store error in plugin's error log
        $error_logs = get_option('mwqbca_error_logs', array());
        $error_logs[] = array(
            'timestamp' => current_time('mysql'),
            'type' => 'api',
            'message' => $error_message,
            'context' => $context
        );
        
        // Keep only last 100 errors
        if (count($error_logs) > 100) {
            $error_logs = array_slice($error_logs, -100);
        }
        
        update_option('mwqbca_error_logs', $error_logs);
        
        // Trigger error action for other plugins/themes to hook into
        do_action('mwqbca_api_error', $error, $context);
    }
    
    /**
     * Handle database errors
     * 
     * @param mixed $error The error object or message
     * @param array $context Additional context about the error
     * @return void
     */
    public function handle_database_error($error, $context = array()) {
        // Log the error
        $error_message = is_string($error) ? $error : print_r($error, true);
        error_log('QB Cost Adjuster Database Error: ' . $error_message);
        
        // Add context to error log if provided
        if (!empty($context)) {
            error_log('QB Cost Adjuster Database Error Context: ' . print_r($context, true));
        }
        
        // Store error in plugin's error log
        $error_logs = get_option('mwqbca_error_logs', array());
        $error_logs[] = array(
            'timestamp' => current_time('mysql'),
            'type' => 'database',
            'message' => $error_message,
            'context' => $context
        );
        
        // Keep only last 100 errors
        if (count($error_logs) > 100) {
            $error_logs = array_slice($error_logs, -100);
        }
        
        update_option('mwqbca_error_logs', $error_logs);
        
        // Trigger error action for other plugins/themes to hook into
        do_action('mwqbca_database_error', $error, $context);
    }
    
    /**
     * Handle sync errors
     * 
     * @param mixed $error The error object or message
     * @param int $product_id The ID of the product that failed to sync
     * @return void
     */
    public function handle_sync_error($error, $product_id) {
        // Log the error
        $error_message = is_string($error) ? $error : print_r($error, true);
        error_log('QB Cost Adjuster Sync Error for Product #' . $product_id . ': ' . $error_message);
        
        // Store error in plugin's error log
        $error_logs = get_option('mwqbca_error_logs', array());
        $error_logs[] = array(
            'timestamp' => current_time('mysql'),
            'type' => 'sync',
            'product_id' => $product_id,
            'message' => $error_message
        );
        
        // Keep only last 100 errors
        if (count($error_logs) > 100) {
            $error_logs = array_slice($error_logs, -100);
        }
        
        update_option('mwqbca_error_logs', $error_logs);
        
        // Update product meta to mark sync failure
        update_post_meta($product_id, '_mwqbca_sync_error', $error_message);
        update_post_meta($product_id, '_mwqbca_last_sync_error', current_time('mysql'));
        
        // Trigger error action for other plugins/themes to hook into
        do_action('mwqbca_sync_error', $error, $product_id);
    }

    /**
     * Validate product data before sync
     * 
     * @param array $product_data The product data to validate
     * @return array|WP_Error Array of validated data or WP_Error on failure
     */
    public function validate_product_data($product_data) {
        // Check if product data is an array
        if (!is_array($product_data)) {
            return new WP_Error(
                'invalid_product_data',
                __('Product data must be an array', 'myworks-qb-cost-adjuster')
            );
        }

        // Required fields
        $required_fields = array('Name', 'SKU', 'PurchaseCost', 'UnitPrice');
        foreach ($required_fields as $field) {
            if (!isset($product_data[$field]) || empty($product_data[$field])) {
                return new WP_Error(
                    'missing_required_field',
                    sprintf(__('Required field "%s" is missing or empty', 'myworks-qb-cost-adjuster'), $field)
                );
            }
        }

        // Validate numeric fields
        $numeric_fields = array('PurchaseCost', 'UnitPrice');
        foreach ($numeric_fields as $field) {
            if (!is_numeric($product_data[$field]) || floatval($product_data[$field]) < 0) {
                return new WP_Error(
                    'invalid_numeric_field',
                    sprintf(__('Field "%s" must be a positive number', 'myworks-qb-cost-adjuster'), $field)
                );
            }
        }

        // Validate SKU format
        if (!preg_match('/^[a-zA-Z0-9-_]+$/', $product_data['SKU'])) {
            return new WP_Error(
                'invalid_sku_format',
                __('SKU must contain only letters, numbers, hyphens, and underscores', 'myworks-qb-cost-adjuster')
            );
        }

        return $product_data;
    }

    /**
     * Validate QuickBooks API response
     * 
     * @param mixed $response The API response to validate
     * @return bool|WP_Error True if valid, WP_Error on failure
     */
    public function validate_quickbooks_response($response) {
        // Check if response is valid
        if (empty($response)) {
            return new WP_Error(
                'empty_response',
                __('QuickBooks API returned an empty response', 'myworks-qb-cost-adjuster')
            );
        }

        // Check for error in response
        if (isset($response['Fault'])) {
            $error_message = isset($response['Fault']['Error'][0]['Message']) 
                ? $response['Fault']['Error'][0]['Message']
                : __('Unknown QuickBooks API error', 'myworks-qb-cost-adjuster');
            
            return new WP_Error(
                'quickbooks_api_error',
                $error_message
            );
        }

        // Check for required response fields
        if (!isset($response['Item']) && !isset($response['QueryResponse']['Item'])) {
            return new WP_Error(
                'invalid_response_format',
                __('QuickBooks API response missing required Item data', 'myworks-qb-cost-adjuster')
            );
        }

        return true;
    }

    /**
     * Validate cost calculation
     * 
     * @param float $cost The calculated cost
     * @param float $regular_price The product's regular price
     * @param float $multiplier The multiplier used
     * @return bool|WP_Error True if valid, WP_Error on failure
     */
    public function validate_cost_calculation($cost, $regular_price, $multiplier) {
        // Validate inputs
        if (!is_numeric($cost) || !is_numeric($regular_price) || !is_numeric($multiplier)) {
            return new WP_Error(
                'invalid_input',
                __('All inputs must be numeric values', 'myworks-qb-cost-adjuster')
            );
        }

        // Convert to float for comparison
        $cost = floatval($cost);
        $regular_price = floatval($regular_price);
        $multiplier = floatval($multiplier);

        // Check for negative values
        if ($cost < 0 || $regular_price < 0 || $multiplier <= 0) {
            return new WP_Error(
                'invalid_values',
                __('Cost and price must be positive, multiplier must be greater than zero', 'myworks-qb-cost-adjuster')
            );
        }

        // Validate the calculation
        $expected_cost = $regular_price / $multiplier;
        $tolerance = 0.01; // 1 cent tolerance for floating point comparison

        if (abs($cost - $expected_cost) > $tolerance) {
            return new WP_Error(
                'calculation_mismatch',
                sprintf(
                    __('Cost calculation mismatch. Expected: %s, Got: %s', 'myworks-qb-cost-adjuster'),
                    number_format($expected_cost, 2),
                    number_format($cost, 2)
                )
            );
        }

        return true;
    }

    /**
     * Format currency value
     * 
     * @param float $amount The amount to format
     * @param string $currency The currency code (default: USD)
     * @return string Formatted currency value
     */
    public function format_currency($amount, $currency = 'USD') {
        // Ensure amount is numeric
        if (!is_numeric($amount)) {
            return '0.00';
        }

        // Get WooCommerce currency settings
        $currency_symbol = get_woocommerce_currency_symbol($currency);
        $decimal_separator = wc_get_price_decimal_separator();
        $thousand_separator = wc_get_price_thousand_separator();
        $decimals = wc_get_price_decimals();

        // Format the amount
        $formatted = number_format(
            floatval($amount),
            $decimals,
            $decimal_separator,
            $thousand_separator
        );

        // Add currency symbol
        return $currency_symbol . $formatted;
    }

    /**
     * Get timezone adjusted time
     * 
     * @param string $format The date format (default: MySQL format)
     * @return string Formatted date/time in site's timezone
     */
    public function get_timezone_adjusted_time($format = 'Y-m-d H:i:s') {
        // Get WordPress timezone
        $timezone = wp_timezone();
        
        // Get current time in UTC
        $utc_time = new DateTime('now', new DateTimeZone('UTC'));
        
        // Convert to site's timezone
        $utc_time->setTimezone($timezone);
        
        // Return formatted time
        return $utc_time->format($format);
    }

    /**
     * Clean up old logs
     * 
     * @param int $days_to_keep Number of days to keep logs (default: 30)
     * @return int Number of logs deleted
     */
    public function cleanup_old_logs($days_to_keep = 30) {
        $deleted_count = 0;
        $log_types = array(
            'mwqbca_sync_logs',
            'mwqbca_api_logs',
            'mwqbca_detailed_variation_logs',
            'mwqbca_product_data_logs',
            'mwqbca_error_logs'
        );

        // Calculate cutoff date
        $cutoff_date = strtotime("-{$days_to_keep} days");

        foreach ($log_types as $log_type) {
            $logs = get_option($log_type, array());
            if (empty($logs)) {
                continue;
            }

            $original_count = count($logs);
            $logs = array_filter($logs, function($log) use ($cutoff_date) {
                $log_date = isset($log['timestamp']) 
                    ? strtotime($log['timestamp'])
                    : 0;
                return $log_date >= $cutoff_date;
            });

            // Update option with filtered logs
            update_option($log_type, array_values($logs));
            
            // Count deleted logs
            $deleted_count += ($original_count - count($logs));
        }

        // Clean up physical log files
        $log_dir = WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/';
        if (is_dir($log_dir)) {
            $files = glob($log_dir . '*.log');
            foreach ($files as $file) {
                if (filemtime($file) < $cutoff_date) {
                    unlink($file);
                    $deleted_count++;
                }
            }
        }

        // Log cleanup results
        $this->handle_database_error(
            sprintf(
                __('Cleaned up %d old log entries and files', 'myworks-qb-cost-adjuster'),
                $deleted_count
            ),
            array('days_kept' => $days_to_keep)
        );

        return $deleted_count;
    }

    /**
     * Schedule log cleanup
     * 
     * @return void
     */
    private function schedule_log_cleanup() {
        if (!wp_next_scheduled('mwqbca_cleanup_logs')) {
            wp_schedule_event(
                time(),
                'daily',
                'mwqbca_cleanup_logs'
            );
        }
    }

    /**
     * Unschedule log cleanup
     * 
     * @return void
     */
    private function unschedule_log_cleanup() {
        $timestamp = wp_next_scheduled('mwqbca_cleanup_logs');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mwqbca_cleanup_logs');
        }
    }

    /**
     * Verify nonce for security
     * 
     * @param string $nonce The nonce to verify
     * @param string $action The action name
     * @return bool|WP_Error True if valid, WP_Error on failure
     */
    public function verify_nonce($nonce, $action) {
        if (!wp_verify_nonce($nonce, $action)) {
            return new WP_Error(
                'invalid_nonce',
                __('Security check failed. Please refresh the page and try again.', 'myworks-qb-cost-adjuster')
            );
        }
        return true;
    }

    /**
     * Sanitize input data
     * 
     * @param mixed $input The input to sanitize
     * @param string $type The type of input (text, number, email, etc.)
     * @return mixed Sanitized input
     */
    public function sanitize_input($input, $type = 'text') {
        if (is_array($input)) {
            return array_map(function($item) use ($type) {
                return $this->sanitize_input($item, $type);
            }, $input);
        }

        switch ($type) {
            case 'number':
                return is_numeric($input) ? floatval($input) : 0;
            
            case 'email':
                return sanitize_email($input);
            
            case 'url':
                return esc_url_raw($input);
            
            case 'textarea':
                return sanitize_textarea_field($input);
            
            case 'key':
                return sanitize_key($input);
            
            case 'title':
                return sanitize_title($input);
            
            case 'html':
                return wp_kses_post($input);
            
            case 'text':
            default:
                return sanitize_text_field($input);
        }
    }

    /**
     * Check user capability
     * 
     * @param string $capability The capability to check
     * @param int $user_id Optional user ID (defaults to current user)
     * @return bool|WP_Error True if user has capability, WP_Error on failure
     */
    public function check_user_capability($capability, $user_id = null) {
        if (is_null($user_id)) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return new WP_Error(
                'not_logged_in',
                __('You must be logged in to perform this action.', 'myworks-qb-cost-adjuster')
            );
        }

        if (!user_can($user_id, $capability)) {
            return new WP_Error(
                'insufficient_permissions',
                __('You do not have sufficient permissions to perform this action.', 'myworks-qb-cost-adjuster')
            );
        }

        return true;
    }

    /**
     * Validate and sanitize API request
     * 
     * @param array $request The API request data
     * @return array|WP_Error Sanitized request data or WP_Error on failure
     */
    public function validate_api_request($request) {
        // Check if request is valid
        if (!is_array($request)) {
            return new WP_Error(
                'invalid_request',
                __('Invalid API request format.', 'myworks-qb-cost-adjuster')
            );
        }

        // Required fields
        $required_fields = array('action', 'nonce');
        foreach ($required_fields as $field) {
            if (!isset($request[$field]) || empty($request[$field])) {
                return new WP_Error(
                    'missing_required_field',
                    sprintf(__('Required field "%s" is missing.', 'myworks-qb-cost-adjuster'), $field)
                );
            }
        }

        // Verify nonce
        $nonce_check = $this->verify_nonce($request['nonce'], 'mwqbca_api_' . $request['action']);
        if (is_wp_error($nonce_check)) {
            return $nonce_check;
        }

        // Sanitize request data
        $sanitized = array();
        foreach ($request as $key => $value) {
            $sanitized[$key] = $this->sanitize_input($value);
        }

        return $sanitized;
    }

    /**
     * Validate and sanitize admin request
     * 
     * @param array $request The admin request data
     * @param string $action The action being performed
     * @return array|WP_Error Sanitized request data or WP_Error on failure
     */
    public function validate_admin_request($request, $action) {
        // Check if request is valid
        if (!is_array($request)) {
            return new WP_Error(
                'invalid_request',
                __('Invalid admin request format.', 'myworks-qb-cost-adjuster')
            );
        }

        // Check nonce
        if (!isset($request['_wpnonce']) || !wp_verify_nonce($request['_wpnonce'], 'mwqbca_admin_' . $action)) {
            return new WP_Error(
                'invalid_nonce',
                __('Security check failed. Please refresh the page and try again.', 'myworks-qb-cost-adjuster')
            );
        }

        // Check user capabilities
        $capability_check = $this->check_user_capability('manage_woocommerce');
        if (is_wp_error($capability_check)) {
            return $capability_check;
        }

        // Sanitize request data
        $sanitized = array();
        foreach ($request as $key => $value) {
            $sanitized[$key] = $this->sanitize_input($value);
        }

        return $sanitized;
    }

    /**
     * Create database tables
     * 
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function create_database_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Table for cost adjustment history
        $table_name = $wpdb->prefix . 'mwqbca_cost_history';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            old_cost decimal(10,2) DEFAULT NULL,
            new_cost decimal(10,2) NOT NULL,
            multiplier decimal(10,2) NOT NULL,
            adjusted_by bigint(20) NOT NULL,
            adjusted_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY adjusted_at (adjusted_at)
        ) $charset_collate;";

        // Table for sync history
        $sync_table = $wpdb->prefix . 'mwqbca_sync_history';
        $sync_sql = "CREATE TABLE IF NOT EXISTS $sync_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            sync_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            synced_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY sync_type (sync_type),
            KEY synced_at (synced_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Create tables
        $result1 = dbDelta($sql);
        $result2 = dbDelta($sync_sql);

        // Check for errors
        if (is_wp_error($result1) || is_wp_error($result2)) {
            return new WP_Error(
                'db_error',
                __('Failed to create database tables.', 'myworks-qb-cost-adjuster')
            );
        }

        // Store current database version
        update_option('mwqbca_db_version', MWQBCA_VERSION);

        return true;
    }

    /**
     * Update database schema
     * 
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function update_database_schema() {
        global $wpdb;
        $current_version = get_option('mwqbca_db_version', '0');
        
        // If versions match, no update needed
        if (version_compare($current_version, MWQBCA_VERSION, '=')) {
            return true;
        }

        // Perform version-specific updates
        if (version_compare($current_version, '1.0', '<')) {
            // Add new columns or tables for version 1.0
            $table_name = $wpdb->prefix . 'mwqbca_cost_history';
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS notes text AFTER adjusted_at");
        }

        // Update database version
        update_option('mwqbca_db_version', MWQBCA_VERSION);

        return true;
    }

    /**
     * Clean up database tables
     * 
     * @param bool $remove_data Whether to remove all data (default: false)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function cleanup_database_tables($remove_data = false) {
        global $wpdb;

        if ($remove_data) {
            // Remove all data from tables
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}mwqbca_cost_history");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}mwqbca_sync_history");
            
            // Delete options
            delete_option('mwqbca_db_version');
            delete_option('mwqbca_enabled');
            delete_option('qb_cost_multiplier');
            delete_option('mwqbca_sync_logs');
            delete_option('mwqbca_api_logs');
            delete_option('mwqbca_detailed_variation_logs');
            delete_option('mwqbca_product_data_logs');
            delete_option('mwqbca_error_logs');
        } else {
            // Archive old data (older than 90 days)
            $cutoff_date = date('Y-m-d H:i:s', strtotime('-90 days'));
            
            // Archive cost history
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}mwqbca_cost_history WHERE adjusted_at < %s",
                $cutoff_date
            ));
            
            // Archive sync history
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}mwqbca_sync_history WHERE synced_at < %s",
                $cutoff_date
            ));
        }

        return true;
    }

    /**
     * Log cost adjustment to database
     * 
     * @param int $product_id The product ID
     * @param float $old_cost The old cost
     * @param float $new_cost The new cost
     * @param float $multiplier The multiplier used
     * @param string $notes Optional notes
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function log_cost_adjustment($product_id, $old_cost, $new_cost, $multiplier, $notes = '') {
        global $wpdb;
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'mwqbca_cost_history',
            array(
                'product_id' => $product_id,
                'old_cost' => $old_cost,
                'new_cost' => $new_cost,
                'multiplier' => $multiplier,
                'adjusted_by' => get_current_user_id(),
                'adjusted_at' => current_time('mysql'),
                'notes' => $notes
            ),
            array('%d', '%f', '%f', '%f', '%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error(
                'db_error',
                __('Failed to log cost adjustment.', 'myworks-qb-cost-adjuster')
            );
        }

        return true;
    }

    /**
     * Log sync operation to database
     * 
     * @param int $product_id The product ID
     * @param string $sync_type The type of sync operation
     * @param string $status The sync status
     * @param string $message Optional message
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function log_sync_operation($product_id, $sync_type, $status, $message = '') {
        global $wpdb;
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'mwqbca_sync_history',
            array(
                'product_id' => $product_id,
                'sync_type' => $sync_type,
                'status' => $status,
                'message' => $message,
                'synced_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error(
                'db_error',
                __('Failed to log sync operation.', 'myworks-qb-cost-adjuster')
            );
        }

        return true;
    }

    /**
     * Handle API rate limiting
     * 
     * @param string $endpoint The API endpoint being called
     * @return bool|WP_Error True if within limits, WP_Error on failure
     */
    public function handle_rate_limiting($endpoint) {
        $rate_limits = get_option('mwqbca_rate_limits', array());
        $current_time = time();
        
        // Default limits (requests per minute)
        $default_limits = array(
            'item' => 60,
            'inventory' => 30,
            'default' => 100
        );
        
        // Get limit for endpoint
        $limit = isset($default_limits[$endpoint]) ? $default_limits[$endpoint] : $default_limits['default'];
        
        // Initialize endpoint tracking if not exists
        if (!isset($rate_limits[$endpoint])) {
            $rate_limits[$endpoint] = array(
                'count' => 0,
                'window_start' => $current_time
            );
        }
        
        // Check if we're in a new time window
        if ($current_time - $rate_limits[$endpoint]['window_start'] >= 60) {
            $rate_limits[$endpoint] = array(
                'count' => 1,
                'window_start' => $current_time
            );
        } else {
            // Increment count
            $rate_limits[$endpoint]['count']++;
            
            // Check if we've exceeded the limit
            if ($rate_limits[$endpoint]['count'] > $limit) {
                $wait_time = 60 - ($current_time - $rate_limits[$endpoint]['window_start']);
                return new WP_Error(
                    'rate_limit_exceeded',
                    sprintf(
                        __('Rate limit exceeded. Please wait %d seconds before trying again.', 'myworks-qb-cost-adjuster'),
                        $wait_time
                    ),
                    array('wait_time' => $wait_time)
                );
            }
        }
        
        // Update rate limits
        update_option('mwqbca_rate_limits', $rate_limits);
        
        return true;
    }

    /**
     * Handle API retry
     * 
     * @param callable $api_call The API call function to retry
     * @param array $args The arguments for the API call
     * @param int $max_attempts Maximum number of retry attempts (default: 3)
     * @param int $delay Delay between retries in seconds (default: 2)
     * @return mixed API response or WP_Error on failure
     */
    public function handle_api_retry($api_call, $args = array(), $max_attempts = 3, $delay = 2) {
        $attempt = 1;
        $last_error = null;
        
        while ($attempt <= $max_attempts) {
            try {
                $result = call_user_func_array($api_call, $args);
                
                // Check if result is an error
                if (is_wp_error($result)) {
                    $last_error = $result;
                    
                    // Don't retry certain errors
                    if (in_array($result->get_error_code(), array('invalid_request', 'authentication_error'))) {
                        break;
                    }
                } else {
                    return $result;
                }
            } catch (Exception $e) {
                $last_error = new WP_Error(
                    'api_exception',
                    $e->getMessage()
                );
            }
            
            // Log retry attempt
            $this->handle_api_error(
                sprintf(
                    __('API call failed, attempt %d of %d', 'myworks-qb-cost-adjuster'),
                    $attempt,
                    $max_attempts
                ),
                array(
                    'error' => $last_error,
                    'attempt' => $attempt,
                    'max_attempts' => $max_attempts
                )
            );
            
            // Wait before retrying
            sleep($delay);
            $attempt++;
        }
        
        return $last_error;
    }

    /**
     * Refresh API authentication
     * 
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function refresh_api_authentication() {
        // Check if MyWorks Sync is active
        if (!class_exists('MyWorks_WC_QBO_Sync')) {
            return new WP_Error(
                'myworks_sync_not_active',
                __('MyWorks Sync for QuickBooks Online is not active.', 'myworks-qb-cost-adjuster')
            );
        }
        
        try {
            // Get MyWorks Sync instance
            $myworks_sync = MyWorks_WC_QBO_Sync::get_instance();
            
            // Check if we can access the authentication method
            if (!method_exists($myworks_sync, 'refresh_oauth_token')) {
                return new WP_Error(
                    'invalid_myworks_sync',
                    __('MyWorks Sync version is not compatible.', 'myworks-qb-cost-adjuster')
                );
            }
            
            // Attempt to refresh the token
            $result = $myworks_sync->refresh_oauth_token();
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            // Log successful refresh
            $this->handle_api_error(
                __('API authentication refreshed successfully', 'myworks-qb-cost-adjuster'),
                array('type' => 'success')
            );
            
            return true;
            
        } catch (Exception $e) {
            return new WP_Error(
                'refresh_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Make API request to QuickBooks
     * 
     * @param string $endpoint The API endpoint
     * @param array $data The data to send
     * @param string $method The HTTP method (default: POST)
     * @return mixed API response or WP_Error on failure
     */
    public function make_quickbooks_request($endpoint, $data = array(), $method = 'POST') {
        // Check rate limits
        $rate_check = $this->handle_rate_limiting($endpoint);
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }
        
        // Check authentication
        if (!$this->is_quickbooks_authenticated()) {
            $refresh_result = $this->refresh_api_authentication();
            if (is_wp_error($refresh_result)) {
                return $refresh_result;
            }
        }
        
        // Prepare request
        $request = array(
            'endpoint' => $endpoint,
            'method' => $method,
            'data' => $data
        );
        
        // Make the request with retry logic
        return $this->handle_api_retry(
            array($this, 'execute_quickbooks_request'),
            array($request)
        );
    }

    /**
     * Execute QuickBooks API request
     * 
     * @param array $request The request data
     * @return mixed API response or WP_Error on failure
     */
    private function execute_quickbooks_request($request) {
        // Check if MyWorks Sync is active
        if (!class_exists('MyWorks_WC_QBO_Sync')) {
            return new WP_Error(
                'myworks_sync_not_active',
                __('MyWorks Sync for QuickBooks Online is not active.', 'myworks-qb-cost-adjuster')
            );
        }
        
        try {
            // Get MyWorks Sync instance
            $myworks_sync = MyWorks_WC_QBO_Sync::get_instance();
            
            // Make the request
            $response = $myworks_sync->make_api_request(
                $request['endpoint'],
                $request['data'],
                $request['method']
            );
            
            // Validate response
            $validation = $this->validate_quickbooks_response($response);
            if (is_wp_error($validation)) {
                return $validation;
            }
            
            return $response;
            
        } catch (Exception $e) {
            return new WP_Error(
                'api_request_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Check if QuickBooks is authenticated
     * 
     * @return bool True if authenticated
     */
    private function is_quickbooks_authenticated() {
        // Check if MyWorks Sync is active
        if (!class_exists('MyWorks_WC_QBO_Sync')) {
            return false;
        }
        
        try {
            // Get MyWorks Sync instance
            $myworks_sync = MyWorks_WC_QBO_Sync::get_instance();
            
            // Check if we can access the authentication check method
            if (!method_exists($myworks_sync, 'is_authenticated')) {
                return false;
            }
            
            return $myworks_sync->is_authenticated();
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Rotate logs
     * 
     * @param string $log_type The type of log to rotate
     * @param int $max_size Maximum size in bytes before rotation (default: 5MB)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function rotate_logs($log_type, $max_size = 5242880) {
        $log_dir = WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/';
        $log_file = $log_dir . $log_type . '.log';
        
        // Check if log file exists
        if (!file_exists($log_file)) {
            return true;
        }
        
        // Check if rotation is needed
        if (filesize($log_file) < $max_size) {
            return true;
        }
        
        try {
            // Generate timestamp for rotated file
            $timestamp = date('Y-m-d-H-i-s');
            $rotated_file = $log_dir . $log_type . '-' . $timestamp . '.log';
            
            // Rename current log file
            if (!rename($log_file, $rotated_file)) {
                throw new Exception('Failed to rename log file');
            }
            
            // Create new empty log file
            if (!touch($log_file)) {
                throw new Exception('Failed to create new log file');
            }
            
            // Set proper permissions
            chmod($log_file, 0644);
            
            // Log rotation event
            $this->handle_database_error(
                sprintf(
                    __('Log file %s rotated to %s', 'myworks-qb-cost-adjuster'),
                    $log_type,
                    basename($rotated_file)
                ),
                array(
                    'log_type' => $log_type,
                    'original_size' => filesize($rotated_file),
                    'timestamp' => $timestamp
                )
            );
            
            return true;
            
        } catch (Exception $e) {
            return new WP_Error(
                'log_rotation_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Compress logs
     * 
     * @param string $log_type The type of log to compress
     * @param int $days_old Minimum age in days to compress (default: 7)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function compress_logs($log_type, $days_old = 7) {
        $log_dir = WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/';
        $cutoff_time = strtotime("-{$days_old} days");
        
        try {
            // Get all log files of this type
            $pattern = $log_dir . $log_type . '-*.log';
            $files = glob($pattern);
            
            if (empty($files)) {
                return true;
            }
            
            $compressed_count = 0;
            foreach ($files as $file) {
                // Skip if file is too new
                if (filemtime($file) > $cutoff_time) {
                    continue;
                }
                
                // Skip if already compressed
                if (substr($file, -4) === '.gz') {
                    continue;
                }
                
                // Compress the file
                $gz_file = $file . '.gz';
                $fp = fopen($file, 'rb');
                $gz = gzopen($gz_file, 'wb9');
                
                if (!$fp || !$gz) {
                    throw new Exception("Failed to open files for compression: $file");
                }
                
                // Copy content to compressed file
                while (!feof($fp)) {
                    gzwrite($gz, fread($fp, 4096));
                }
                
                fclose($fp);
                gzclose($gz);
                
                // Remove original file
                unlink($file);
                $compressed_count++;
            }
            
            // Log compression results
            if ($compressed_count > 0) {
                $this->handle_database_error(
                    sprintf(
                        __('Compressed %d log files of type %s', 'myworks-qb-cost-adjuster'),
                        $compressed_count,
                        $log_type
                    ),
                    array(
                        'log_type' => $log_type,
                        'compressed_count' => $compressed_count,
                        'days_old' => $days_old
                    )
                );
            }
            
            return true;
            
        } catch (Exception $e) {
            return new WP_Error(
                'log_compression_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Export logs
     * 
     * @param string $format The export format (csv, json, xml)
     * @param array $filters Optional filters for log data
     * @return string|WP_Error Exported data or WP_Error on failure
     */
    public function export_logs($format = 'csv', $filters = array()) {
        try {
            // Get log data
            $log_data = $this->get_log_data_for_export($filters);
            
            if (empty($log_data)) {
                return new WP_Error(
                    'no_log_data',
                    __('No log data found for export.', 'myworks-qb-cost-adjuster')
                );
            }
            
            // Export based on format
            switch ($format) {
                case 'json':
                    return json_encode($log_data, JSON_PRETTY_PRINT);
                
                case 'xml':
                    return $this->array_to_xml($log_data);
                
                case 'csv':
                default:
                    return $this->array_to_csv($log_data);
            }
            
        } catch (Exception $e) {
            return new WP_Error(
                'export_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Get log data for export
     * 
     * @param array $filters Optional filters
     * @return array Log data
     */
    private function get_log_data_for_export($filters = array()) {
        $log_types = array(
            'sync' => get_option('mwqbca_sync_logs', array()),
            'api' => get_option('mwqbca_api_logs', array()),
            'error' => get_option('mwqbca_error_logs', array())
        );
        
        $export_data = array();
        
        foreach ($log_types as $type => $logs) {
            if (empty($logs)) {
                continue;
            }
            
            // Apply filters
            if (!empty($filters)) {
                $logs = array_filter($logs, function($log) use ($filters) {
                    foreach ($filters as $key => $value) {
                        if (!isset($log[$key]) || $log[$key] != $value) {
                            return false;
                        }
                    }
                    return true;
                });
            }
            
            // Add type to each log entry
            foreach ($logs as $log) {
                $log['log_type'] = $type;
                $export_data[] = $log;
            }
        }
        
        // Sort by timestamp
        usort($export_data, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        return $export_data;
    }

    /**
     * Convert array to CSV
     * 
     * @param array $data The data to convert
     * @return string CSV data
     */
    private function array_to_csv($data) {
        if (empty($data)) {
            return '';
        }
        
        // Get headers from first row
        $headers = array_keys($data[0]);
        
        // Start output buffer
        ob_start();
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write headers
        fputcsv($output, $headers);
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, array_map(function($field) {
                return is_array($field) ? json_encode($field) : $field;
            }, $row));
        }
        
        // Close output stream
        fclose($output);
        
        // Get CSV data
        $csv = ob_get_clean();
        
        return $csv;
    }

    /**
     * Convert array to XML
     * 
     * @param array $data The data to convert
     * @return string XML data
     */
    private function array_to_xml($data) {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><logs></logs>');
        
        foreach ($data as $row) {
            $log = $xml->addChild('log');
            foreach ($row as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $log->addChild($key, htmlspecialchars($value));
            }
        }
        
        return $xml->asXML();
    }

    /**
     * Sync product to QuickBooks
     * 
     * @param int $product_id The product ID to sync
     * @param bool $force Whether to force sync regardless of last sync time
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function sync_product($product_id, $force = false) {
        // Check if product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error(
                'invalid_product',
                sprintf(__('Product #%d not found.', 'myworks-qb-cost-adjuster'), $product_id)
            );
        }

        // Check if sync is needed
        if (!$force) {
            $last_sync = get_post_meta($product_id, '_mwqbca_last_sync', true);
            if ($last_sync && (time() - strtotime($last_sync)) < 300) { // 5 minutes
                return new WP_Error(
                    'sync_throttled',
                    sprintf(__('Product #%d was synced recently. Please wait 5 minutes between syncs.', 'myworks-qb-cost-adjuster'), $product_id)
                );
            }
        }

        try {
            // Get product data
            $product_data = $this->get_product_data_for_sync($product);
            
            // Validate product data
            $validation = $this->validate_product_data($product_data);
            if (is_wp_error($validation)) {
                throw new Exception($validation->get_error_message());
            }

            // Make API request
            $response = $this->make_quickbooks_request('item', $product_data);
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            // Update sync status
            update_post_meta($product_id, '_mwqbca_last_sync', current_time('mysql'));
            update_post_meta($product_id, '_mwqbca_sync_status', 'success');
            delete_post_meta($product_id, '_mwqbca_sync_error');

            // Log successful sync
            $this->log_sync_operation(
                $product_id,
                'product',
                'success',
                sprintf(__('Product synced successfully to QuickBooks', 'myworks-qb-cost-adjuster'))
            );

            return true;

        } catch (Exception $e) {
            // Log sync error
            $this->handle_sync_error($e->getMessage(), $product_id);
            
            return new WP_Error(
                'sync_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Batch sync products
     * 
     * @param array $product_ids Array of product IDs to sync
     * @param bool $force Whether to force sync regardless of last sync time
     * @return array Results of sync operations
     */
    public function batch_sync_products($product_ids, $force = false) {
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );

        foreach ($product_ids as $product_id) {
            $sync_result = $this->sync_product($product_id, $force);
            
            if (is_wp_error($sync_result)) {
                $results['failed']++;
                $results['errors'][] = array(
                    'product_id' => $product_id,
                    'error' => $sync_result->get_error_message()
                );
            } else {
                $results['success']++;
            }

            // Add small delay between syncs to prevent rate limiting
            usleep(100000); // 100ms delay
        }

        // Log batch results
        $this->handle_database_error(
            sprintf(
                __('Batch sync completed: %d successful, %d failed', 'myworks-qb-cost-adjuster'),
                $results['success'],
                $results['failed']
            ),
            $results
        );

        return $results;
    }

    /**
     * Get product data for sync
     * 
     * @param WC_Product $product The product object
     * @return array Product data formatted for QuickBooks
     */
    private function get_product_data_for_sync($product) {
        // Get base product data
        $data = array(
            'Name' => $product->get_name(),
            'SKU' => $product->get_sku(),
            'Description' => $product->get_description(),
            'UnitPrice' => $product->get_regular_price(),
            'PurchaseCost' => $product->get_meta('_cost'),
            'Type' => 'Inventory',
            'TrackQtyOnHand' => true,
            'QtyOnHand' => $product->get_stock_quantity()
        );

        // Add variation data if applicable
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $data['Name'] = $parent->get_name() . ' - ' . $product->get_name();
            }
        }

        // Apply cost adjustment
        $multiplier = get_option('qb_cost_multiplier', 1.65);
        if (isset($data['PurchaseCost']) && $data['PurchaseCost'] > 0) {
            $data['PurchaseCost'] = round($data['PurchaseCost'] * $multiplier, 2);
        }

        // Allow other plugins to modify the data
        return apply_filters('mwqbca_product_data_before_sync', $data, $product);
    }

    /**
     * Schedule sync for product
     * 
     * @param int $product_id The product ID to schedule
     * @param int $delay Delay in seconds before sync (default: 0)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function schedule_sync($product_id, $delay = 0) {
        // Check if product exists
        if (!wc_get_product($product_id)) {
            return new WP_Error(
                'invalid_product',
                sprintf(__('Product #%d not found.', 'myworks-qb-cost-adjuster'), $product_id)
            );
        }

        // Calculate timestamp
        $timestamp = time() + $delay;

        // Schedule the sync
        $scheduled = wp_schedule_single_event(
            $timestamp,
            'mwqbca_sync_product',
            array($product_id)
        );

        if (!$scheduled) {
            return new WP_Error(
                'schedule_failed',
                sprintf(__('Failed to schedule sync for product #%d', 'myworks-qb-cost-adjuster'), $product_id)
            );
        }

        // Log scheduled sync
        $this->log_sync_operation(
            $product_id,
            'scheduled',
            'pending',
            sprintf(__('Sync scheduled for %s', 'myworks-qb-cost-adjuster'), date('Y-m-d H:i:s', $timestamp))
        );

        return true;
    }

    /**
     * Cancel scheduled sync
     * 
     * @param int $product_id The product ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function cancel_scheduled_sync($product_id) {
        // Get scheduled event
        $timestamp = wp_next_scheduled('mwqbca_sync_product', array($product_id));
        
        if (!$timestamp) {
            return new WP_Error(
                'no_scheduled_sync',
                sprintf(__('No scheduled sync found for product #%d', 'myworks-qb-cost-adjuster'), $product_id)
            );
        }

        // Cancel the event
        $cancelled = wp_unschedule_event($timestamp, 'mwqbca_sync_product', array($product_id));

        if (!$cancelled) {
            return new WP_Error(
                'cancel_failed',
                sprintf(__('Failed to cancel scheduled sync for product #%d', 'myworks-qb-cost-adjuster'), $product_id)
            );
        }

        // Log cancellation
        $this->log_sync_operation(
            $product_id,
            'scheduled',
            'cancelled',
            __('Scheduled sync cancelled', 'myworks-qb-cost-adjuster')
        );

        return true;
    }

    /**
     * Get sync status for product
     * 
     * @param int $product_id The product ID
     * @return array Sync status information
     */
    public function get_sync_status($product_id) {
        $status = array(
            'last_sync' => get_post_meta($product_id, '_mwqbca_last_sync', true),
            'sync_status' => get_post_meta($product_id, '_mwqbca_sync_status', true),
            'sync_error' => get_post_meta($product_id, '_mwqbca_sync_error', true),
            'next_scheduled' => wp_next_scheduled('mwqbca_sync_product', array($product_id))
        );

        // Get sync history
        global $wpdb;
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mwqbca_sync_history 
            WHERE product_id = %d 
            ORDER BY synced_at DESC 
            LIMIT 5",
            $product_id
        ));

        $status['recent_history'] = $history;

        return $status;
    }

    /**
     * Register admin scripts and styles
     */
    public function register_admin_assets() {
        // Debug log
        error_log('QB Cost Adjuster - Registering admin assets');
        
        // Register admin CSS
        wp_register_style(
            'mwqbca-admin-style',
            MWQBCA_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            MWQBCA_VERSION
        );
        
        // Register admin JavaScript
        wp_register_script(
            'mwqbca-admin-script',
            MWQBCA_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            MWQBCA_VERSION,
            true
        );
        
        // Create nonce
        $nonce = wp_create_nonce('mwqbca_admin_nonce');
        error_log('QB Cost Adjuster - Created nonce: ' . $nonce);
        
        // Localize the script with necessary data
        wp_localize_script(
            'mwqbca-admin-script',
            'mwqbcaAdmin',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => $nonce,
                'i18n' => array(
                    'error' => __('An error occurred while processing your request.', 'myworks-qb-cost-adjuster'),
                    'confirm' => __('Are you sure you want to proceed?', 'myworks-qb-cost-adjuster')
                )
            )
        );
        
        // Debug log
        error_log('QB Cost Adjuster - Admin assets registered');
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        // Debug log
        error_log('QB Cost Adjuster - Enqueuing admin assets on hook: ' . $hook);
        
        // Only enqueue on our plugin pages
        if (strpos($hook, 'myworks-qb-cost-adjuster') === false) {
            error_log('QB Cost Adjuster - Not our plugin page, skipping asset enqueue');
            return;
        }

        // Enqueue styles
        wp_enqueue_style('mwqbca-admin-style');
        
        // Enqueue scripts
        wp_enqueue_script('mwqbca-admin-script');
        
        // Debug log
        error_log('QB Cost Adjuster - Admin assets enqueued');
    }

    /**
     * Add admin notices
     */
    public function add_admin_notices() {
        // Check if MyWorks Sync is active
        if (!$this->is_myworks_sync_active()) {
            $this->render_admin_notice(
                'error',
                __('MyWorks Sync for QuickBooks Online is required for this plugin to work.', 'myworks-qb-cost-adjuster')
            );
            return;
        }

        // Check if plugin is configured
        if (!get_option('mwqbca_enabled')) {
            $this->render_admin_notice(
                'warning',
                sprintf(
                    __('Please %sconfigure%s the QuickBooks Cost Adjuster plugin.', 'myworks-qb-cost-adjuster'),
                    '<a href="' . admin_url('admin.php?page=myworks-qb-cost-adjuster') . '">',
                    '</a>'
                )
            );
        }

        // Check for sync errors
        $error_count = $this->get_sync_error_count();
        if ($error_count > 0) {
            $this->render_admin_notice(
                'error',
                sprintf(
                    __('There are %d sync errors. Please check the %slogs%s for details.', 'myworks-qb-cost-adjuster'),
                    $error_count,
                    '<a href="' . admin_url('admin.php?page=myworks-qb-cost-adjuster-logs') . '">',
                    '</a>'
                )
            );
        }
    }

    /**
     * Render admin notice
     * 
     * @param string $type Notice type (error, warning, success, info)
     * @param string $message Notice message
     */
    private function render_admin_notice($type, $message) {
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
            <p><?php echo wp_kses_post($message); ?></p>
        </div>
        <?php
    }

    /**
     * Get sync error count
     * 
     * @return int Number of sync errors
     */
    private function get_sync_error_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mwqbca_sync_history 
            WHERE status = 'error' 
            AND synced_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
    }

    /**
     * Add product meta box
     */
    public function add_product_meta_box() {
        add_meta_box(
            'mwqbca-sync-box',
            __('QuickBooks Sync', 'myworks-qb-cost-adjuster'),
            array($this, 'render_product_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render product meta box
     * 
     * @param WP_Post $post The post object
     */
    public function render_product_meta_box($post) {
        // Get sync status
        $status = $this->get_sync_status($post->ID);
        
        // Add nonce for security
        wp_nonce_field('mwqbca_product_meta_box', 'mwqbca_product_meta_box_nonce');
        ?>
        <div class="mwqbca-sync-status">
            <?php if ($status['last_sync']): ?>
                <p>
                    <strong><?php _e('Last Sync:', 'myworks-qb-cost-adjuster'); ?></strong>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status['last_sync']))); ?>
                </p>
            <?php endif; ?>

            <?php if ($status['sync_error']): ?>
                <p class="error">
                    <strong><?php _e('Error:', 'myworks-qb-cost-adjuster'); ?></strong>
                    <?php echo esc_html($status['sync_error']); ?>
                </p>
            <?php endif; ?>

            <?php if ($status['next_scheduled']): ?>
                <p>
                    <strong><?php _e('Next Sync:', 'myworks-qb-cost-adjuster'); ?></strong>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $status['next_scheduled'])); ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="mwqbca-sync-actions">
            <button type="button" class="button mwqbca-sync-now" data-product-id="<?php echo esc_attr($post->ID); ?>">
                <?php _e('Sync Now', 'myworks-qb-cost-adjuster'); ?>
            </button>
            <button type="button" class="button mwqbca-schedule-sync" data-product-id="<?php echo esc_attr($post->ID); ?>">
                <?php _e('Schedule Sync', 'myworks-qb-cost-adjuster'); ?>
            </button>
        </div>

        <div class="mwqbca-sync-history">
            <?php if (!empty($status['recent_history'])): ?>
                <h4><?php _e('Recent Sync History', 'myworks-qb-cost-adjuster'); ?></h4>
                <ul>
                    <?php foreach ($status['recent_history'] as $history): ?>
                        <li>
                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($history->synced_at))); ?>
                            - <?php echo esc_html($history->status); ?>
                            <?php if ($history->message): ?>
                                <br><small><?php echo esc_html($history->message); ?></small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Add bulk actions
     */
    public function add_bulk_actions() {
        global $post_type;
        
        if ($post_type === 'product') {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('<option>').val('sync_to_quickbooks').text('<?php _e('Sync to QuickBooks', 'myworks-qb-cost-adjuster'); ?>').appendTo("select[name='action']");
                    $('<option>').val('sync_to_quickbooks').text('<?php _e('Sync to QuickBooks', 'myworks-qb-cost-adjuster'); ?>').appendTo("select[name='action2']");
                });
            </script>
            <?php
        }
    }

    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions() {
        $wp_list_table = _get_list_table('WP_Posts_List_Table');
        $action = $wp_list_table->current_action();

        if ($action !== 'sync_to_quickbooks') {
            return;
        }

        // Check nonce
        check_admin_referer('bulk-posts');

        // Get selected posts
        $post_ids = isset($_REQUEST['post']) ? array_map('intval', $_REQUEST['post']) : array();
        
        if (empty($post_ids)) {
            return;
        }

        // Perform sync
        $results = $this->batch_sync_products($post_ids);

        // Set admin notice
        $message = sprintf(
            __('Synced %d products to QuickBooks (%d successful, %d failed)', 'myworks-qb-cost-adjuster'),
            count($post_ids),
            $results['success'],
            $results['failed']
        );

        // Add error details if any
        if (!empty($results['errors'])) {
            $message .= '<br><br>' . __('Errors:', 'myworks-qb-cost-adjuster') . '<br>';
            foreach ($results['errors'] as $error) {
                $message .= sprintf(
                    __('Product #%d: %s', 'myworks-qb-cost-adjuster'),
                    $error['product_id'],
                    $error['error']
                ) . '<br>';
            }
        }

        // Store message in transient
        set_transient('mwqbca_bulk_action_message', $message, 45);

        // Redirect to prevent resubmission
        wp_redirect(add_query_arg('bulk_action', 'sync_to_quickbooks', wp_get_referer()));
        exit;
    }

    /**
     * Display bulk action messages
     */
    public function display_bulk_action_messages() {
        $message = get_transient('mwqbca_bulk_action_message');
        
        if ($message) {
            $this->render_admin_notice('info', $message);
            delete_transient('mwqbca_bulk_action_message');
        }
    }

    /**
     * Register AJAX handlers
     */
    public function register_ajax_handlers() {
        // Product sync actions
        add_action('wp_ajax_mwqbca_sync_product', array($this, 'ajax_sync_product'));
        add_action('wp_ajax_mwqbca_schedule_sync', array($this, 'ajax_schedule_sync'));
        add_action('wp_ajax_mwqbca_cancel_sync', array($this, 'ajax_cancel_sync'));
        add_action('wp_ajax_mwqbca_force_update_all', array($this, 'ajax_force_update_all'));
        
        // Log actions
        add_action('wp_ajax_mwqbca_get_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_mwqbca_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_mwqbca_export_logs', array($this, 'ajax_export_logs'));
        
        // Settings actions
        add_action('wp_ajax_mwqbca_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_mwqbca_test_connection', array($this, 'ajax_test_connection'));
    }

    /**
     * Handle product sync AJAX request
     */
    public function ajax_sync_product() {
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster'));
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(__('Invalid product ID.', 'myworks-qb-cost-adjuster'));
        }

        // Perform sync
        $result = $this->sync_product($product_id, true);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Get updated status
        $status = $this->get_sync_status($product_id);
        
        wp_send_json_success(array(
            'message' => __('Product synced successfully.', 'myworks-qb-cost-adjuster'),
            'status' => $status
        ));
    }

    /**
     * Handle schedule sync AJAX request
     */
    public function ajax_schedule_sync() {
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster'));
        }

        // Get parameters
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $delay = isset($_POST['delay']) ? intval($_POST['delay']) : 0;

        if (!$product_id) {
            wp_send_json_error(__('Invalid product ID.', 'myworks-qb-cost-adjuster'));
        }

        // Schedule sync
        $result = $this->schedule_sync($product_id, $delay);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Get updated status
        $status = $this->get_sync_status($product_id);
        
        wp_send_json_success(array(
            'message' => __('Sync scheduled successfully.', 'myworks-qb-cost-adjuster'),
            'status' => $status
        ));
    }

    /**
     * Handle cancel sync AJAX request
     */
    public function ajax_cancel_sync() {
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster'));
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(__('Invalid product ID.', 'myworks-qb-cost-adjuster'));
        }

        // Cancel sync
        $result = $this->cancel_scheduled_sync($product_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Get updated status
        $status = $this->get_sync_status($product_id);
        
        wp_send_json_success(array(
            'message' => __('Scheduled sync cancelled.', 'myworks-qb-cost-adjuster'),
            'status' => $status
        ));
    }

    /**
     * Handle get logs AJAX request
     */
    public function ajax_get_logs() {
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster'));
        }

        // Get parameters
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
        $filters = isset($_POST['filters']) ? $this->sanitize_input($_POST['filters']) : array();

        // Get logs
        $logs = $this->get_logs_for_display($type, $page, $per_page, $filters);
        
        wp_send_json_success($logs);
    }

    /**
     * Handle clear logs AJAX request
     */
    public function ajax_clear_logs() {
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster'));
        }

        // Get parameters
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $days = isset($_POST['days']) ? intval($_POST['days']) : 30;

        // Clear logs
        $result = $this->cleanup_old_logs($days);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Cleared logs older than %d days.', 'myworks-qb-cost-adjuster'),
                $days
            )
        ));
    }

    /**
     * Handle export logs AJAX request
     */
    public function ajax_export_logs() {
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster'));
        }

        // Get parameters
        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'csv';
        $filters = isset($_POST['filters']) ? $this->sanitize_input($_POST['filters']) : array();

        // Export logs
        $result = $this->export_logs($format, $filters);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Set headers for download
        $filename = 'quickbooks-sync-logs-' . date('Y-m-d') . '.' . $format;
        header('Content-Type: ' . $this->get_export_content_type($format));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $result;
        exit;
    }

    /**
     * Handle save settings AJAX request
     */
    public function ajax_save_settings() {
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster'));
        }

        // Get settings
        $settings = isset($_POST['settings']) ? $this->sanitize_input($_POST['settings']) : array();
        
        if (empty($settings)) {
            wp_send_json_error(__('No settings provided.', 'myworks-qb-cost-adjuster'));
        }

        // Save settings
        foreach ($settings as $key => $value) {
            update_option('mwqbca_' . $key, $value);
        }

        wp_send_json_success(array(
            'message' => __('Settings saved successfully.', 'myworks-qb-cost-adjuster')
        ));
    }

    /**
     * Handle test connection AJAX request
     */
    public function ajax_test_connection() {
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster'));
        }

        // Test connection
        if (!$this->is_quickbooks_authenticated()) {
            wp_send_json_error(__('Not connected to QuickBooks.', 'myworks-qb-cost-adjuster'));
        }

        // Try to refresh authentication
        $result = $this->refresh_api_authentication();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => __('Successfully connected to QuickBooks.', 'myworks-qb-cost-adjuster')
        ));
    }

    /**
     * Get logs for display
     * 
     * @param string $type Log type
     * @param int $page Page number
     * @param int $per_page Items per page
     * @param array $filters Optional filters
     * @return array Log data with pagination
     */
    private function get_logs_for_display($type, $page, $per_page, $filters = array()) {
        global $wpdb;
        
        // Build query
        $query = "SELECT * FROM {$wpdb->prefix}mwqbca_sync_history WHERE 1=1";
        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}mwqbca_sync_history WHERE 1=1";
        
        // Add type filter
        if ($type) {
            $query .= $wpdb->prepare(" AND sync_type = %s", $type);
            $count_query .= $wpdb->prepare(" AND sync_type = %s", $type);
        }
        
        // Add custom filters
        foreach ($filters as $key => $value) {
            if ($value) {
                $query .= $wpdb->prepare(" AND {$key} = %s", $value);
                $count_query .= $wpdb->prepare(" AND {$key} = %s", $value);
            }
        }
        
        // Add pagination
        $offset = ($page - 1) * $per_page;
        $query .= " ORDER BY synced_at DESC LIMIT %d OFFSET %d";
        $query = $wpdb->prepare($query, $per_page, $offset);
        
        // Get results
        $logs = $wpdb->get_results($query);
        $total = $wpdb->get_var($count_query);
        
        return array(
            'logs' => $logs,
            'pagination' => array(
                'total' => (int) $total,
                'per_page' => $per_page,
                'current_page' => $page,
                'total_pages' => ceil($total / $per_page)
            )
        );
    }

    /**
     * Get export content type
     * 
     * @param string $format Export format
     * @return string Content type
     */
    private function get_export_content_type($format) {
        switch ($format) {
            case 'csv':
                return 'text/csv';
            case 'json':
                return 'application/json';
            case 'xml':
                return 'application/xml';
            default:
                return 'text/plain';
        }
    }

    /**
     * Handle force update all products AJAX request
     */
    public function ajax_force_update_all() {
        // Debug log
        error_log('QB Cost Adjuster - Force update AJAX request received');
        error_log('POST data: ' . print_r($_POST, true));
        
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            error_log('QB Cost Adjuster - Permission check failed');
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster')));
            return;
        }

        // Reset progress data
        $progress_data = array(
            'total' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'recent_logs' => array(),
            'complete' => false
        );

        // Store progress data in a transient to persist between requests
        set_transient('mwqbca_progress_data', $progress_data, 3600); // 1 hour expiration

        // Get all published products
        $product_ids = get_posts(array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1
        ));

        if (empty($product_ids)) {
            error_log('QB Cost Adjuster - No products found to update');
            wp_send_json_error(array('message' => __('No products found to update.', 'myworks-qb-cost-adjuster')));
            return;
        }

        $progress_data['total'] = count($product_ids);
        error_log('QB Cost Adjuster - Found ' . $progress_data['total'] . ' products to process');
        
        // Update the progress data
        set_transient('mwqbca_progress_data', $progress_data, 3600);
        
        // We'll process the first batch now and let AJAX polling handle the rest
        $batch_size = 25; // Increased from 5 to 25 for faster initial processing
        $first_batch = array_slice($product_ids, 0, $batch_size);
        
        foreach ($first_batch as $product_id) {
            try {
                $product = wc_get_product($product_id);
                if (!$product) {
                    throw new Exception('Invalid product ID: ' . $product_id);
                }

                $progress_data = get_transient('mwqbca_progress_data');
                $progress_data['processed']++;
                
                if ($product->is_type('variable')) {
                    $variations = $product->get_children();
                    foreach ($variations as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation) {
                            $this->update_product_cost($variation);
                        }
                    }
                } else {
                    $this->update_product_cost($product);
                }
                
                $progress_data['success']++;
                $this->add_progress_log_to_transient($product->get_name(), 'Cost updated successfully', 'success');
                
                // Update progress data
                set_transient('mwqbca_progress_data', $progress_data, 3600);
                
            } catch (Exception $e) {
                error_log('QB Cost Adjuster - Error processing product ' . $product_id . ': ' . $e->getMessage());
                
                $progress_data = get_transient('mwqbca_progress_data');
                $progress_data['failed']++;
                $product_name = isset($product) && $product ? $product->get_name() : 'Product #' . $product_id;
                $this->add_progress_log_to_transient($product_name, $e->getMessage(), 'error');
                
                // Update progress data
                set_transient('mwqbca_progress_data', $progress_data, 3600);
            }
        }
        
        // Store the remaining products to process
        update_option('mwqbca_remaining_products', array_slice($product_ids, $batch_size));
        
        // Get the latest progress data
        $progress_data = get_transient('mwqbca_progress_data');
        
        // Prepare response message
        $message = sprintf(
            __('Processing %d products. Started with %d products. Check progress for updates.', 'myworks-qb-cost-adjuster'),
            $progress_data['total'],
            count($first_batch)
        );
        
        error_log('QB Cost Adjuster - Initial batch processed. Response: ' . $message);
        
        // Include the progress data in the response
        $response_data = array(
            'message' => $message,
            'total' => $progress_data['total'],
            'processed' => $progress_data['processed'],
            'success' => $progress_data['success'],
            'failed' => $progress_data['failed'],
            'recent_logs' => $progress_data['recent_logs'],
            'complete' => $progress_data['complete']
        );
        
        wp_send_json_success($response_data);
    }

    /**
     * Add log entry to the progress data transient
     */
    private function add_progress_log_to_transient($product_name, $message, $status) {
        $progress_data = get_transient('mwqbca_progress_data');
        if (!$progress_data) {
            $progress_data = array(
                'total' => 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'recent_logs' => array(),
                'complete' => false
            );
        }
        
        $log_entry = array(
            'timestamp' => current_time('H:i:s'),
            'product_name' => $product_name,
            'message' => $message,
            'status' => $status
        );
        
        array_unshift($progress_data['recent_logs'], $log_entry);
        
        // Keep only the last 50 log entries
        if (count($progress_data['recent_logs']) > 50) {
            $progress_data['recent_logs'] = array_slice($progress_data['recent_logs'], 0, 50);
        }
        
        // Update progress data
        set_transient('mwqbca_progress_data', $progress_data, 3600);
    }

    /**
     * Add cost update to the progress log if available
     * 
     * @param WC_Product $product The product being updated
     * @param float $new_cost The new cost value
     * @param float $old_qb_cost Old QuickBooks cost
     * @param float $old_cog_cost Old Cost of Goods cost
     */
    private function add_cost_update_to_log($product, $new_cost, $old_qb_cost, $old_cog_cost) {
        $log_message = sprintf(
            'Cost updated from QB:%s/COG:%s to %s',
            $old_qb_cost ? number_format($old_qb_cost, 2) : 'not set',
            $old_cog_cost ? number_format($old_cog_cost, 2) : 'not set', 
            number_format($new_cost, 2)
        );
        
        $this->add_progress_log_to_transient($product->get_name(), $log_message, 'success');
    }

    /**
     * Handle get progress AJAX request
     */
    public function ajax_get_progress() {
        // Debug log
        error_log('QB Cost Adjuster - Progress check AJAX request received');
        
        // Verify nonce
        check_ajax_referer('mwqbca_admin_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'myworks-qb-cost-adjuster')));
            return;
        }

        // Get current progress data from transient
        $progress_data = get_transient('mwqbca_progress_data');
        if (!$progress_data) {
            $progress_data = array(
                'total' => 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'recent_logs' => array(),
                'complete' => false
            );
            error_log('QB Cost Adjuster - No progress data found in transient');
        }
        
        // Process more products if there are any remaining
        $remaining_products = get_option('mwqbca_remaining_products', array());
        
        if (!empty($remaining_products)) {
            $batch_size = 50; // Increased from 10 to 50 for faster processing
            $batch = array_slice($remaining_products, 0, $batch_size);
            
            foreach ($batch as $product_id) {
                try {
                    $product = wc_get_product($product_id);
                    if (!$product) {
                        throw new Exception('Invalid product ID: ' . $product_id);
                    }

                    $progress_data['processed']++;
                    
                    if ($product->is_type('variable')) {
                        $variations = $product->get_children();
                        foreach ($variations as $variation_id) {
                            $variation = wc_get_product($variation_id);
                            if ($variation) {
                                $this->update_product_cost($variation);
                            }
                        }
                    } else {
                        $this->update_product_cost($product);
                    }
                    
                    $progress_data['success']++;
                    $this->add_progress_log_to_transient($product->get_name(), 'Cost updated successfully', 'success');
                    
                } catch (Exception $e) {
                    $progress_data['failed']++;
                    $product_name = isset($product) && $product ? $product->get_name() : 'Product #' . $product_id;
                    $this->add_progress_log_to_transient($product_name, $e->getMessage(), 'error');
                }
                
                // Update progress data after each product to show real-time progress
                set_transient('mwqbca_progress_data', $progress_data, 3600);
            }
            
            // Update remaining products
            $remaining = array_slice($remaining_products, $batch_size);
            update_option('mwqbca_remaining_products', $remaining);
            
            // If we've processed all products, mark as complete
            if (empty($remaining)) {
                $progress_data['complete'] = true;
                set_transient('mwqbca_progress_data', $progress_data, 3600);
            }
        } else {
            // If there are no remaining products but we haven't marked as complete
            if (!$progress_data['complete'] && $progress_data['processed'] >= $progress_data['total']) {
                $progress_data['complete'] = true;
                set_transient('mwqbca_progress_data', $progress_data, 3600);
            }
        }
        
        // Debug output of progress data
        error_log('QB Cost Adjuster - Progress data: ' . print_r($progress_data, true));
        
        // Send progress data
        wp_send_json_success($progress_data);
    }

    /**
     * Update product cost
     */
    private function update_product_cost($product) {
        $regular_price = $product->get_regular_price();
        if (!$regular_price) {
            throw new Exception('No regular price set');
        }

        // Get settings using the new format
        $settings = get_option('mwqbca_settings', array('cost_multiplier' => '1.65'));
        $multiplier = floatval($settings['cost_multiplier']);
        if (!$multiplier) {
            throw new Exception('Cost multiplier not set or invalid');
        }

        // Calculate cost based on regular price divided by multiplier
        $cost = $regular_price / $multiplier;
        $cost = round($cost, 2); // Round to 2 decimal places
        
        // Get old costs for logging
        $old_qb_cost = $product->get_meta('_qb_p_cost');
        $old_cog_cost = $product->get_meta('_wc_cog_cost');
        $old_tracking_cost = $product->get_meta('_mwqbca_cost');
        $old_purchase_cost = $product->get_meta('_purchase_cost');
        
        // Update the QuickBooks cost meta field and tracking costs
        $product->update_meta_data('_qb_p_cost', $cost); // QuickBooks cost meta key
        $product->update_meta_data('_mwqbca_cost', $cost); // Our tracking cost
        $product->update_meta_data('_purchase_cost', $cost); // Standard WooCommerce cost
        
        // Update WooCommerce Cost of Goods plugin cost field
        $product->update_meta_data('_wc_cog_cost', $cost); // WooCommerce Cost of Goods plugin
        
        // Save changes
        $product->save();
        
        // Build enhanced log message
        $log_message = sprintf(
            'QB Cost Adjuster - Updated costs for product #%d (%s)' . PHP_EOL .
            'Regular price: %s' . PHP_EOL .
            'Multiplier: %s' . PHP_EOL .
            'Calculated cost: %s' . PHP_EOL .
            'Previous costs:' . PHP_EOL .
            '  - QuickBooks (_qb_p_cost): %s' . PHP_EOL .
            '  - WC Cost of Goods (_wc_cog_cost): %s' . PHP_EOL .
            '  - Tracking (_mwqbca_cost): %s' . PHP_EOL .
            '  - Purchase (_purchase_cost): %s',
            $product->get_id(),
            $product->get_name(),
            $regular_price,
            $multiplier,
            $cost,
            $old_qb_cost ? $old_qb_cost : 'not set',
            $old_cog_cost ? $old_cog_cost : 'not set',
            $old_tracking_cost ? $old_tracking_cost : 'not set',
            $old_purchase_cost ? $old_purchase_cost : 'not set'
        );
        
        // Log the cost update with enhanced information
        error_log($log_message);
        
        // Add to progress log if we're in the middle of a bulk update
        $this->add_cost_update_to_log($product, $cost, $old_qb_cost, $old_cog_cost);
        
        return $cost;
    }

    public function debug_output() {
        $screen = get_current_screen();
        if (strpos($screen->id, 'myworks-qb-cost-adjuster') === false) {
            return;
        }
        ?>
        <script type="text/javascript">
            console.log('QB Cost Adjuster Debug:');
            console.log('AJAX URL:', '<?php echo esc_js(admin_url('admin-ajax.php')); ?>');
            console.log('Nonce:', '<?php echo esc_js(wp_create_nonce('mwqbca_admin_nonce')); ?>');
            console.log('Admin Object:', mwqbcaAdmin);
        </script>
        <?php
    }
}

// Initialize the plugin
function MWQBCA() {
    return MyWorks_QB_Cost_Adjuster::get_instance();
}

// Start the plugin
MWQBCA();

// Debug logging function
function qb_cost_adjuster_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('QB Cost Adjuster: ' . $message);
    }
}

// Initialize plugin
function qb_cost_adjuster_init() {
    qb_cost_adjuster_log('Plugin initializing...');
    
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        qb_cost_adjuster_log('WooCommerce not found');
        return;
    }
    
    qb_cost_adjuster_log('WooCommerce found, proceeding with initialization');
    
    // Register settings (handled by MWQBCA_Settings, so this is not needed)
    // qb_cost_adjuster_register_settings();
    
    // Add menu items
//    add_action('admin_menu', 'qb_cost_adjuster_add_settings_page', 99);
    
    // Add settings link
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'qb_cost_adjuster_add_settings_link');
    
    qb_cost_adjuster_log('Plugin initialized successfully');
}
add_action('plugins_loaded', 'qb_cost_adjuster_init');

// Dummy function for compatibility; settings are handled by MWQBCA_Settings
if (!function_exists('qb_cost_adjuster_register_settings')) {
    function qb_cost_adjuster_register_settings() {
        // Settings are registered by MWQBCA_Settings. This is a stub to prevent fatal errors.
        return;
    }
} 

// Add settings link to plugins page
function qb_cost_adjuster_add_settings_link($links) {
    $settings_link = '<a href="admin.php?page=myworks-qb-cost-adjuster">' . __('Settings', 'myworks-qb-cost-adjuster') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
