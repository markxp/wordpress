<?php

/**
 * Plugin Name: HTTP 103 Early Hints & Link Header Generator
 * Description: Intercepts enqueued styles and scripts, and outputs them as HTTP Link response headers to support CDN HTTP 103 Early Hints for faster page load.
 * Version: 1.0.0
 * Author: markxp
 * Text Domain: http-103-early-hints
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 7.1
 * Requires PHP: 8.4
 *
 * @package Http103EarlyHints
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 1. Register Self-Contained PSR-4 Autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'Http103EarlyHints\\';
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
new \Http103EarlyHints\Plugin();
