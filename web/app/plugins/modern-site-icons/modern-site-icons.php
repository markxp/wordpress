<?php

/**
 * Plugin Name: Modern Site Icons
 * Description: Emits modern multi-format favicon and Web App Manifest tags via site_icon_meta_tags when static root icon assets are present.
 * Version: 1.0.0
 * Author: Antigravity
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 6.8
 * Requires PHP: 8.3
 *
 * @package Modern_Site_Icons
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once __DIR__ . '/src/Plugin.php';

new \Modern_Site_Icons\Plugin();
