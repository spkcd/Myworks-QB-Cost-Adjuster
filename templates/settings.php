<?php
// Debug log
error_log('QB Cost Adjuster - Loading settings template');

// Get current settings
$settings = get_option('mwqbca_settings', array(
    'enabled' => 0,
    'cost_multiplier' => '1.65'
));

// Debug log
error_log('QB Cost Adjuster - Settings retrieved: ' . print_r($settings, true));
?>

<div class="wrap">
    <h1><?php _e('QuickBooks Cost Adjuster Settings', 'myworks-qb-cost-adjuster'); ?></h1>
    
    <form method="post" action="options.php">
        <?php
        // Output security fields
        settings_fields('mwqbca_settings');
        
        // Output setting sections and their fields
        do_settings_sections('mwqbca_settings');
        ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="mwqbca_enabled">
                        <?php _e('Enable Cost Adjuster', 'myworks-qb-cost-adjuster'); ?>
                    </label>
                </th>
                <td>
                    <input type="checkbox" 
                           id="mwqbca_enabled" 
                           name="mwqbca_settings[enabled]" 
                           value="1" 
                           <?php checked(1, $settings['enabled']); ?> />
                    <p class="description">
                        <?php _e('Enable or disable the cost adjustment functionality.', 'myworks-qb-cost-adjuster'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="mwqbca_cost_multiplier">
                        <?php _e('Cost Multiplier', 'myworks-qb-cost-adjuster'); ?>
                    </label>
                </th>
                <td>
                    <input type="number" 
                           id="mwqbca_cost_multiplier" 
                           name="mwqbca_settings[cost_multiplier]" 
                           value="<?php echo esc_attr($settings['cost_multiplier']); ?>" 
                           step="0.01" 
                           min="0.01" 
                           class="regular-text" />
                    <p class="description">
                        <?php _e('Multiply the product cost by this value before syncing to QuickBooks.', 'myworks-qb-cost-adjuster'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>
    
    <hr>
    
    <h2><?php _e('Force Update All Products & Variations', 'myworks-qb-cost-adjuster'); ?></h2>
    <p><?php _e('Click the button below to force update all products with the current cost multiplier.', 'myworks-qb-cost-adjuster'); ?></p>
    
    <div class="mwqbca-force-update-section">
        <button type="button" id="mwqbca-force-update-all" class="button button-primary">
            <?php _e('Force Update All Products & Variations', 'myworks-qb-cost-adjuster'); ?>
            <span class="spinner" style="display:none; visibility:visible; float:none; margin:0 0 0 5px;"></span>
        </button>
        <div id="mwqbca-force-update-result"></div>
        
        <div id="mwqbca-force-update-progress" style="display: none; margin-top: 20px;">
            <h3><?php _e('Progress', 'myworks-qb-cost-adjuster'); ?></h3>
            <div class="progress-bar">
                <div class="progress-bar-fill" style="width: 0%;"></div>
            </div>
            <div class="progress-text">0%</div>
        </div>
        
        <div id="mwqbca-force-update-log" style="display: none; margin-top: 20px;">
            <h3><?php _e('Processing Log', 'myworks-qb-cost-adjuster'); ?></h3>
            <div class="log-entries"></div>
        </div>
    </div>
</div>

<?php
// Debug log
error_log('QB Cost Adjuster - Settings template loaded');
?> 