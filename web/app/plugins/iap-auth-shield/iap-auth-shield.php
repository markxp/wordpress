<?php

/**
 * Plugin Name: GCP IAP Auth & API Shield (Stateless)
 * Description: IAP authentication and REST API firewall controlled by system environment variables, protecting the backend while keeping the frontend open.
 * Version: 1.1.0
 * Author: markxp
 * Text Domain: iap-auth-shield
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 7.1
 * Requires PHP: 8.4
 *
 * @package IapAuthShield
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 1. Include standalone Composer autoloader if bundled locally
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// 2. Register Self-Contained PSR-4 Autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'IapAuthShield\\';
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

// 3. Bootstrap the plugin
new \IapAuthShield\Plugin();
