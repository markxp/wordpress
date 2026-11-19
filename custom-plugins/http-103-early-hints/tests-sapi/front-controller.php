<?php

declare(strict_types=1);

header('X-PHP-SAPI: ' . PHP_SAPI);

if (isset($_GET['sapi_health'])) {
    echo 'ok';
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['sapi_actions'] = [];
$GLOBALS['sapi_filters'] = [];

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): void
{
    $GLOBALS['sapi_actions'][$hook][$priority][] = $cb;
}
function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): void
{
    $GLOBALS['sapi_filters'][$hook][$priority][] = $cb;
}
function apply_filters(string $hook, $value, ...$args)
{
    if (!isset($GLOBALS['sapi_filters'][$hook])) {
        return $value;
    }
    ksort($GLOBALS['sapi_filters'][$hook]);
    foreach ($GLOBALS['sapi_filters'][$hook] as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }
    return $value;
}
function do_action(string $hook, ...$args): void
{
    if (!isset($GLOBALS['sapi_actions'][$hook])) {
        return;
    }
    ksort($GLOBALS['sapi_actions'][$hook]);
    foreach ($GLOBALS['sapi_actions'][$hook] as $callbacks) {
        foreach ($callbacks as $cb) {
            $cb(...$args);
        }
    }
}
function is_admin(): bool
{
    return false;
}
function is_feed(): bool
{
    return false;
}
function is_404(): bool
{
    return false;
}
function wp_is_json_request(): bool
{
    return false;
}
function wp_doing_ajax(): bool
{
    return false;
}
function wp_is_xml_request(): bool
{
    return false;
}
function did_action(string $hook): int
{
    return 0;
}
function remove_action(string $hook, $cb, int $priority = 10): bool
{
    return true;
}
function get_stylesheet(): string
{
    return 'site';
}
function site_url(string $path = ''): string
{
    return 'http://localhost' . $path;
}
function content_url(string $path = ''): string
{
    return 'http://localhost/app' . $path;
}
function add_query_arg(string $key, string $value, string $url): string
{
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . rawurlencode($key) . '=' . rawurlencode($value);
}
function plugin_basename(string $file): string
{
    return 'http-103-early-hints/http-103-early-hints.php';
}
function get_option(string $name, $default = false)
{
    if ($name === 'http_103_early_hints_options') {
        return ['enabled' => true, 'preload_styles' => true, 'preload_scripts' => true];
    }
    return $default;
}
function home_url(string $path = ''): string
{
    return 'http://localhost' . $path;
}
function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}
function esc_url_raw(string $url): string
{
    return $url;
}
function absint($val): int
{
    return abs((int) $val);
}

class WP_Styles
{
    public array $queue = ['theme-style'];
    public array $registered = [];
    public array $to_do = [];
    public function __construct()
    {
        $this->registered['theme-style'] = (object) ['src' => '/app/themes/site/style.css', 'ver' => '1.0'];
    }
    public function all_deps($queue): bool
    {
        $this->to_do = $this->queue;
        return true;
    }
    public function get_data(string $handle, string $key)
    {
        return null;
    }
}

class WP_Scripts
{
    public array $queue = ['app-js'];
    public array $registered = [];
    public array $to_do = [];
    public function __construct()
    {
        $this->registered['app-js'] = (object) ['src' => '/app/themes/site/app.js', 'ver' => '1.0'];
    }
    public function all_deps($queue): bool
    {
        $this->to_do = $this->queue;
        return true;
    }
    public function get_data(string $handle, string $key)
    {
        return null;
    }
}

$GLOBALS['wp_styles'] = new WP_Styles();
$GLOBALS['wp_scripts'] = new WP_Scripts();

require_once dirname(__DIR__) . '/http-103-early-hints.php';

do_action('template_redirect');

echo 'early-hints-ok';
