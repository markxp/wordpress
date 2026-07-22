<?php

/**
 * Plugin Name: Remove WP Default Link Header
 * Description: Removes default WordPress RESTful API, shortlink, and XML-RPC links and headers.
 * Version: 1.0.0
 * Author: Antigravity
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 6.8
 * Requires PHP: 8.3
 *
 * @package Remove_WP_Default_Link_Header
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include the Main Plugin Class
require_once __DIR__ . '/src/class-remove-wp-default-link-header.php';

// Bootstrap the plugin
new \Remove_WP_Default_Link_Header\Plugin();
