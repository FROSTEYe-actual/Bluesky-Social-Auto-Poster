<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trait for Admin UI and Settings Management
 */
trait Bluesky_Admin_Handler {

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_options_page(
            __('Bluesky Settings', 'auto-poster-for-bluesky'),
            __('Bluesky Auto-Poster', 'auto-poster-for-bluesky'),
            'manage_options',
            'bluesky-settings',
            [$this, 'render_settings_page']
        );

        // Add log page submenu
        add_submenu_page(
            'bluesky-settings',
            __('Bluesky Log', 'auto-poster-for-bluesky'),
            __('View Log', 'auto-poster-for-bluesky'),
            'manage_options',
            'bluesky-log',
            [$this, 'render_log_page']
        );
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'auto-poster-for-bluesky'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('bluesky_settings');
        do_settings_sections('bluesky-settings');
        submit_button();
        
        // Log button: admin.php?page=bluesky-log
        echo '<div style="margin-top: 20px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=bluesky-log')) . '" class="button button-secondary">' . esc_html__('View Log', 'auto-poster-for-bluesky') . '</a>';
        echo '</div>';
        
        echo '</form>';
        echo '</div>';
    }
    
	/**
	* Render log page
	*/
	public function render_log_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'auto-poster-for-bluesky'));
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Bluesky Activity Log', 'auto-poster-for-bluesky') . '</h1>';
    
		// Handle cleared message
		if (isset($_GET['cleared'])) {
			if ($_GET['cleared'] == 1) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Log file successfully cleared.', 'auto-poster-for-bluesky') . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to clear log file.', 'auto-poster-for-bluesky') . '</p></div>';
			}
		}

		$log_content = $this->get_log_content();

		if (empty($log_content)) {
			echo '<div class="notice notice-info">';
			echo '<p><strong>' . esc_html__('Log file not found or empty.', 'auto-poster-for-bluesky') . '</strong></p>';
			echo '<p>' . esc_html__('No plugin activity has been logged yet, or logging is currently disabled in the main settings.', 'auto-poster-for-bluesky') . '</p>';
			echo '</div>';
        
			// Back button even when no log content
			echo '<div style="margin-top: 20px;">';
			echo '<a href="' . esc_url(admin_url('options-general.php?page=bluesky-settings')) . '" class="button button-secondary">' . esc_html__('← Back to Settings', 'auto-poster-for-bluesky') . '</a>';
			echo '</div>';
		} else {
        
			// 1. Action Buttons (Clear & Download) with Back button
			echo '<div class="tablenav top">';
			echo '<div class="alignleft actions">';
        
			// Clear Log Button
			$clear_url = wp_nonce_url(admin_url('admin.php?page=bluesky-log&action=clear_log'), 'clear_bluesky_log');
			echo '<a href="' . esc_url($clear_url) . '" class="button button-secondary" onclick="return confirm(\'' . esc_js(__('Are you sure you want to clear the entire log file?', 'auto-poster-for-bluesky')) . '\');">' . esc_html__('Clear Log', 'auto-poster-for-bluesky') . '</a>';
        
			// Download Log Button (with margin for spacing)
			$download_url = wp_nonce_url(admin_url('admin.php?page=bluesky-log&action=download_log'), 'download_bluesky_log');
			echo '<a href="' . esc_url($download_url) . '" class="button button-primary" style="margin-left: 10px;">' . esc_html__('Download Log', 'auto-poster-for-bluesky') . '</a>';
        
			// Back to Settings Button (with margin for spacing)
			echo '<a href="' . esc_url(admin_url('options-general.php?page=bluesky-settings')) . '" class="button button-secondary" style="margin-left: 10px;">' . esc_html__('← Back to Settings', 'auto-poster-for-bluesky') . '</a>';
        
			echo '</div>';
			echo '</div>'; // .tablenav top
        
			// 2. Log Content
			echo '<div style="margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #ccc; max-height: 700px; overflow: auto;">';
			echo '<pre>' . esc_html($log_content) . '</pre>';
			echo '</div>';
		}

		echo '</div>'; // .wrap
	}

    /**
     * Register plugin settings
     */
    public function register_settings() {
        add_settings_section(
            'bluesky_settings_section',
            __('Bluesky Credentials', 'auto-poster-for-bluesky'),
            null,
            'bluesky-settings'
        );

        add_settings_field(
            'bluesky_identifier',
            __('Bluesky Identifier (DID or Handle)', 'auto-poster-for-bluesky'),
            [$this, 'render_identifier_field'],
            'bluesky-settings',
            'bluesky_settings_section'
        );
        register_setting('bluesky_settings', 'bluesky_identifier');

        add_settings_field(
            'bluesky_password',
            __('Bluesky App Password', 'auto-poster-for-bluesky'),
            [$this, 'render_password_field'],
            'bluesky-settings',
            'bluesky_settings_section'
        );
        // Register password field with a custom sanitizer
        register_setting('bluesky_settings', 'bluesky_password', ['sanitize_callback' => [$this, 'sanitize_password_setting']]);

        add_settings_section(
            'bluesky_logging_section',
            __('Logging Settings', 'auto-poster-for-bluesky'),
            [$this, 'render_logging_section_description'],
            'bluesky-settings'
        );

        add_settings_field(
            'bluesky_enable_logging',
            __('Enable Logging', 'auto-poster-for-bluesky'),
            [$this, 'render_logging_field'],
            'bluesky-settings',
            'bluesky_logging_section'
        );
        register_setting('bluesky_settings', 'bluesky_enable_logging', [
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => [$this, 'sanitize_checkbox']
        ]);
    }
    
    /**
     * Sanitize checkbox value
     */
    public function sanitize_checkbox($input) {
        return $input ? '1' : '0';
    }
    
    /**
     * Sanitize and conditionally update the password setting.
     */
    public function sanitize_password_setting($new_value) {
        // Keeps existing password if the new value is empty
        if (empty($new_value)) {
            return get_option('bluesky_password');
        }
        return sanitize_text_field($new_value);
    }

    /**
     * Render logging section description
     */
    public function render_logging_section_description() {
        echo '<p>' . esc_html__('Configure logging behavior for the plugin.', 'auto-poster-for-bluesky') . '</p>';
    }

    /**
     * Render identifier input field
     */
    public function render_identifier_field() {
        echo '<input type="text" name="bluesky_identifier" value="' . esc_attr(get_option('bluesky_identifier')) . '" class="regular-text" placeholder="예: your.handle.bsky.social 또는 did:plc:..." />';
    }

    /**
     * Render password input field
     */
	public function render_password_field() {
		$has_password = !empty(get_option('bluesky_password'));
		$placeholder = $has_password ? '********' : 'App Password';
			echo '<input type="password" name="bluesky_password" value="" class="regular-text" placeholder="' . esc_attr($placeholder) . '" />';
		if ($has_password) {
			echo '<p class="description">' . esc_html__('A password is already set. Enter a new one only to change it.', 'auto-poster-for-bluesky') . '</p>';
		}
	}

    /**
     * Render logging enable field
     */
    public function render_logging_field() {
        $enable_logging = get_option('bluesky_enable_logging', '0');
        $checked = checked('1', $enable_logging, false);
        
        echo '<label for="bluesky_enable_logging">';
        echo '<input type="checkbox" id="bluesky_enable_logging" name="bluesky_enable_logging" value="1" ' . $checked . '/> ';
        echo esc_html__('Enable plugin activity logging', 'auto-poster-for-bluesky');
        echo '</label>';
        echo '<p class="description">' . esc_html__('When enabled, the plugin will log all activities to a log file.<br />This is useful for debugging but may impact performance on high-traffic sites.<br />Recommend enabling this feature only when you really need it.', 'auto-poster-for-bluesky') . '</p>';
        echo '<p class="description"><strong>' . esc_html__('Default: OFF', 'auto-poster-for-bluesky') . '</strong></p>';
    }

    /**
     * Add custom meta box for post settings
     */
    public function add_custom_meta_box() {
        add_meta_box(
            'bluesky_post_settings',
            __('Auto-Poster Setting', 'auto-poster-for-bluesky'),
            [$this, 'render_post_settings_field'],
            'post',
            'side',
            'default'
        );
    }

    /**
     * Add settings link to plugin list page
     * @param array $links Existing action links
     * @return array Modified links array
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=bluesky-settings')) . '">' . esc_html__('Settings', 'auto-poster-for-bluesky') . '</a>';
        
        array_unshift($links, $settings_link);
        
        return $links;
    }
}