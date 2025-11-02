<?php
/*
* Plugin Name: Auto-Poster for Bluesky
* Description: Automatically shares WordPress posts to Bluesky.
* Version: 0.9.3 (Modified for Log Performance)
* Author: FROSTEYe (Modified for Performance)
* Author URI: https://frosteye.net/
* License: GPLv2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: auto-poster-for-bluesky
* Domain Path: /languages
*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Include required files
require_once dirname(__FILE__) . '/includes/bluesky-api-handler.php';
require_once dirname(__FILE__) . '/includes/bluesky-log-handler.php';
require_once dirname(__FILE__) . '/includes/bluesky-admin-handler.php';
require_once dirname(__FILE__) . '/includes/bluesky-post-handler.php';
require_once dirname(__FILE__) . '/includes/bluesky-content-processor.php';
require_once dirname(__FILE__) . '/includes/bluesky-media-handler.php';

class Simple_Bluesky_Poster {
    use Bluesky_API_Handler;
    use Bluesky_Log_Handler;
    use Bluesky_Admin_Handler;
    use Bluesky_Post_Handler;
    use Bluesky_Content_Processor;
    use Bluesky_Media_Handler;

    private $identifier;
    private $password;
    private $log_file;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_file = trailingslashit($upload_dir['basedir']) . 'bluesky_poster_log.txt';

        $this->setup_hooks();
        $this->initialize_filesystem();
    }

    /**
     * Set up WordPress hooks and filters
     */
    private function setup_hooks() {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        add_action('add_meta_boxes', [$this, 'add_custom_meta_box']);
        add_action('save_post', [$this, 'save_post_settings']);
        
        // Main handler for post transitions (from old_status to new_status)
        add_action('transition_post_status', [$this, 'handle_post'], 10, 3);
        
        // Log handler hooks
        add_action('admin_init', [$this, 'handle_log_actions']);
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('auto-poster-for-bluesky', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    private function get_session_data() {
        $session_data = get_option('bluesky_session_data', []);
        
        if (!empty($session_data) && $session_data['expiresAt'] > time() + 300) {
            return $session_data;
        }

        return $this->authenticate();
    }

    /**
     * Log post transition information
     */
    private function log_post_transition($post_ID, $old_status, $new_status) {
        $this->log(sprintf(
            __("Post ID %d status transition: %s -> %s", 'auto-poster-for-bluesky'),
            $post_ID,
            $old_status,
            $new_status
        ));
    }

    /**
     * Main function to post to Bluesky including media and logic checks
     */
    private function post_to_bluesky_with_media($post_ID, $post) {
        $session_data = $this->get_session_data();

        if (empty($session_data) || empty($session_data['accessJwt'])) {
            $this->log(__("Posting skipped. Not authenticated with Bluesky.", 'auto-poster-for-bluesky'));
            return false;
        }

        $access_token = $session_data['accessJwt'];
        $repo = $session_data['did'];

        // 1. Prepare Content
        $content_data = $this->prepare_bluesky_content($post_ID, $post);
        $text = $content_data['text'];
        $facets = $content_data['facets'];
        $permalink = $content_data['permalink'];
        
        // 2. Upload Media (if available)
        $images = $this->get_post_images($post_ID, $post);
        $uploaded_images = [];
        if (!empty($images)) {
            $this->log(sprintf(__("Found %d images for Post ID %d. Attempting to upload.", 'auto-poster-for-bluesky'), count($images), $post_ID));
            foreach ($images as $image_id) {
                $upload_result = $this->upload_media($image_id, $access_token);
                if ($upload_result) {
                    $uploaded_images[] = $upload_result;
                }
                // Bluesky max images is 4
                if (count($uploaded_images) >= 4) {
                    break;
                }
            }
        }
        
        // 3. Prepare Post Data (with embedded media and link card)
        $post_data = [
            'repo' => $repo,
            'collection' => 'app.bsky.feed.post',
            'record' => [
                '$type' => 'app.bsky.feed.post',
                'text' => $text,
                'createdAt' => gmdate('Y-m-d\TH:i:s\.v\Z'),
                'facets' => $facets,
            ],
        ];

        // Add embedded image if uploaded
        if (!empty($uploaded_images)) {
            $post_data['record']['embed'] = [
                '$type' => 'app.bsky.embed.images',
                'images' => $uploaded_images,
            ];
        } else {
            // Add link card (OpenGraph) if no image
            $og_data = $this->get_og_data($permalink);
            if (!empty($og_data)) {
                $link_card = [
                    '$type' => 'app.bsky.embed.external',
                    'external' => [
                        'uri' => $permalink,
                        'title' => $og_data['title'] ?? $post->post_title,
                        'description' => $og_data['description'] ?? '',
                    ]
                ];
                // Add external thumb if available
                if (!empty($og_data['image'])) {
                    $thumb_upload = $this->upload_media_from_url($og_data['image'], $access_token);
                    if ($thumb_upload) {
                        $link_card['external']['thumb'] = $thumb_upload['image'];
                    }
                }
                $post_data['record']['embed'] = $link_card;
            }
        }
        
        // 4. Send the post to Bluesky
        $post_url = 'https://bsky.social/xrpc/com.atproto.repo.createRecord';
        
        $this->log(sprintf(__("Sending post to Bluesky API: %s", 'auto-poster-for-bluesky'), wp_json_encode($post_data)));

        $post_response = wp_remote_post($post_url, [
            'body' => wp_json_encode($post_data),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$session_data['accessJwt']}"
            ],
            'sslverify' => true,
            'timeout' => 30,
        ]);
        
        $result = true; // Assume success initially

        if (is_wp_error($post_response)) {
            $this->log(sprintf(
                __("Bluesky post failed: %s", 'auto-poster-for-bluesky'),
                $post_response->get_error_message()
            ));
            $result = false;
        } elseif (wp_remote_retrieve_response_code($post_response) != 200) {
            $this->log(sprintf(
                __('Bluesky post failed. Status code: %1$d, Body: %2$s', 'auto-poster-for-bluesky'),
                wp_remote_retrieve_response_code($post_response),
                wp_remote_retrieve_body($post_response)
            ));
            $result = false;
        } else {
            $response_body = json_decode(wp_remote_retrieve_body($post_response), true);
            $this->log(sprintf(
                __("Successfully posted to Bluesky. URI: %s", 'auto-poster-for-bluesky'),
                $response_body['uri'] ?? 'N/A'
            ));
        }

        $this->maybe_rotate_log();

        return $result;
    }

    /**
     * Alias for post_to_bluesky_with_media for legacy/simplicity
     */
    public function post_to_bluesky($post_ID, $post) {
        return $this->post_to_bluesky_with_media($post_ID, $post);
    }
}

// Initialize the plugin
new Simple_Bluesky_Poster();