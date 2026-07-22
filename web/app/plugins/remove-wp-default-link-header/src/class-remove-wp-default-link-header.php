<?php

/**
 * Main Plugin Class - Removes default WordPress RESTful API, shortlink, and XML-RPC links and headers.
 *
 * @package Remove_WP_Default_Link_Header
 */

namespace Remove_WP_Default_Link_Header;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Plugin
{
    public function __construct()
    {
        // 1. Remove default WordPress links and headers.
        add_action('init', [$this, 'remove_wp_default_links_and_headers']);

        // 2. Disable XML-RPC entirely.
        add_filter('xmlrpc_enabled', '__return_false');

        // 3. Remove X-Pingback HTTP header.
        add_filter('wp_headers', [$this, 'remove_pingback_header'], 10, 1);
    }

    /**
     * Remove default WordPress links and headers from HTML head and HTTP responses.
     */
    public function remove_wp_default_links_and_headers()
    {
        // Remove REST API links and headers.
        remove_action('wp_head', 'rest_output_link_wp_head', 10);
        remove_action('template_redirect', 'rest_output_link_header', 11);
        remove_action('xmlrpc_rpc_methods', 'rest_output_link_wp_head');

        // Remove shortlink links and headers.
        remove_action('wp_head', 'wp_shortlink_wp_head', 10);
        remove_action('template_redirect', 'wp_shortlink_header', 11);

        // Remove XML-RPC / Pingback links.
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
    }

    /**
     * Filter HTTP response headers to remove X-Pingback.
     */
    public function remove_pingback_header($headers)
    {
        if (isset($headers['X-Pingback'])) {
            unset($headers['X-Pingback']);
        }
        return $headers;
    }
}
