<?php
/**
 * Settings page for MyWorks QuickBooks Cost Adjuster
 *
 * @package MyWorks_QB_Cost_Adjuster
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings class
 */
class MWQBCA_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Add action for custom saving if needed
        add_action('admin_post_mwqbca_save_settings', array($this, 'save_settings'));
        
        // Debug: Check option value when settings class is initialized
        $this->debug_check_option_value();
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register enabled setting
        register_setting(
            'mwqbca_settings_group',
            'mwqbca_enabled',
            array(
                'sanitize_callback' => array($this, 'sanitize_enabled'),
                'default' => 0,
            )
        );
        
        // Register multiplier setting
        register_setting(
            'mwqbca_settings_group',
            'qb_cost_multiplier',
            array(
                'sanitize_callback' => array($this, 'sanitize_multiplier'),
                'default' => '1.65',
            )
        );
    }
    
    /**
     * Sanitize enabled value
     * 
     * @param mixed $value The input value
     * @return int Sanitized value (1 for enabled, 0 for disabled)
     */
    public function sanitize_enabled($value) {
        // Log raw input value
        error_log('QB Cost Adjuster - sanitize_enabled raw input: ' . (is_null($value) ? 'NULL' : $value) . ' (type: ' . gettype($value) . ')');
        
        // Convert to integer value (1 or 0)
        $sanitized = !empty($value) ? 1 : 0;
        
        error_log('QB Cost Adjuster - sanitize_enabled result: ' . $sanitized);
        
        return $sanitized;
    }
    
    /**
     * Sanitize multiplier value
     * 
     * @param string $value The input value
     * @return string Sanitized value
     */
    public function sanitize_multiplier($value) {
        // Convert to float for validation
        $multiplier = floatval($value);
        
        // Ensure multiplier is a valid number
        if (!is_numeric($value)) {
            add_settings_error(
                'qb_cost_multiplier',
                'qb_cost_multiplier_not_numeric',
                __('Multiplier must be a valid number. Using default value instead.', 'myworks-qb-cost-adjuster'),
                'error'
            );
            return '1.65';
        }
        
        // Ensure multiplier is greater than minimum value (0.01)
        if ($multiplier <= 0.01) {
            add_settings_error(
                'qb_cost_multiplier',
                'qb_cost_multiplier_too_small',
                __('Multiplier must be greater than 0.01. Using default value instead.', 'myworks-qb-cost-adjuster'),
                'error'
            );
            return '1.65';
        }
        
        // Cap maximum value at 100 to prevent unreasonable values
        if ($multiplier > 100) {
            add_settings_error(
                'qb_cost_multiplier',
                'qb_cost_multiplier_too_large',
                __('Multiplier cannot exceed 100. Value has been capped.', 'myworks-qb-cost-adjuster'),
                'warning'
            );
            return '100.00';
        }
        
        // Return formatted value with 2 decimal places
        return number_format($multiplier, 2, '.', '');
    }
    
    /**
     * Custom save settings handler for direct form submission
     */
    public function save_settings() {
        // Check nonce for security
        if (!isset($_POST['mwqbca_settings_nonce']) || !wp_verify_nonce($_POST['mwqbca_settings_nonce'], 'mwqbca_save_settings')) {
            wp_die(__('Security check failed.', 'myworks-qb-cost-adjuster'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'myworks-qb-cost-adjuster'));
        }
        
        // Save enabled setting (1 for enabled, 0 for disabled)
        // Check if checkbox is present and checked
        $enabled = isset($_POST['mwqbca_enabled']) ? 1 : 0;
        
        // DEBUG: Log the checkbox value before saving
        error_log('QB Cost Adjuster - Saving enabled value: ' . $enabled);
        error_log('QB Cost Adjuster - Raw POST data: ' . print_r($_POST, true));
        
        // Make sure we're storing an integer value (1 or 0)
        update_option('mwqbca_enabled', (int)$enabled);
        
        // Verify the saved value
        $saved_value = get_option('mwqbca_enabled');
        error_log('QB Cost Adjuster - Saved enabled value in database: ' . $saved_value . ' (type: ' . gettype($saved_value) . ')');
        
        // Direct DB check to verify the raw value
        global $wpdb;
        $raw_db_value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'mwqbca_enabled'
        ));
        error_log('QB Cost Adjuster - Raw DB value after save: ' . $raw_db_value);
        
        // Save multiplier setting
        if (isset($_POST['qb_cost_multiplier'])) {
            $multiplier = $this->sanitize_multiplier($_POST['qb_cost_multiplier']);
            update_option('qb_cost_multiplier', $multiplier);
        }
        
        // Add success message
        add_settings_error(
            'mwqbca_settings',
            'settings_updated',
            __('Settings saved successfully.', 'myworks-qb-cost-adjuster'),
            'success'
        );
        
        // Redirect back to the settings page
        wp_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=myworks-qb-cost-adjuster')));
        exit;
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only enqueue on our settings page
        if (strpos($hook, 'myworks-qb-cost-adjuster') === false) {
            return;
        }
        
        wp_enqueue_style(
            'mwqbca-admin-style',
            MWQBCA_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            MWQBCA_VERSION
        );
        
        // Add inline JavaScript for validating the multiplier input
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                var multiplierField = $("input[name=\'qb_cost_multiplier\']");
                var enabledCheckbox = $("input[name=\'mwqbca_enabled\']");
                
                // Debug: Log current checkbox state
                console.log("Initial checkbox state: ", enabledCheckbox.is(":checked"));
                
                // Add change event listener to log checkbox changes
                enabledCheckbox.on("change", function() {
                    console.log("Checkbox changed to: ", $(this).is(":checked"), "Value: ", $(this).val());
                });
                
                multiplierField.on("change", function() {
                    var value = parseFloat($(this).val());
                    if (isNaN(value) || value <= 0.01) {
                        alert("Multiplier must be a valid number greater than 0.01");
                        $(this).val("1.65");
                    } else if (value > 100) {
                        alert("Multiplier cannot exceed 100. Value has been capped.");
                        $(this).val("100.00");
                    }
                });
                
                // Make sure the checkbox is properly submitted even when unchecked
                $("#settings-form").on("submit", function(e) {
                    console.log("Form submitted. Checkbox state: ", enabledCheckbox.is(":checked"));
                    
                    if (!enabledCheckbox.is(":checked")) {
                        console.log("Checkbox is unchecked. Adding hidden field with value 0");
                        $(this).append("<input type=\'hidden\' name=\'mwqbca_enabled\' value=\'0\' />");
                    } else {
                        console.log("Checkbox is checked. Value to be submitted: ", enabledCheckbox.val());
                    }
                });
            });
        ');
    }
    
    /**
     * Check if cost adjustment is enabled
     */
    public function is_enabled() {
        $enabled = (int) get_option('mwqbca_enabled', 0);
        
        // DEBUG: Log the retrieved enabled value
        error_log('QB Cost Adjuster - Retrieved enabled value: ' . $enabled);
        
        return $enabled === 1;
    }
    
    /**
     * Get the cost multiplier
     */
    public function get_multiplier() {
        return floatval(get_option('qb_cost_multiplier', '1.65'));
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $enabled = $this->is_enabled();
        $multiplier = get_option('qb_cost_multiplier', '1.65');
        
        // Debug information to help troubleshoot
        if (WP_DEBUG) {
            error_log('QB Cost Adjuster - Current enabled value: ' . (int) $enabled);
            error_log('QB Cost Adjuster - Current multiplier value: ' . $multiplier);
            error_log('QB Cost Adjuster - Raw option value in database: ' . get_option('mwqbca_enabled', 'not set'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('QuickBooks Cost Adjuster Settings', 'myworks-qb-cost-adjuster'); ?></h1>
            
            <?php settings_errors(); ?>
            
            <!-- Primary form using WordPress Settings API -->
            <form method="post" action="options.php" id="settings-form">
                <?php settings_fields('mwqbca_settings_group'); ?>
                <?php do_settings_sections('mwqbca_settings_group'); ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Enable Cost Adjuster', 'myworks-qb-cost-adjuster'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mwqbca_enabled" value="1" <?php checked(1, $enabled ? 1 : 0); ?> />
                                <?php _e('Enable automatic cost adjustments for QuickBooks products', 'myworks-qb-cost-adjuster'); ?>
                            </label>
                            <!-- Always submit a value for mwqbca_enabled -->
                            <input type="hidden" name="mwqbca_enabled_hidden" value="0" />
                            <p class="description"><?php _e('When enabled, product costs will be adjusted before syncing to QuickBooks.', 'myworks-qb-cost-adjuster'); ?></p>
                            <!-- Debug info -->
                            <?php if (WP_DEBUG): ?>
                            <p class="description">
                                <strong>Debug:</strong> Current enabled value: <?php echo esc_html($enabled ? '1 (enabled)' : '0 (disabled)'); ?><br>
                                Raw option value: <?php echo esc_html(get_option('mwqbca_enabled', 'not set')); ?>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row"><?php _e('Cost Multiplier', 'myworks-qb-cost-adjuster'); ?></th>
                        <td>
                            <input type="number" name="qb_cost_multiplier" value="<?php echo esc_attr($multiplier); ?>" step="0.01" min="0.02" style="width: 100px;" />
                            <p class="description">
                                <?php _e('Regular price will be divided by this value to calculate product cost for QuickBooks (Cost = Regular Price / Multiplier).', 'myworks-qb-cost-adjuster'); ?>
                                <?php _e('Default value: 1.65', 'myworks-qb-cost-adjuster'); ?>
                            </p>
                            <p class="description">
                                <?php _e('Multiplier must be greater than 0.01 and cannot exceed 100.', 'myworks-qb-cost-adjuster'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <!-- Alternative form for direct form submission if needed -->
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="alternative-settings-form" style="display: none;">
                <input type="hidden" name="action" value="mwqbca_save_settings" />
                <?php wp_nonce_field('mwqbca_save_settings', 'mwqbca_settings_nonce'); ?>
                
                <input type="checkbox" name="mwqbca_enabled" value="1" <?php checked(1, $enabled ? 1 : 0); ?> />
                <input type="hidden" name="mwqbca_enabled_hidden" value="0" />
                <input type="number" name="qb_cost_multiplier" value="<?php echo esc_attr($multiplier); ?>" />
                
                <input type="submit" value="<?php _e('Save Settings', 'myworks-qb-cost-adjuster'); ?>" />
            </form>
            
            <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
                <h2><?php _e('About QuickBooks Cost Adjuster', 'myworks-qb-cost-adjuster'); ?></h2>
                <p><?php _e('This plugin adjusts product costs before syncing to QuickBooks via MyWorks Sync.', 'myworks-qb-cost-adjuster'); ?></p>
                <p><?php _e('When enabled, the plugin will calculate costs as: Product Cost = Regular Price / Multiplier.', 'myworks-qb-cost-adjuster'); ?></p>
                <p><?php _e('For example, with a default multiplier of 1.65 and a product price of $16.50, the cost sent to QuickBooks will be $10.00.', 'myworks-qb-cost-adjuster'); ?></p>
            </div>
            
            <?php 
            // Display log viewer without relying on global instance
            $log_viewer = new MWQBCA_Log_Viewer();
            $log_viewer->render_log_section();
            ?>

            <div class="mwqbca-force-update-section">
                <h3><?php _e('Force Update All Products & Variations', 'myworks-qb-cost-adjuster'); ?></h3>
                <p><?php _e('This will force update all products and variations with the current cost multiplier.', 'myworks-qb-cost-adjuster'); ?></p>
                
                <div id="mwqbca-force-update-progress" style="display: none; margin: 20px 0;">
                    <div class="progress-bar-container" style="background: #f0f0f1; height: 20px; border-radius: 3px; overflow: hidden;">
                        <div id="mwqbca-force-update-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                    </div>
                    <p id="mwqbca-force-update-progress-text" style="margin: 5px 0;"></p>
                </div>

                <div id="mwqbca-force-update-log" style="max-height: 300px; overflow-y: auto; margin: 20px 0; padding: 10px; background: #f6f7f7; border: 1px solid #dcdcde;">
                    <style>
                        .log-entry {
                            padding: 5px;
                            border-bottom: 1px solid #dcdcde;
                            font-family: monospace;
                        }
                        .log-entry:last-child {
                            border-bottom: none;
                        }
                        .log-entry .timestamp {
                            color: #666;
                            margin-right: 10px;
                        }
                        .log-entry .product {
                            font-weight: bold;
                            margin-right: 10px;
                        }
                        .log-entry.success .message {
                            color: #00a32a;
                        }
                        .log-entry.error .message {
                            color: #d63638;
                        }
                    </style>
                </div>

                <button type="button" id="mwqbca-force-update-all" class="button button-primary">
                    <?php _e('Force Update All Products & Variations', 'myworks-qb-cost-adjuster'); ?>
                    <span id="mwqbca-force-update-spinner" class="spinner" style="float: none; margin: 0 0 0 10px;"></span>
                </button>
                <div id="mwqbca-force-update-result"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Debug function to check the raw option value in the database
     */
    private function debug_check_option_value() {
        global $wpdb;
        
        // Only run on admin pages
        if (!is_admin()) {
            return;
        }
        
        // Get the raw option value directly from database
        $option_name = 'mwqbca_enabled';
        $raw_db_value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option_name
        ));
        
        // Get the value using get_option
        $option_value = get_option($option_name, 'not_set');
        
        // Log both values
        error_log('QB Cost Adjuster - DEBUG - Raw DB value for ' . $option_name . ': ' . (is_null($raw_db_value) ? 'NULL' : $raw_db_value));
        error_log('QB Cost Adjuster - DEBUG - Option value from get_option(): ' . $option_value . ' (type: ' . gettype($option_value) . ')');
    }
} 