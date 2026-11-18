<?php

/**
 * Plugin Name: Remove WP Default Link Header
 * Description: Removes default WordPress RESTful API, shortlink, and XML-RPC links and headers.
 * Version: 1.0.0
 * Author: markxp
 * Text Domain: remove-wp-default-link-header
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 7.1
 * Requires PHP: 8.4
 *
 * @package RemoveWpDefaultLinkHeader
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 1. Register Self-Contained PSR-4 Autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'RemoveWpDefaultLinkHeader\\';
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
new \RemoveWpDefaultLinkHeader\Plugin();
