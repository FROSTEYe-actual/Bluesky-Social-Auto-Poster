<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log Management and Log Page Rendering
 */
trait Bluesky_Log_Handler {
    
    private $use_wp_filesystem = false;
    private $is_filesystem_loaded = false;
    private $log_max_size = 4 * 1024 * 1024;


    /**
     * Check if logging is enabled
     */
    private function is_logging_enabled() {
        return get_option('bluesky_enable_logging', '0') === '1';
    }
    
    /**
     * Initialize filesystem (Directory check only - Lazy Loading Preparation)
     * NOTE: This still uses wp_mkdir_p which uses DIRECT method if possible, 
     * but this is generally safer than direct file writes.
     */
    private function initialize_filesystem() {
        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }
    }
    
    /**
     * Load WP_Filesystem API (Deferred Initialization)
     */
    private function load_wp_filesystem() {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        // Try WordPress Filesystem API first
        $filesystem_available = WP_Filesystem();
        global $wp_filesystem;
        
        $this->use_wp_filesystem = ($filesystem_available && $wp_filesystem);
        $this->is_filesystem_loaded = true; 
        
    }

    /**
     * Write to the log file
     */
    public function log(string $message) {
        if (!$this->is_logging_enabled()) {
            return;
        }
        
        if (!$this->is_filesystem_loaded) {
            $this->load_wp_filesystem();
        }

        if (!$this->use_wp_filesystem) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $log_entry = sprintf("[%s] %s\n", $timestamp, $message);

        global $wp_filesystem;
        
        $log_dir = dirname($this->log_file);
        if (!$wp_filesystem->is_dir($log_dir)) {
            $wp_filesystem->mkdir($log_dir);
        }

        $current_content = $wp_filesystem->get_contents($this->log_file);
        
        if ($current_content === false) {
            $current_content = '';
        }
        
        $new_content = $current_content . $log_entry;
        
        $wp_filesystem->put_contents($this->log_file, $new_content, FS_CHMOD_FILE);
    }

    public function maybe_rotate_log() {
        if (!$this->is_logging_enabled()) {
            return;
        }

        if (!$this->is_filesystem_loaded) {
            $this->load_wp_filesystem();
        }
        
        if (!$this->use_wp_filesystem) {
            return;
        }

        $log_size = $this->get_log_size();
        
        if ($log_size === false || $log_size <= $this->log_max_size) {
            return;
        }
        
        $this->log(sprintf(__("Log file size (%s) exceeded max limit (%s). Starting rotation.", 'auto-poster-for-bluesky'), size_format($log_size, 2), size_format($this->log_max_size, 2)));

        $content = $this->get_log_content();
        if ($content === false) {
            return;
        }
        
        $lines = explode("\n", $content);
        $new_lines = [];
        $current_size = 0;
        
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i] . "\n";
            $line_size = strlen($line);
            
            if ($current_size + $line_size > $this->log_max_size) {
                break;
            }
            
            array_unshift($new_lines, $line);
            $current_size += $line_size;
        }
        
        $new_content = implode('', $new_lines);
        
        global $wp_filesystem;
        if ($this->use_wp_filesystem) {
            $wp_filesystem->put_contents($this->log_file, $new_content, FS_CHMOD_FILE);
        }
        
        $this->log(__("Log rotation completed. Oldest entries removed.", 'auto-poster-for-bluesky'));
    }

    /**
     * Helper: Get log file size
     */
    private function get_log_size() {
        if ($this->use_wp_filesystem) {
            global $wp_filesystem;
            if ($wp_filesystem->exists($this->log_file)) {
                return $wp_filesystem->size($this->log_file);
            }
        }
        
        return false;
    }

    /**
     * Get log file content
     */
    public function get_log_content() {
        if (!$this->is_filesystem_loaded) {
            $this->load_wp_filesystem();
        }

        if ($this->use_wp_filesystem) {
            global $wp_filesystem;
            if ($wp_filesystem->exists($this->log_file)) {
                return $wp_filesystem->get_contents($this->log_file);
            }
        }
        
        return false;
    }

    /**
     * Handle log page actions (download/clear)
     */
    public function handle_log_actions() {
        if (isset($_GET['page']) && $_GET['page'] === 'bluesky-log') {
            if (isset($_GET['action']) && $_GET['action'] === 'download_log' && wp_verify_nonce($_GET['_wpnonce'], 'download_bluesky_log')) {
                $this->handle_log_download();
            } elseif ($_GET['action'] === 'clear_log' && wp_verify_nonce($_GET['_wpnonce'], 'clear_bluesky_log')) {
                $this->handle_log_clear();
            }
        }
    }
    
    /**
     * Handle log download
     */
    private function handle_log_download() {
        if (!$this->is_filesystem_loaded) {
            $this->load_wp_filesystem();
        }
        
        $log_content = $this->get_log_content();
        
        if ($log_content !== false) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="bluesky_poster_log.txt"');
            header('Content-Length: ' . strlen($log_content));
            echo $log_content;
            exit;
        }
    }
    
    /**
     * Handle log clear
     */
    private function handle_log_clear() {
        global $wp_filesystem;
        
        if (!$this->is_filesystem_loaded) {
            $this->load_wp_filesystem();
        }
        
        if ($this->use_wp_filesystem && $wp_filesystem && $wp_filesystem->exists($this->log_file)) {
            // Write empty content instead of deleting to keep the file structure ready
            $wp_filesystem->put_contents($this->log_file, '', FS_CHMOD_FILE); 
            wp_safe_redirect(admin_url('admin.php?page=bluesky-log&cleared=1'));
            exit;
        } 
        
        wp_safe_redirect(admin_url('admin.php?page=bluesky-log&cleared=0&failed=1'));
        exit;
    }
}