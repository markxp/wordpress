<?php

/**
 * Plugin Name: Modern Site Icons
 * Description: Emits modern multi-format favicon and Web App Manifest tags via site_icon_meta_tags when static root icon assets are present.
 * Version: 1.0.0
 * Author: markxp
 * Text Domain: modern-site-icons
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 7.1
 * Requires PHP: 8.4
 *
 * @package ModernSiteIcons
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 1. Register Self-Contained PSR-4 Autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'ModernSiteIcons\\';
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
new \ModernSiteIcons\Plugin();
