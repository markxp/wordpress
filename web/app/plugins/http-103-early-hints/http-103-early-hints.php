<?php

/**
 * Plugin Name: HTTP 103 Early Hints & Link Header Generator
 * Description: Intercepts enqueued styles and scripts, and outputs them as HTTP Link response headers to support CDN HTTP 103 Early Hints for faster page load.
 * Version: 1.0.0
 * Author: Antigravity
 * Text Domain: http-103-early-hints
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 6.8
 * Requires PHP: 8.3
 *
 * @package HTTP_103_Early_Hints
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 1. Include the Abstract Base Class (Core engine)
require_once __DIR__ . '/src/class-base.php';

// 2. Include the Main Plugin Class (Admin settings and initialization)
require_once __DIR__ . '/src/class-http-103-early-hints.php';

new \HTTP_103_Early_Hints\Plugin();
