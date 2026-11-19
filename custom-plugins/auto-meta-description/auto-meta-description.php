<?php

/**
 * Plugin Name: Auto Meta Description
 * Description: Lightweight plugin that emits a clean <meta name="description"> tag in wp_head using the post excerpt, block content summary, taxonomy description, or site tagline.
 * Version: 1.0.0
 * Author: markxp
 * Text Domain: auto-meta-description
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 7.1
 * Requires PHP: 8.4
 *
 * @package AutoMetaDescription
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 1. Register Self-Contained PSR-4 Autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'AutoMetaDescription\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// 2. Bootstrap the plugin
new \AutoMetaDescription\Plugin();
