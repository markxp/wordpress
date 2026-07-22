<?php

/**
 * Plugin Name: GCP IAP Auth & API Shield (Stateless)
 * Description: IAP authentication and REST API firewall controlled by system environment variables, protecting the backend while keeping the frontend open.
 * Version: 1.1.0
 * Author: Cheng Bo Yan
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 6.8
 * Requires PHP: 8.3
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Include Composer Autoload
$autoload_path = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
}

// 2. Read toggle environment variable (Protective mechanism, will not start if not set)
$iap_enabled = getenv('IAP_AUTH_ENABLED') ?: (defined('IAP_AUTH_ENABLED') ? IAP_AUTH_ENABLED : false);
if (filter_var($iap_enabled, FILTER_VALIDATE_BOOLEAN) === false) {
    return;
}

// 3. Include the Main Plugin Class
require_once __DIR__ . '/src/class-iap-auth-shield.php';

// 4. Bootstrap the plugin
new \IAP_Auth_Shield\Plugin();
