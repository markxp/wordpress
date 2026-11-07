<?php

/**
 * Plugin Name: SEO Meta Description
 * Description: Lightweight plugin that emits a clean <meta name="description"> tag in wp_head using the post excerpt, block content summary, taxonomy description, or site tagline.
 * Version: 1.0.0
 * Author: Antigravity
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 6.8
 * Requires PHP: 8.3
 *
 * @package SEO_Meta_Description
 */

declare(strict_types=1);

namespace SEO_Meta_Description;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once __DIR__ . '/src/class-seo-meta-description.php';

new Plugin();
