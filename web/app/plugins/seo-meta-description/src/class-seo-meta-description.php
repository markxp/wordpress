<?php

/**
 * Main Plugin Class - Resolves and prints <meta name="description"> in wp_head.
 *
 * @package SEO_Meta_Description
 */

declare(strict_types=1);

namespace SEO_Meta_Description;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Plugin
{
    public const MAX_LENGTH = 160;

    public function __construct()
    {
        add_action('wp_head', [$this, 'render_meta_description'], 1);
    }

    /**
     * Resolve and print <meta name="description" content="..."> in wp_head.
     */
    public function render_meta_description(): void
    {
        $description = $this->resolve_description();
        $description = (string) apply_filters('seo_meta_description_content', $description);

        if ($description === '') {
            return;
        }

        echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
    }

    /**
     * Determine description from singular post excerpt/content, taxonomy description, or site tagline.
     */
    public function resolve_description(): string
    {
        if (function_exists('is_404') && is_404()) {
            return '';
        }

        $text = '';

        if ((function_exists('is_front_page') && is_front_page()) || (function_exists('is_home') && is_home())) {
            $text = (string) get_bloginfo('description');
        }

        if ($text === '' && function_exists('is_singular') && is_singular()) {
            $post = get_queried_object();
            if (is_object($post)) {
                if (!empty($post->post_excerpt) && is_string($post->post_excerpt)) {
                    $text = $post->post_excerpt;
                } elseif (!empty($post->post_content) && is_string($post->post_content)) {
                    $text = $post->post_content;
                    if (function_exists('excerpt_remove_blocks')) {
                        $text = excerpt_remove_blocks($text);
                    }
                    if (function_exists('strip_shortcodes')) {
                        $text = strip_shortcodes($text);
                    }
                }
            }
        }

        if (
            $text === ''
            && (
                (function_exists('is_category') && is_category())
                || (function_exists('is_tag') && is_tag())
                || (function_exists('is_tax') && is_tax())
            )
            && function_exists('term_description')
        ) {
            $text = (string) term_description();
        }

        if ($text === '') {
            $text = (string) get_bloginfo('description');
        }

        $text = wp_strip_all_tags($text, true);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH - 1, 'UTF-8')) . '…';
        }

        return $text;
    }
}
