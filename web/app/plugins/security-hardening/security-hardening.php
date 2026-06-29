<?php
/**
 * Plugin Name:       WordPress Security Hardening
 * Plugin URI:        https://www.legispect.com/
 * Description:       Secures the WordPress site systematically against data leaks, scraper bots, and username enumeration.
 * Version:           1.0.0
 * Author:            Cheng Bo-Yan & Antigravity AI
 * Author URI:        https://www.legispect.com/
 * License:           MIT
 * Requires at least: 6.8
 * Requires PHP:      8.3
 *
 * @package Legispect_Security
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 1. Read toggle environment variable (Protective mechanism, defaults to false)
$enabled = getenv('SECURITY_HARDENING_ENABLED') ?: (defined('SECURITY_HARDENING_ENABLED') ? SECURITY_HARDENING_ENABLED : false);
if (filter_var($enabled, FILTER_VALIDATE_BOOLEAN) === false) {
    return;
}

// 2. Register Self-Contained PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'Legispect\\Security\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// 3. Bootstrap the plugin
new \Legispect\Security\Plugin();
