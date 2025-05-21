<?php
/**
 * Logger class for MyWorks QuickBooks Cost Adjuster
 *
 * @package MyWorks_QB_Cost_Adjuster
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
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
        $this->log_dir = WP_CONTENT_DIR . '/uploads/myworks-qb-cost-adjuster-logs/';
        
        // Create log directory if it doesn't exist
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
    public function log($message, $level = 'info') {
        // Always log messages regardless of setting
        $date = date('Y-m-d');
        $time = date('H:i:s');
        
        $log_file = $this->log_dir . 'cost-adjuster-' . $date . '.log';
        
        $log_entry = "[{$date} {$time}] [{$level}] {$message}" . PHP_EOL;
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
    
    /**
     * Get log files
     * 
     * @return array List of log files
     */
    public function get_log_files() {
        $files = array();
        
        if (file_exists($this->log_dir) && is_dir($this->log_dir)) {
            $files = glob($this->log_dir . 'cost-adjuster-*.log');
            $files = array_map('basename', $files);
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
        
        return '';
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
} 