<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trait for Post Handling Logic
 */
trait Bluesky_Post_Handler {
    
    /**
     * Handler called when a WordPress post's status transitions
     */
    public function handle_post($new_status, $old_status, $post) {
        $post_ID = $post->ID;

        // Basic validation
        if (!$this->is_valid_post($post_ID, $new_status)) {
            return;
        }

        $this->log_post_transition($post_ID, $old_status, $new_status);
            
        // Check if posting is explicitly ENABLED (Opt-In Model)
        if (!$this->is_posting_enabled($post_ID, $old_status)) {
            $this->log(sprintf(
                __("Final decision: Posting is DISABLED (Opt-In model) for Post ID %d. Skipping.", 'auto-poster-for-bluesky'),
                $post_ID
            ));
            return;
        }
        
        // Prevent duplicate posts
        if ($this->is_too_soon($post_ID)) {
            return;
        }

        // Update the last attempt time
        update_post_meta($post_ID, '_bluesky_last_attempt_time', time());
        
        // Post to Bluesky
        $this->log(__("Automatic posting is ENABLED (Opt-In), calling post_to_bluesky", 'auto-poster-for-bluesky'));
        $this->post_to_bluesky($post_ID, $post);
    }

    /**
     * Check if post is valid for processing
     */
    private function is_valid_post($post_ID, $new_status) {
        if (get_post_type($post_ID) !== 'post') {
            return false;
        }
        
        if ($new_status !== 'publish' || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return false;
        }

        return true;
    }

    /**
     * Log post transition
     */
    private function log_post_transition($post_ID, $old_status, $new_status) {
        $this->log(sprintf(
            __("handle_post called for Post ID: %d (Transition: %s -> %s)", 'auto-poster-for-bluesky'),
            $post_ID,
            $old_status,
            $new_status
        ));
    }

    /**
     * Check if posting is enabled for this post
     */
    private function is_posting_enabled($post_ID, $old_status) {
        // Check POST data first for existing posts
        if ($old_status === 'publish' && isset($_POST['bluesky_post_settings_nonce_field'])) {
            return isset($_POST['bluesky_auto_post_enabled']);
        }

        // Check database meta for new posts
        $meta_value = get_post_meta($post_ID, '_bluesky_auto_post_enabled', true);
        return $meta_value === '1';
    }

    /**
     * Check if post was attempted too recently
     */
    private function is_too_soon($post_ID) {
        $last_attempt_time = (int) get_post_meta($post_ID, '_bluesky_last_attempt_time', true);
        $current_time = time();
        $time_diff = $current_time - $last_attempt_time;
        
        // Minimum interval (10 seconds)
        if ($time_diff < 10 && $last_attempt_time !== 0) {
            $this->log(sprintf(
                __("Skipping post ID %d: Called too soon (%d seconds since last attempt). This prevents duplicate posts.", 'auto-poster-for-bluesky'),
                $post_ID,
                $time_diff
            ));
            return true;
        }

        return false;
    }

    /**
     * Render the post settings field
     */
    public function render_post_settings_field($post) {
        wp_nonce_field('bluesky_post_settings_nonce', 'bluesky_post_settings_nonce_field');

        $is_enabled = get_post_meta($post->ID, '_bluesky_auto_post_enabled', true);
        $checked = checked('1', $is_enabled, false);
        
        echo '<label for="bluesky_auto_post_enabled">';
        echo '<input type="checkbox" id="bluesky_auto_post_enabled" name="bluesky_auto_post_enabled" value="1" ' . $checked . '/> ';
        echo '<strong>' . esc_html__('Enable automatic posting to Bluesky for this post.', 'auto-poster-for-bluesky') . '</strong>';
        echo '</label>';
        echo '<p class="description" style="margin-top: 10px;">' . esc_html__('Default is DISABLED. Check this box to allow posting.', 'auto-poster-for-bluesky') . '</p>';
    }

    /**
     * Save post settings
     */
    public function save_post_settings($post_id) {
        if (!$this->validate_save_post($post_id)) {
            return;
        }

        $is_enabled = isset($_POST['bluesky_auto_post_enabled']) ? 1 : 0;

        if ($is_enabled === 1) {
            update_post_meta($post_id, '_bluesky_auto_post_enabled', '1');
            $this->log(sprintf(__("Post ID %d: Bluesky posting set to ENABLED", 'auto-poster-for-bluesky'), $post_id));
        } else {
            delete_post_meta($post_id, '_bluesky_auto_post_enabled');
            $this->log(sprintf(__("Post ID %d: Bluesky posting set to DISABLED", 'auto-poster-for-bluesky'), $post_id));
        }
    }

    /**
     * Validate post save operation
     */
    private function validate_save_post($post_id) {
        if (!isset($_POST['bluesky_post_settings_nonce_field']) || 
            !wp_verify_nonce($_POST['bluesky_post_settings_nonce_field'], 'bluesky_post_settings_nonce')) {
            return false;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }
        
        if (!current_user_can('edit_post', $post_id) || get_post_type($post_id) !== 'post') {
            return false;
        }

        return true;
    }
}