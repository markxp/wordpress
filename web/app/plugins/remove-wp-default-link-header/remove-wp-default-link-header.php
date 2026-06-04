<?php

/**
 * Plugin Name: Remove WP Default Link Header
 * Description: Removes default WordPress RESTful API, shortlink, and XML-RPC links and headers.
 * Version: 1.0.0
 * Author: Antigravity
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Remove default WordPress links and headers.
 */
function remove_wp_default_links_and_headers()
{
    // 1. Remove REST API links and headers.
    // Remove the REST API link tag from the HTML head.
    remove_action('wp_head', 'rest_output_link_wp_head', 10);
    // Remove the Link header from HTTP responses.
    remove_action('template_redirect', 'rest_output_link_header', 11);
    // Remove the REST API link from XML-RPC responses.
    remove_action('xmlrpc_rpc_methods', 'rest_output_link_wp_head');

    // 2. Remove shortlink links and headers.
    // Remove the shortlink link tag from the HTML head.
    remove_action('wp_head', 'wp_shortlink_wp_head', 10);
    // Remove the shortlink Link header from HTTP responses.
    remove_action('template_redirect', 'wp_shortlink_header', 11);

    // 3. Remove XML-RPC / Pingback links and headers.
    // Remove the RSD (Really Simple Discovery) link from the HTML head.
    remove_action('wp_head', 'rsd_link');
    // Remove the Windows Live Writer manifest link from the HTML head.
    remove_action('wp_head', 'wlwmanifest_link');
}
add_action('init', 'remove_wp_default_links_and_headers');

// Disable XML-RPC entirely.
add_filter('xmlrpc_enabled', '__return_false');

// Remove X-Pingback HTTP header.
add_filter('wp_headers', function ($headers) {
    if (isset($headers['X-Pingback'])) {
        unset($headers['X-Pingback']);
    }
    return $headers;
}, 10, 1);
