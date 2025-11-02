<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trait for Media Handling and Upload Logic
 */
trait Bluesky_Media_Handler {

    /**
     * Get image attachments (IDs) from a post.
     * Prioritizes the featured image.
     */
    private function get_post_images($post_ID, $post) {
        $image_ids = [];

        // 1. Check for Featured Image
        $thumbnail_id = get_post_thumbnail_id($post_ID);
        if ($thumbnail_id) {
            $image_ids[] = $thumbnail_id;
        }

        // 2. Get other attached images (max 4 total for Bluesky)
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => 4, // Max images for Bluesky is 4
            'post_parent'    => $post_ID,
            'exclude'        => [$thumbnail_id], // Avoid duplication
            'post_mime_type' => 'image',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ]);

        foreach ($attachments as $att) {
            if (count($image_ids) >= 4) {
                break;
            }
            $image_ids[] = $att->ID;
        }

        return $image_ids;
    }

    /**
     * Uploads an image attached to the post (by ID) to Bluesky.
     */
    private function upload_media($attachment_id, $access_token) {
        $image_url = wp_get_attachment_url($attachment_id);
        
        if (!$image_url) {
            $this->log(sprintf(__("Attachment ID %d not found or no URL.", 'auto-poster-for-bluesky'), $attachment_id));
            return null;
        }

        // Get image alt text for alt attribute
        $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        
        $blob = $this->upload_image_to_bluesky($image_url, $access_token);
        
        if ($blob) {
            return [
                'alt' => $alt_text ?: '',
                'image' => $blob,
            ];
        }

        return null;
    }

    /**
     * Uploads an image from an external URL (used for OG thumb) to Bluesky.
     * Returns the required Bluesky embed structure.
     */
    private function upload_media_from_url($image_url, $access_token) {
        $blob = $this->upload_image_to_bluesky($image_url, $access_token);
        
        if ($blob) {
            return [
                'alt' => '', // Alt text for external OG image is typically unavailable
                'image' => $blob,
            ];
        }

        return null;
    }
}