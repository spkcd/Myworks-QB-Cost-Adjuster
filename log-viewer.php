<?php
/**
 * Log viewer for MyWorks QuickBooks Cost Adjuster
 *
 * @package MyWorks_QB_Cost_Adjuster
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log viewer class
 */
class MWQBCA_Log_Viewer {
    
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
        
        // Add action for clear logs
        add_action('admin_post_mwqbca_clear_logs', array($this, 'handle_clear_logs'));
    }
    
    /**
     * Get log files
     * 
     * @return array Log files
     */
    public function get_log_files() {
        $log_dir = WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/';
        $log_files = array();
        
        if (file_exists($log_dir)) {
            $files = scandir($log_dir);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) == 'log') {
                    $log_files[] = $file;
                }
            }
        }
        
        // Sort files by date (newest first)
        usort($log_files, function($a, $b) {
            return filemtime(WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/' . $b) - 
                   filemtime(WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/' . $a);
        });
        
        return $log_files;
    }
    
    /**
     * Get log content
     * 
     * @param string $filename Log filename
     * @param int $limit Maximum number of lines to return
     * @param float $multiplier_filter Filter for specific multiplier value (optional)
     * @return string Log content
     */
    public function get_log_content($filename, $limit = 100, $multiplier_filter = null) {
        $log_dir = WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/';
        $file_path = $log_dir . $filename;
        
        if (!file_exists($file_path)) {
            return '';
        }
        
        $lines = file($file_path);
        
        // If we're filtering by multiplier, do that first
        if ($multiplier_filter !== null) {
            $filtered_lines = array();
            $multiplier_tag = sprintf("[MULTIPLIER:%.2f]", floatval($multiplier_filter));
            
            foreach ($lines as $line) {
                if (strpos($line, $multiplier_tag) !== false) {
                    $filtered_lines[] = $line;
                }
            }
            
            $lines = $filtered_lines;
        }
        
        // Get the most recent entries up to the limit
        if (count($lines) <= $limit) {
            $content = implode('', $lines);
        } else {
            // Get the last X lines
            $lines = array_slice($lines, -$limit);
            $content = implode('', $lines);
        }
        
        // Highlight multiplier information in the content
        $content = preg_replace(
            '/\[MULTIPLIER:([\d\.]+)\]/', 
            '<span style="background-color: #FFEB3B; font-weight: bold; padding: 2px 4px; border-radius: 3px;">Multiplier: $1</span>', 
            $content
        );
        
        return $content;
    }
    
    /**
     * Extract unique multiplier values from log file
     * 
     * @param string $filename Log filename
     * @return array List of unique multiplier values
     */
    public function get_unique_multipliers($filename) {
        $log_dir = WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/';
        $file_path = $log_dir . $filename;
        
        if (!file_exists($file_path)) {
            return array();
        }
        
        $multipliers = array();
        $lines = file($file_path);
        
        foreach ($lines as $line) {
            if (preg_match('/\[MULTIPLIER:([\d\.]+)\]/', $line, $matches)) {
                $multipliers[] = floatval($matches[1]);
            }
        }
        
        return array_unique($multipliers);
    }
    
    /**
     * Handle clearing logs
     */
    public function handle_clear_logs() {
        // Check nonce
        check_admin_referer('mwqbca_clear_logs', 'mwqbca_nonce');
        
        // Check capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'myworks-qb-cost-adjuster'));
        }
        
        // Clear logs
        $this->logger->clear_logs();
        
        // Redirect back to log page with success message
        wp_redirect(add_query_arg('cleared', 'true', admin_url('admin.php?page=myworks-qb-cost-adjuster-logs')));
        exit;
    }
    
    /**
     * Render the log viewer section that appears on the settings page
     */
    public function render_log_section() {
        $log_files = $this->get_log_files();
        ?>
        <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
            <h2><?php _e('Cost Adjustment Logs', 'myworks-qb-cost-adjuster'); ?></h2>
            
            <?php if (empty($log_files)): ?>
                <p><?php _e('No logs available.', 'myworks-qb-cost-adjuster'); ?></p>
            <?php else: ?>
                <p><?php _e('Recent log entries:', 'myworks-qb-cost-adjuster'); ?></p>
                
                <?php 
                // Get most recent log file
                $latest_log = $log_files[0];
                $log_content = $this->get_log_content($latest_log, 5); // Only show 5 recent entries on summary
                ?>
                
                <div class="log-viewer">
                    <pre><?php echo wp_kses_post($log_content); ?></pre>
                </div>
                
                <p>
                    <em><?php printf(__('Log file: %s', 'myworks-qb-cost-adjuster'), $latest_log); ?></em>
                    <a href="<?php echo admin_url('admin.php?page=myworks-qb-cost-adjuster-logs'); ?>" class="button button-secondary"><?php _e('View Full Logs', 'myworks-qb-cost-adjuster'); ?></a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render the full log viewer page
     */
    public function render_logs_page() {
        // Get log files
        $log_files = $this->get_log_files();
        
        // Check if logs were cleared
        $cleared = isset($_GET['cleared']) && $_GET['cleared'] === 'true';
        
        // Get current log file (default to most recent)
        $current_log = isset($_GET['file']) && in_array($_GET['file'], $log_files) 
            ? $_GET['file'] 
            : (!empty($log_files) ? $log_files[0] : '');
        
        // Get multiplier filter
        $multiplier_filter = isset($_GET['multiplier']) ? floatval($_GET['multiplier']) : null;
        
        // Get unique multipliers for the current log
        $unique_multipliers = !empty($current_log) ? $this->get_unique_multipliers($current_log) : array();
        
        // Get log content
        $log_content = !empty($current_log) ? $this->get_log_content($current_log, 100, $multiplier_filter) : '';
        
        ?>
        <div class="wrap">
            <h1><?php _e('QuickBooks Cost Adjuster Logs', 'myworks-qb-cost-adjuster'); ?></h1>
            
            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Logs have been successfully cleared.', 'myworks-qb-cost-adjuster'); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (empty($log_files)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No log files available. Logs will be created when cost adjustments are processed.', 'myworks-qb-cost-adjuster'); ?></p>
                </div>
            <?php else: ?>
                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <div class="tablenav top">
                        <div class="alignleft actions">
                            <form method="get">
                                <input type="hidden" name="page" value="myworks-qb-cost-adjuster-logs">
                                <label for="log-file" class="screen-reader-text"><?php _e('Select log file', 'myworks-qb-cost-adjuster'); ?></label>
                                <select name="file" id="log-file">
                                    <?php foreach ($log_files as $log_file): ?>
                                        <option value="<?php echo esc_attr($log_file); ?>" <?php selected($current_log, $log_file); ?>>
                                            <?php echo esc_html($log_file); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <?php if (!empty($unique_multipliers)): ?>
                                    <label for="multiplier-filter" class="screen-reader-text"><?php _e('Filter by multiplier', 'myworks-qb-cost-adjuster'); ?></label>
                                    <select name="multiplier" id="multiplier-filter">
                                        <option value=""><?php _e('All multipliers', 'myworks-qb-cost-adjuster'); ?></option>
                                        <?php foreach ($unique_multipliers as $mult): ?>
                                            <option value="<?php echo esc_attr($mult); ?>" <?php selected($multiplier_filter, $mult); ?>>
                                                <?php printf(__('Multiplier: %.2f', 'myworks-qb-cost-adjuster'), $mult); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                
                                <input type="submit" class="button" value="<?php esc_attr_e('Apply', 'myworks-qb-cost-adjuster'); ?>">
                                
                                <?php if ($multiplier_filter !== null): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=myworks-qb-cost-adjuster-logs&file=' . urlencode($current_log))); ?>" class="button"><?php _e('Clear Filter', 'myworks-qb-cost-adjuster'); ?></a>
                                <?php endif; ?>
                            </form>
                        </div>
                        <div class="alignright">
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                <input type="hidden" name="action" value="mwqbca_clear_logs">
                                <?php wp_nonce_field('mwqbca_clear_logs', 'mwqbca_nonce'); ?>
                                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Clear All Logs', 'myworks-qb-cost-adjuster'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all logs? This cannot be undone.', 'myworks-qb-cost-adjuster'); ?>');">
                            </form>
                        </div>
                        <br class="clear">
                    </div>
                    
                    <h2>
                        <?php 
                        if ($multiplier_filter !== null) {
                            printf(__('Showing log entries with multiplier: %.2f', 'myworks-qb-cost-adjuster'), $multiplier_filter);
                        } else {
                            _e('Showing last 100 log entries', 'myworks-qb-cost-adjuster');
                        }
                        ?>
                    </h2>
                    
                    <div class="log-viewer" style="background-color: #f9f9f9; border: 1px solid #ddd; padding: 15px; max-height: 500px; overflow: auto;">
                        <?php if (empty($log_content)): ?>
                            <p><?php _e('No log entries found matching your criteria.', 'myworks-qb-cost-adjuster'); ?></p>
                        <?php else: ?>
                            <pre style="margin: 0; white-space: pre-wrap; font-family: monospace; font-size: 12px;"><?php echo wp_kses_post($log_content); ?></pre>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($unique_multipliers)): ?>
                        <div style="margin-top: 15px;">
                            <h3><?php _e('Multipliers Used in This Log', 'myworks-qb-cost-adjuster'); ?></h3>
                            <div class="multiplier-tags">
                                <?php foreach ($unique_multipliers as $mult): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=myworks-qb-cost-adjuster-logs&file=' . urlencode($current_log) . '&multiplier=' . $mult)); ?>" class="multiplier-tag" style="display: inline-block; background-color: #FFEB3B; padding: 5px 10px; margin: 0 5px 5px 0; border-radius: 3px; text-decoration: none; color: #000; font-weight: bold;">
                                        <?php printf('%.2f', $mult); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="card" style="padding: 20px;">
                    <h2><?php _e('Log Information', 'myworks-qb-cost-adjuster'); ?></h2>
                    <p><?php _e('These logs show cost adjustment operations performed by the plugin when product data is synced to QuickBooks.', 'myworks-qb-cost-adjuster'); ?></p>
                    <p><?php _e('Log entries include:', 'myworks-qb-cost-adjuster'); ?></p>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><?php _e('<strong>Multiplier Value</strong>: The multiplier used in cost calculations', 'myworks-qb-cost-adjuster'); ?></li>
                        <li><?php _e('Regular price of products', 'myworks-qb-cost-adjuster'); ?></li>
                        <li><?php _e('Calculated cost values sent to QuickBooks', 'myworks-qb-cost-adjuster'); ?></li>
                        <li><?php _e('Products that were processed', 'myworks-qb-cost-adjuster'); ?></li>
                    </ul>
                </div>
            <?php endif; ?>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=myworks-qb-cost-adjuster'); ?>" class="button button-secondary"><?php _e('Back to Settings', 'myworks-qb-cost-adjuster'); ?></a>
            </p>
        </div>
        <?php
    }
} 