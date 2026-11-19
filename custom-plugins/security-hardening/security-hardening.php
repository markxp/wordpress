<?php

/**
 * Plugin Name: WordPress Security Hardening
 * Description: Secures the WordPress site systematically against data leaks, scraper bots, and username enumeration.
 * Version: 1.0.0
 * Author: markxp
 * Text Domain: security-hardening
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 7.1
 * Requires PHP: 8.4
 *
 * @package SecurityHardening
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 1. Register Self-Contained PSR-4 Autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'SecurityHardening\\';
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
new \SecurityHardening\Plugin();
