<?php

/**
 * Plugin Name: HTTP Link Header Generator
 * Description: Intercepts enqueued styles and scripts, and outputs them as HTTP Link response headers for CDN HTTP 103 Early Hints.
 * Version: 1.0.0
 * Author: Antigravity
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class HTTP_Link_Header_Generator
{
    public function __construct()
    {
        // Hook into template_redirect to run asset inspection before template rendering and header outputs.
        add_action('template_redirect', [$this, 'inspect_and_emit_link_headers'], 1000);
    }

    /**
     * Trigger queue registrations early, run heuristics, and send HTTP Link headers.
     */
    public function inspect_and_emit_link_headers()
    {
        // Only run on the front-end and when headers haven't been sent.
        if (is_admin() || wp_is_json_request() || headers_sent()) {
            return;
        }

        global $wp_styles, $wp_scripts;

        // Initialize script/style handlers if not already loaded.
        if (!($wp_styles instanceof WP_Styles)) {
            $wp_styles = new WP_Styles();
        }
        if (!($wp_scripts instanceof WP_Scripts)) {
            $wp_scripts = new WP_Scripts();
        }

        // 1. Trigger the standard scripts and styles enqueuing hooks early.
        // This populates the $wp_styles->queue and $wp_scripts->queue.
        do_action('wp_enqueue_scripts');

        // 2. Resolve all dependencies so we get the complete list of assets that will be printed.
        $wp_styles->all_deps($wp_styles->queue);
        $wp_scripts->all_deps($wp_scripts->queue);

        $preload_candidates = [];
        $added_urls = [];

        // --- HEURISTIC 1: Explicitly Registered Preloads (wp_preload_resources) ---
        $preload_resources = apply_filters('wp_preload_resources', []);
        if (is_array($preload_resources)) {
            foreach ($preload_resources as $resource) {
                $href = $resource['href'] ?? '';
                $as = $resource['as'] ?? '';
                if ($href && $as) {
                    $url = $this->resolve_asset_url($href, null);
                    if (!in_array($url, $added_urls)) {
                        $preload_candidates[] = [
                            'url' => $url,
                            'as'  => $as,
                            'crossorigin' => isset($resource['crossorigin']) ? $resource['crossorigin'] : null,
                        ];
                        $added_urls[] = $url;
                    }
                }
            }
        }

        // --- HEURISTIC 2: Main Active Theme Stylesheet ---
        $theme_slug = get_stylesheet();
        $primary_styles = [];
        $other_styles = [];

        if (!empty($wp_styles->to_do) && is_array($wp_styles->to_do)) {
            foreach ($wp_styles->to_do as $handle) {
                if (!isset($wp_styles->registered[$handle])) {
                    continue;
                }
                $style = $wp_styles->registered[$handle];

                // Skip if no source URL or if print-only stylesheet.
                if (empty($style->src) || (isset($style->args) && $style->args === 'print')) {
                    continue;
                }

                $url = $this->resolve_asset_url($style->src, $style->ver);
                $is_primary = (strpos($handle, $theme_slug) !== false || strpos($handle, 'style') !== false || strpos($handle, 'theme') !== false);

                $asset = [
                    'url' => $url,
                    'as'  => 'style',
                ];

                if ($is_primary) {
                    $primary_styles[] = $asset;
                } else {
                    $other_styles[] = $asset;
                }
            }
        }

        // Add primary stylesheets.
        foreach ($primary_styles as $style_asset) {
            if (!in_array($style_asset['url'], $added_urls)) {
                $preload_candidates[] = $style_asset;
                $added_urls[] = $style_asset['url'];
            }
        }

        // --- HEURISTIC 3: Synchronous Header Scripts ---
        if (!empty($wp_scripts->to_do) && is_array($wp_scripts->to_do)) {
            foreach ($wp_scripts->to_do as $handle) {
                if (!isset($wp_scripts->registered[$handle])) {
                    continue;
                }
                $script = $wp_scripts->registered[$handle];

                if (empty($script->src)) {
                    continue;
                }

                // Check if enqueued in footer (group = 1).
                $group = $wp_scripts->get_data($handle, 'group');
                if ($group === 1 || $group === true) {
                    continue;
                }

                // Check strategy (defer or async) - skip if non-blocking.
                $strategy = '';
                if (method_exists($wp_scripts, 'get_data')) {
                    $strategy = $wp_scripts->get_data($handle, 'strategy');
                }
                if ($strategy === 'defer' || $strategy === 'async') {
                    continue;
                }

                $url = $this->resolve_asset_url($script->src, $script->ver);
                if (!in_array($url, $added_urls)) {
                    $preload_candidates[] = [
                        'url' => $url,
                        'as'  => 'script',
                    ];
                    $added_urls[] = $url;
                }
            }
        }

        // --- HEURISTIC 4: General Header Stylesheets ---
        foreach ($other_styles as $style_asset) {
            if (!in_array($style_asset['url'], $added_urls)) {
                $preload_candidates[] = $style_asset;
                $added_urls[] = $style_asset['url'];
            }
        }

        // Apply strict cap of 5 preload resources.
        $preload_candidates = array_slice($preload_candidates, 0, 5);

        // Generate and send the Link HTTP headers.
        if (!empty($preload_candidates)) {
            $link_headers = [];
            foreach ($preload_candidates as $candidate) {
                $header_part = "<{$candidate['url']}>; rel=preload; as={$candidate['as']}";
                if (!empty($candidate['crossorigin'])) {
                    $crossorigin = $candidate['crossorigin'];
                    $header_part .= ($crossorigin === true || $crossorigin === 'anonymous')
                        ? '; crossorigin'
                        : "; crossorigin={$crossorigin}";
                }
                $link_headers[] = $header_part;
            }

            header('Link: ' . implode(', ', $link_headers), false);
        }
    }

    /**
     * Resolve asset source URL to absolute or relative link, appending version if present.
     */
    private function resolve_asset_url($src, $ver)
    {
        // If relative to WordPress root or absolute, format correctly.
        if (strpos($src, 'http') !== 0 && strpos($src, '//') !== 0) {
            $src = site_url($src);
        }

        if ($ver) {
            $src = add_query_arg('ver', $ver, $src);
        }

        return $src;
    }
}

new HTTP_Link_Header_Generator();
