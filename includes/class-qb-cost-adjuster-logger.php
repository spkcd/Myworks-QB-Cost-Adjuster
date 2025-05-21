<?php
/**
 * Logger class for tracking cost field changes
 */
class QB_Cost_Field_Logger {
    private static $instance = null;
    private $log_table = 'qb_cost_adjuster_logs';
    private $batch_log_table = 'qb_cost_adjuster_batch_logs';

    private function __construct() {
        $this->create_log_tables();
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create necessary database tables
     */
    private function create_log_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table for individual cost changes
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$this->log_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            old_cost decimal(10,2) NOT NULL,
            new_cost decimal(10,2) NOT NULL,
            change_type varchar(50) NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        
        // Table for batch operations
        $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$this->batch_log_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            batch_id varchar(50) NOT NULL,
            total_products int(11) NOT NULL,
            successful_updates int(11) NOT NULL,
            failed_updates int(11) NOT NULL,
            multiplier decimal(10,2) NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY batch_id (batch_id),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log a single cost field change
     */
    public function log_cost_change($product_id, $old_cost, $new_cost, $change_type = 'manual') {
        global $wpdb;
        
        return $wpdb->insert(
            $wpdb->prefix . $this->log_table,
            array(
                'product_id' => $product_id,
                'old_cost' => $old_cost,
                'new_cost' => $new_cost,
                'change_type' => $change_type,
                'timestamp' => current_time('mysql')
            ),
            array('%d', '%f', '%f', '%s', '%s')
        );
    }

    /**
     * Log a batch adjustment operation
     */
    public function log_batch_adjustment($adjusted_items, $errors) {
        global $wpdb;
        
        $batch_id = uniqid('batch_');
        
        return $wpdb->insert(
            $wpdb->prefix . $this->batch_log_table,
            array(
                'batch_id' => $batch_id,
                'total_products' => count($adjusted_items) + count($errors),
                'successful_updates' => count($adjusted_items),
                'failed_updates' => count($errors),
                'multiplier' => get_option('qb_cost_adjuster_multiplier', 0.6),
                'timestamp' => current_time('mysql')
            ),
            array('%s', '%d', '%d', '%d', '%f', '%s')
        );
    }

    /**
     * Get recent cost changes for a product
     */
    public function get_product_cost_history($product_id, $limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}{$this->log_table}
            WHERE product_id = %d
            ORDER BY timestamp DESC
            LIMIT %d",
            $product_id,
            $limit
        ));
    }

    /**
     * Get recent batch operations
     */
    public function get_recent_batch_operations($limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}{$this->batch_log_table}
            ORDER BY timestamp DESC
            LIMIT %d",
            $limit
        ));
    }
} 