<?php
/**
 * Sync Report for MyWorks QuickBooks Cost Adjuster
 *
 * @package MyWorks_QB_Cost_Adjuster
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync Report class
 */
class MWQBCA_Sync_Report {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize logger
        require_once MWQBCA_PLUGIN_PATH . 'includes/class-mwqbca-logger.php';
        $this->logger = new MWQBCA_Logger();
        
        // Add action for clearing sync logs
        add_action('admin_post_mwqbca_clear_sync_logs', array($this, 'handle_clear_sync_logs'));
        
        // Register assets
        add_action('admin_enqueue_scripts', array($this, 'register_assets'));
    }
    
    /**
     * Register and enqueue assets
     */
    public function register_assets($hook) {
        if ($hook != 'woocommerce_page_myworks-qb-cost-adjuster-sync-report') {
            return;
        }
        
        wp_enqueue_style('mwqbca-admin-style', MWQBCA_PLUGIN_URL . 'assets/css/admin-style.css', array(), MWQBCA_VERSION);
        wp_enqueue_script('mwqbca-admin-script', MWQBCA_PLUGIN_URL . 'assets/js/admin-script.js', array('jquery'), MWQBCA_VERSION, true);
    }
    
    /**
     * Get sync logs from database
     * 
     * @param int $limit Maximum number of logs to retrieve
     * @return array Sync logs
     */
    public function get_sync_logs($limit = 100) {
        $logs = get_option('mwqbca_sync_logs', array());
        
        // Sort by timestamp (newest first)
        usort($logs, function($a, $b) {
            $a_time = isset($a['timestamp']) ? strtotime($a['timestamp']) : 0;
            $b_time = isset($b['timestamp']) ? strtotime($b['timestamp']) : 0;
            return $b_time - $a_time;
        });
        
        // Limit the number of logs returned
        return array_slice($logs, 0, $limit);
    }
    
    /**
     * Get API logs from database
     *
     * @param int $limit Maximum number of logs to retrieve
     * @return array API logs
     */
    public function get_api_logs($limit = 50) {
        $logs = get_option('mwqbca_api_logs', array());
        
        // Sort by timestamp (newest first)
        usort($logs, function($a, $b) {
            $a_time = isset($a['timestamp']) ? strtotime($a['timestamp']) : 0;
            $b_time = isset($b['timestamp']) ? strtotime($b['timestamp']) : 0;
            return $b_time - $a_time;
        });
        
        // Limit the number of logs returned
        return array_slice($logs, 0, $limit);
    }
    
    /**
     * Handle clearing sync logs
     */
    public function handle_clear_sync_logs() {
        // Check nonce
        check_admin_referer('mwqbca_clear_sync_logs', 'mwqbca_nonce');
        
        // Check capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'myworks-qb-cost-adjuster'));
        }
        
        // Clear sync logs
        update_option('mwqbca_sync_logs', array());
        
        // Clear API logs if requested
        if (isset($_POST['clear_api_logs']) && $_POST['clear_api_logs'] == '1') {
            update_option('mwqbca_api_logs', array());
        }
        
        // Clear detailed logs if requested
        if (isset($_POST['clear_detailed_logs']) && $_POST['clear_detailed_logs'] == '1') {
            update_option('mwqbca_detailed_variation_logs', array());
            update_option('mwqbca_product_data_logs', array());
        }
        
        // Redirect back to sync report page with success message
        wp_redirect(add_query_arg('cleared', 'true', admin_url('admin.php?page=myworks-qb-cost-adjuster-sync-report')));
        exit;
    }
    
    /**
     * Render the sync report page
     */
    public function render_sync_report_page() {
        // Get sync logs
        $sync_logs = $this->get_sync_logs(100);
        
        // Get API logs
        $api_logs = $this->get_api_logs(20);
        
        // Get detailed logs
        $detailed_logs = get_option('mwqbca_detailed_variation_logs', array());
        
        // Limit to recent logs
        $detailed_logs = array_slice($detailed_logs, 0, 20);
        
        // Check if logs were cleared
        $cleared = isset($_GET['cleared']) && $_GET['cleared'] === 'true';
        
        // Get the active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'sync_logs';
        
        ?>
        <div class="wrap">
            <h1><?php _e('QuickBooks Cost Adjuster - Sync Report', 'myworks-qb-cost-adjuster'); ?></h1>
            
            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Logs have been successfully cleared.', 'myworks-qb-cost-adjuster'); ?></p>
                </div>
            <?php endif; ?>
            
            <nav class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=myworks-qb-cost-adjuster-sync-report&tab=sync_logs'); ?>" class="nav-tab <?php echo $active_tab == 'sync_logs' ? 'nav-tab-active' : ''; ?>"><?php _e('Sync Logs', 'myworks-qb-cost-adjuster'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=myworks-qb-cost-adjuster-sync-report&tab=api_logs'); ?>" class="nav-tab <?php echo $active_tab == 'api_logs' ? 'nav-tab-active' : ''; ?>"><?php _e('API Logs', 'myworks-qb-cost-adjuster'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=myworks-qb-cost-adjuster-sync-report&tab=detailed_logs'); ?>" class="nav-tab <?php echo $active_tab == 'detailed_logs' ? 'nav-tab-active' : ''; ?>"><?php _e('Detailed Logs', 'myworks-qb-cost-adjuster'); ?></a>
            </nav>
            
            <div class="tab-content">
                <?php if ($active_tab == 'sync_logs'): ?>
                    <div class="sync-logs-section">
                        <div class="card" style="padding: 20px; margin-top: 20px;">
                            <h2><?php _e('Recent Cost Adjustment Operations', 'myworks-qb-cost-adjuster'); ?></h2>
                            <p><?php _e('These logs show cost adjustment operations performed by the plugin when product data is synced to QuickBooks.', 'myworks-qb-cost-adjuster'); ?></p>
                            
                            <?php if (empty($sync_logs)): ?>
                                <p><?php _e('No sync logs found. Logs will be generated when products are synced to QuickBooks.', 'myworks-qb-cost-adjuster'); ?></p>
                            <?php else: ?>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Time', 'myworks-qb-cost-adjuster'); ?></th>
                                            <th><?php _e('Type', 'myworks-qb-cost-adjuster'); ?></th>
                                            <th><?php _e('Product', 'myworks-qb-cost-adjuster'); ?></th>
                                            <th><?php _e('Regular Price', 'myworks-qb-cost-adjuster'); ?></th>
                                            <th><?php _e('Multiplier', 'myworks-qb-cost-adjuster'); ?></th>
                                            <th><?php _e('Calculated Cost', 'myworks-qb-cost-adjuster'); ?></th>
                                            <th><?php _e('Status', 'myworks-qb-cost-adjuster'); ?></th>
                                            <th><?php _e('Message', 'myworks-qb-cost-adjuster'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sync_logs as $log): ?>
                                            <tr>
                                                <td><?php echo isset($log['timestamp']) ? esc_html($log['timestamp']) : ''; ?></td>
                                                <td><?php echo isset($log['product_type']) ? esc_html($log['product_type']) : ''; ?></td>
                                                <td>
                                                    <?php 
                                                    $product_id = isset($log['product_id']) ? intval($log['product_id']) : 0;
                                                    $product_name = isset($log['product_name']) ? esc_html($log['product_name']) : '';
                                                    
                                                    if ($product_id > 0) {
                                                        echo sprintf('<a href="%s" target="_blank">%s (#%d)</a>', 
                                                            esc_url(get_edit_post_link($product_id)), 
                                                            $product_name, 
                                                            $product_id
                                                        );
                                                    } else {
                                                        echo $product_name;
                                                    }
                                                    ?>
                                                </td>
                                                <td><?php echo isset($log['regular_price']) ? esc_html($log['regular_price']) : ''; ?></td>
                                                <td><?php echo isset($log['multiplier']) ? esc_html($log['multiplier']) : ''; ?></td>
                                                <td><?php echo isset($log['cost']) ? esc_html($log['cost']) : ''; ?></td>
                                                <td>
                                                    <?php 
                                                    $status = isset($log['status']) ? $log['status'] : '';
                                                    $status_class = '';
                                                    $status_label = '';
                                                    
                                                    switch ($status) {
                                                        case 'success':
                                                            $status_class = 'success';
                                                            $status_label = __('Success', 'myworks-qb-cost-adjuster');
                                                            break;
                                                        case 'error':
                                                            $status_class = 'error';
                                                            $status_label = __('Error', 'myworks-qb-cost-adjuster');
                                                            break;
                                                        case 'warning':
                                                            $status_class = 'warning';
                                                            $status_label = __('Warning', 'myworks-qb-cost-adjuster');
                                                            break;
                                                        case 'info':
                                                            $status_class = 'info';
                                                            $status_label = __('Info', 'myworks-qb-cost-adjuster');
                                                            break;
                                                        default:
                                                            $status_label = esc_html($status);
                                                    }
                                                    
                                                    echo '<span class="status-' . $status_class . '">' . $status_label . '</span>';
                                                    ?>
                                                </td>
                                                <td><?php echo isset($log['message']) ? esc_html($log['message']) : ''; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                            
                            <div class="clear-logs-section" style="margin-top: 20px;">
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                    <input type="hidden" name="action" value="mwqbca_clear_sync_logs">
                                    <?php wp_nonce_field('mwqbca_clear_sync_logs', 'mwqbca_nonce'); ?>
                                    <button type="submit" class="button button-secondary" onclick="return confirm('<?php _e('Are you sure you want to clear all sync logs?', 'myworks-qb-cost-adjuster'); ?>')">
                                        <?php _e('Clear Sync Logs', 'myworks-qb-cost-adjuster'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                
                <?php elseif ($active_tab == 'api_logs'): ?>
                    <div class="api-logs-section">
                        <div class="card" style="padding: 20px; margin-top: 20px;">
                            <h2><?php _e('QuickBooks API Request/Response Logs', 'myworks-qb-cost-adjuster'); ?></h2>
                            <p><?php _e('These logs show the data being sent to QuickBooks and the responses received.', 'myworks-qb-cost-adjuster'); ?></p>
                            
                            <?php if (empty($api_logs)): ?>
                                <p><?php _e('No API logs found. Logs will be generated when products are synced to QuickBooks.', 'myworks-qb-cost-adjuster'); ?></p>
                            <?php else: ?>
                                <div class="accordion-container">
                                    <?php foreach ($api_logs as $index => $log): ?>
                                        <div class="accordion-section">
                                            <div class="accordion-section-title" id="accordion-section-<?php echo $index; ?>">
                                                <h3>
                                                    <?php echo isset($log['timestamp']) ? esc_html($log['timestamp']) : ''; ?> - 
                                                    <?php echo isset($log['product_id']) ? sprintf(__('Product #%d', 'myworks-qb-cost-adjuster'), $log['product_id']) : ''; ?> - 
                                                    <?php echo isset($log['type']) ? esc_html($log['type']) : ''; ?>
                                                </h3>
                                                <span class="screen-reader-text"><?php _e('Toggle panel', 'myworks-qb-cost-adjuster'); ?></span>
                                                <span class="accordion-section-arrow"></span>
                                            </div>
                                            <div class="accordion-section-content" id="accordion-section-content-<?php echo $index; ?>">
                                                <div class="inside">
                                                    <h4><?php _e('Request Payload', 'myworks-qb-cost-adjuster'); ?></h4>
                                                    <div class="code-preview">
                                                        <pre><?php echo isset($log['request']) ? esc_html($log['request']) : ''; ?></pre>
                                                    </div>
                                                    
                                                    <h4><?php _e('Response', 'myworks-qb-cost-adjuster'); ?></h4>
                                                    <div class="code-preview">
                                                        <pre><?php echo isset($log['response']) ? esc_html($log['response']) : ''; ?></pre>
                                                    </div>
                                                    
                                                    <?php if (isset($log['status'])): ?>
                                                        <p>
                                                            <strong><?php _e('Status:', 'myworks-qb-cost-adjuster'); ?></strong>
                                                            <span class="status-<?php echo ($log['status'] == 'success') ? 'success' : 'error'; ?>">
                                                                <?php echo ($log['status'] == 'success') ? __('Success', 'myworks-qb-cost-adjuster') : __('Error', 'myworks-qb-cost-adjuster'); ?>
                                                            </span>
                                                        </p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (isset($log['error_message']) && !empty($log['error_message'])): ?>
                                                        <p><strong><?php _e('Error Message:', 'myworks-qb-cost-adjuster'); ?></strong> <?php echo esc_html($log['error_message']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="clear-logs-section" style="margin-top: 20px;">
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                    <input type="hidden" name="action" value="mwqbca_clear_sync_logs">
                                    <input type="hidden" name="clear_api_logs" value="1">
                                    <?php wp_nonce_field('mwqbca_clear_sync_logs', 'mwqbca_nonce'); ?>
                                    <button type="submit" class="button button-secondary" onclick="return confirm('<?php _e('Are you sure you want to clear all API logs?', 'myworks-qb-cost-adjuster'); ?>')">
                                        <?php _e('Clear API Logs', 'myworks-qb-cost-adjuster'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                
                <?php elseif ($active_tab == 'detailed_logs'): ?>
                    <div class="detailed-logs-section">
                        <div class="card" style="padding: 20px; margin-top: 20px;">
                            <h2><?php _e('Detailed Sync Logs', 'myworks-qb-cost-adjuster'); ?></h2>
                            <p><?php _e('These logs show detailed before/after data structures during product syncing.', 'myworks-qb-cost-adjuster'); ?></p>
                            
                            <?php if (empty($detailed_logs)): ?>
                                <p><?php _e('No detailed logs found. Logs will be generated when products are synced to QuickBooks.', 'myworks-qb-cost-adjuster'); ?></p>
                            <?php else: ?>
                                <div class="accordion-container">
                                    <?php foreach ($detailed_logs as $index => $log): ?>
                                        <div class="accordion-section">
                                            <div class="accordion-section-title" id="accordion-section-detailed-<?php echo $index; ?>">
                                                <h3>
                                                    <?php echo isset($log['timestamp']) ? esc_html($log['timestamp']) : ''; ?> - 
                                                    <?php echo isset($log['product_type']) ? esc_html($log['product_type']) : ''; ?> #<?php echo isset($log['variation_id']) ? esc_html($log['variation_id']) : ''; ?> - 
                                                    <?php echo isset($log['stage']) ? esc_html($log['stage']) : ''; ?>
                                                </h3>
                                                <span class="screen-reader-text"><?php _e('Toggle panel', 'myworks-qb-cost-adjuster'); ?></span>
                                                <span class="accordion-section-arrow"></span>
                                            </div>
                                            <div class="accordion-section-content" id="accordion-section-content-detailed-<?php echo $index; ?>">
                                                <div class="inside">
                                                    <h4><?php _e('Cost Fields', 'myworks-qb-cost-adjuster'); ?></h4>
                                                    <?php if (isset($log['cost_fields']) && !empty($log['cost_fields'])): ?>
                                                        <table class="wp-list-table widefat fixed striped" style="width: auto;">
                                                            <thead>
                                                                <tr>
                                                                    <th><?php _e('Field', 'myworks-qb-cost-adjuster'); ?></th>
                                                                    <th><?php _e('Value', 'myworks-qb-cost-adjuster'); ?></th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($log['cost_fields'] as $field => $value): ?>
                                                                    <tr>
                                                                        <td><strong><?php echo esc_html($field); ?></strong></td>
                                                                        <td><?php echo esc_html($value); ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    <?php else: ?>
                                                        <p><?php _e('No cost fields found.', 'myworks-qb-cost-adjuster'); ?></p>
                                                    <?php endif; ?>
                                                    
                                                    <h4><?php _e('Full Data Structure', 'myworks-qb-cost-adjuster'); ?></h4>
                                                    <div class="code-preview">
                                                        <pre><?php echo isset($log['full_data']) ? esc_html(print_r($log['full_data'], true)) : ''; ?></pre>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="clear-logs-section" style="margin-top: 20px;">
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                    <input type="hidden" name="action" value="mwqbca_clear_sync_logs">
                                    <input type="hidden" name="clear_detailed_logs" value="1">
                                    <?php wp_nonce_field('mwqbca_clear_sync_logs', 'mwqbca_nonce'); ?>
                                    <button type="submit" class="button button-secondary" onclick="return confirm('<?php _e('Are you sure you want to clear all detailed logs?', 'myworks-qb-cost-adjuster'); ?>')">
                                        <?php _e('Clear Detailed Logs', 'myworks-qb-cost-adjuster'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            .status-success { color: #46b450; font-weight: bold; }
            .status-error { color: #dc3232; font-weight: bold; }
            .status-warning { color: #ffb900; font-weight: bold; }
            .status-info { color: #00a0d2; font-weight: bold; }
            
            .accordion-section {
                border: 1px solid #ddd;
                margin-bottom: 10px;
            }
            
            .accordion-section-title {
                background: #f7f7f7;
                padding: 10px 15px;
                cursor: pointer;
                position: relative;
            }
            
            .accordion-section-title h3 {
                margin: 0;
                font-size: 14px;
            }
            
            .accordion-section-arrow {
                position: absolute;
                right: 15px;
                top: 50%;
                transform: translateY(-50%);
            }
            
            .accordion-section-arrow:after {
                content: "\f140";
                font: normal 20px/1 dashicons;
            }
            
            .accordion-section-content {
                display: none;
                padding: 15px;
                background: #fff;
            }
            
            .code-preview {
                background: #f9f9f9;
                padding: 10px;
                max-height: 300px;
                overflow: auto;
                border: 1px solid #ddd;
                margin-bottom: 15px;
            }
            
            .code-preview pre {
                margin: 0;
                white-space: pre-wrap;
                word-wrap: break-word;
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                // Accordion functionality
                $('.accordion-section-title').click(function() {
                    var section = $(this).closest('.accordion-section');
                    var content = section.find('.accordion-section-content');
                    
                    if (content.is(':visible')) {
                        content.slideUp();
                        $(this).find('.accordion-section-arrow').removeClass('open');
                    } else {
                        content.slideDown();
                        $(this).find('.accordion-section-arrow').addClass('open');
                    }
                });
                
                // Add open class for arrow toggle
                $('.accordion-section-arrow.open:after').css('content', '"\f142"');
            });
        </script>
        <?php
    }
} 