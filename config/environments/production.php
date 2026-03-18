<?php
/**
 * Configuration overrides for WP_ENV === 'production'
 */

use Roots\WPConfig\Config;
use function Env\env;

/**
 *
 * Example: `Config::define('WP_DEBUG', false);`
 */

Config::define('DISALLOW_INDEXING', env('DISALLOW_INDEXING') ?? false);
