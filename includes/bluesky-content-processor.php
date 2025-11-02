<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trait for Content Processing Logic
 */
trait Bluesky_Content_Processor {
    
    /**
     * Process post content for Bluesky
     */
    public function prepare_bluesky_content($post_ID, $post) {
        $permalink = get_permalink($post_ID);
        
        // Clean and prepare text content
        $text_content = $this->clean_post_content($post->post_content);
        $post_title = $post->post_title;
        
        // Combine title and content
        $content_only = sprintf("%s\n\n%s", $post_title, $text_content);
        
        // Trim content to fit Bluesky limits with permalink
        $trimmed_text = $this->trim_content_with_permalink($content_only, $permalink);
        
        // Calculate facets for link
        $facets = $this->calculate_link_facets($trimmed_text, $permalink);
        
        return [
            'text' => $trimmed_text,
            'facets' => $facets,
            'permalink' => $permalink
        ];
    }

    /**
     * Clean post content by removing unwanted elements
     */
    private function clean_post_content($post_content) {
        $text_content = $post_content;
        
        // Remove specific HTML elements
        $patterns = [
            '/<figure.*?>.*?<\/figure>/s',
            '/<blockquote.*?>.*?<\/blockquote>/s',
            '/<sup.*?>.*?<\/sup>/s',
            '/<h[1-6].*?>.*?<\/h[1-6]>/s',
            '/https?:\/\/\S+/i'
        ];
        
        foreach ($patterns as $pattern) {
            $text_content = preg_replace($pattern, '', $text_content);
        }
        
        // Clean up HTML tags and whitespace
        $text_content = preg_replace('/<[^>]*>/', '', $text_content);
        $text_content = html_entity_decode($text_content, ENT_QUOTES, 'UTF-8');
        $text_content = preg_replace('/([.!?])(?!\.)(?=\S)/', '$1 ', $text_content);
        $text_content = preg_replace('/\s+/', ' ', $text_content);
        
        return trim($text_content);
    }

    /**
     * Trim content to fit Bluesky character limit with permalink
     */
    private function trim_content_with_permalink($content, $permalink, $max_graphemes = 300) {
        $link_separator = "\n\n";
        $link_and_separator = $link_separator . $permalink;

        // Calculate lengths
        $link_length = $this->get_string_length($link_and_separator);
        $content_length = $this->get_string_length($content);

        if ($content_length + $link_length > $max_graphemes) {
            $trim_limit = $max_graphemes - $link_length;
            $this->log(sprintf(
                __("Content was too long (%d). Trimming content to %d to reserve space for permalink (%d).", 'auto-poster-for-bluesky'),
                $content_length,
                $trim_limit,
                $link_length
            ));
            
            $trimmed_content = $this->trim_text($content, $trim_limit);
            $final_text = $trimmed_content . $link_and_separator;
            
            // Ensure final text doesn't exceed limit
            $final_length = $this->get_string_length($final_text);
            if ($final_length > $max_graphemes) {
                $final_text = $this->substr_text($final_text, 0, $max_graphemes);
            }
            
            return $final_text;
        } else {
            $this->log(__("Content and permalink fit within the limit.", 'auto-poster-for-bluesky'));
            return $content . $link_and_separator;
        }
    }

    /**
     * Calculate facets for link in text
     */
    private function calculate_link_facets($text, $permalink) {
        $permalink_start_byte = strpos($text, $permalink);

        if ($permalink_start_byte === false) {
            $this->log(__("Warning: Permlink not found in text for facet calculation. The link may not be clickable.", 'auto-poster-for-bluesky'));
            return [];
        }

        $permalink_end_byte = $permalink_start_byte + strlen($permalink);

        return [
            [
                'index' => [
                    'byteStart' => $permalink_start_byte,
                    'byteEnd' => $permalink_end_byte,
                ],
                'features' => [
                    [
                        '$type' => 'app.bsky.richtext.facet#link',
                        'uri' => $permalink,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get string length using appropriate function
     */
    private function get_string_length($text) {
        if (function_exists('grapheme_strlen')) {
            return grapheme_strlen($text);
        }
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * Get substring using appropriate function
     */
    private function substr_text($text, $start, $length) {
        if (function_exists('grapheme_substr')) {
            return grapheme_substr($text, $start, $length);
        }
        return mb_substr($text, $start, $length, 'UTF-8');
    }

    /**
     * Trims text by grapheme unit
     */
    public function trim_text(string $text, int $max_graphemes): string {
        if (!function_exists('grapheme_strlen')) {
            // Fallback: Use mb_ functions
            if (mb_strlen($text, 'UTF-8') <= $max_graphemes) {
                return $text;
            }
            $trimmed_string = mb_substr($text, 0, $max_graphemes, 'UTF-8');
            $last_space = mb_strrpos($trimmed_string, ' ', 'UTF-8');
            if ($last_space !== false) {
                $trimmed_string = mb_substr($trimmed_string, 0, $last_space, 'UTF-8');
            }
        } else {
            // Use grapheme functions
            $grapheme_length = grapheme_strlen($text);
            if ($grapheme_length <= $max_graphemes) {
                return $text;
            }
            $trimmed_string = grapheme_substr($text, 0, $max_graphemes);
            if ($trimmed_string === false) {
                return '';
            }
            $last_space = grapheme_strrpos($trimmed_string, ' ');
            if ($last_space !== false) {
                $trimmed_string = grapheme_substr($trimmed_string, 0, $last_space);
            }
        }
        $trimmed_string .= '…';
        return $trimmed_string;
    }
}