<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

trait Bluesky_API_Handler {
    
    /**
     * Extract OpenGraph metadata from a specific URL
     */
	private function get_og_data($url) {
		$this->log(__("Attempting to fetch OpenGraph data using DOMDocument.", 'auto-poster-for-bluesky'));
        $response = wp_remote_get($url, ['timeout' => 10, 'sslverify' => true]);
        
        $og_data = [];
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $this->log(sprintf(
                __("Failed to fetch OG data from URL %s: %s", 'auto-poster-for-bluesky'),
                $url,
                is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)
            ));
            return $og_data;
        }

        $html = wp_remote_retrieve_body($response);
                
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();

        if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html)) { 
            $this->log(__("Failed to load HTML content for OG parsing.", 'auto-poster-for-bluesky'));
            libxml_use_internal_errors(false);
            return $og_data;
        }
        libxml_use_internal_errors(false);
        
        $xpath = new DOMXPath($dom);
        
        $properties = ['title', 'description', 'image'];
        
        foreach ($properties as $property) {
            $nodes = $xpath->query("//meta[@property='og:{$property}']");
            
            if ($nodes->length > 0) {
                $content = $nodes->item(0)->getAttribute('content');
                if (!empty($content)) {
                    $og_data[$property] = $content;
                }
            }
        }

        if (empty($og_data['title']) || empty($og_data['description'])) {
             $this->log(__("Warning: Could not extract full OpenGraph metadata (title/description) from the URL.", 'auto-poster-for-bluesky'));
        }
       
        return $og_data;
    }

    /**
     * Upload image to Bluesky server and return blob reference
     */
    private function upload_image_to_bluesky($image_url, $access_token) {
        $upload_url = 'https://bsky.social/xrpc/com.atproto.repo.uploadBlob';
		$allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'];
    
		$path_info = pathinfo(parse_url($image_url, PHP_URL_PATH));
		$extension = strtolower($path_info['extension'] ?? '');
		$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];
    
		if (!in_array($extension, $allowed_extensions)) {
			$this->log(sprintf(__("Unsupported image extension: %s", 'auto-poster-for-bluesky'), $extension));
        return null;
		}
	
        // Convert relative URL to absolute URL if needed
        $parsed_url = parse_url($image_url);
        if (!isset($parsed_url['scheme']) || !isset($parsed_url['host'])) {
            $image_url = site_url($image_url);
            $this->log(sprintf(
                __("Converting relative image URL to absolute: %s", 'auto-poster-for-bluesky'),
                $image_url
            ));
        }

        // Fetch image data
        $image_data_response = wp_remote_get($image_url, ['sslverify' => true, 'timeout' => 10]);
        
        if (is_wp_error($image_data_response) || wp_remote_retrieve_response_code($image_data_response) !== 200) {
            $this->log(sprintf(
                __("Failed to fetch image: %s", 'auto-poster-for-bluesky'),
                is_wp_error($image_data_response) ? $image_data_response->get_error_message() : wp_remote_retrieve_response_code($image_data_response)
            ));
            return null;
        }

        $image_content = wp_remote_retrieve_body($image_data_response);
        $image_mime = wp_remote_retrieve_header($image_data_response, 'content-type');

        // Image MIME type validation
        if (empty($image_mime) || !in_array($image_mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'])) {
            $this->log(sprintf(__("Unsupported image MIME type: %s", 'auto-poster-for-bluesky'), $image_mime));
            return null;
        }
        
        // Upload image to Bluesky
        $upload_response = wp_remote_post($upload_url, [
            'body'    => $image_content,
            'headers' => [
                'Content-Type'  => $image_mime,
                'Authorization' => "Bearer $access_token"
            ],
            'sslverify' => true,
            'timeout' => 30,
        ]);

        if (is_wp_error($upload_response) || wp_remote_retrieve_response_code($upload_response) !== 200) {
            $this->log(sprintf(
                __('Image upload failed. Status code: %1$d, Body: %2$s', 'auto-poster-for-bluesky'),
                wp_remote_retrieve_response_code($upload_response),
                wp_remote_retrieve_body($upload_response)
            ));
            return null;
        }

        $blob_data = json_decode(wp_remote_retrieve_body($upload_response), true);
        return $blob_data['blob'] ?? null;
    }
}