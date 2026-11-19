<?php

declare(strict_types=1);

header('X-PHP-SAPI: ' . PHP_SAPI);

if (isset($_GET['sapi_health'])) {
    echo 'ok';
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');
define('HOUR_IN_SECONDS', 3600);

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
function do_action(string $hook, ...$args): void
{
    foreach ($GLOBALS['sapi_actions'][$hook] ?? [] as $callbacks) {
        foreach ($callbacks as $cb) {
            $cb(...$args);
        }
    }
}
function apply_filters(string $hook, $value, ...$args)
{
    foreach ($GLOBALS['sapi_filters'][$hook] ?? [] as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }
    return $value;
}
function is_admin(): bool
{
    return false;
}
function is_404(): bool
{
    return false;
}
function is_front_page(): bool
{
    return true;
}
function is_home(): bool
{
    return true;
}
function is_singular(): bool
{
    return false;
}
function is_category(): bool
{
    return false;
}
function is_tag(): bool
{
    return false;
}
function is_tax(): bool
{
    return false;
}
function get_bloginfo(string $show = ''): string
{
    return $show === 'description' ? 'Cloud-native WordPress site' : '';
}
function get_option(string $name, $default = false)
{
    return $default;
}
function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function wp_strip_all_tags(string $text, bool $remove_breaks = false): string
{
    return trim(strip_tags($text));
}
function plugin_basename(string $file): string
{
    return 'auto-meta-description/auto-meta-description.php';
}

require_once dirname(__DIR__) . '/auto-meta-description.php';

ob_start();
do_action('wp_head');
$head = ob_get_clean();

echo $head;
