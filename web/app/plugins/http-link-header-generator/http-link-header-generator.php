<?php

/**
 * Plugin Name: HTTP Link Header Generator
 * Description: Intercepts enqueued styles and scripts, and outputs them as HTTP Link response headers for CDN HTTP 103 Early Hints.
 * Version: 1.0.0
 * Author: Antigravity
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 6.8
 * Requires PHP: 8.3
 *
 * @package HTTP_Link_Header_Generator
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 1. Include the Abstract Base Class (Core engine)
require_once __DIR__ . '/src/class-base.php';

// 2. Include the Main Plugin Class (Admin settings and initialization)
require_once __DIR__ . '/src/class-http-link-header-generator.php';

new \HTTP_Link_Header_Generator\Plugin();
