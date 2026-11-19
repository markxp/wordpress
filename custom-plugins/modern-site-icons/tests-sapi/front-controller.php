<?php

declare(strict_types=1);

header('X-PHP-SAPI: ' . PHP_SAPI);

if (isset($_GET['sapi_health'])) {
    echo 'ok';
    exit;
}

$tempWebRoot = sys_get_temp_dir() . '/modern_site_icons_sapi_' . getmypid();
@mkdir($tempWebRoot, 0777, true);
file_put_contents($tempWebRoot . '/icon.svg', '<svg></svg>');
file_put_contents($tempWebRoot . '/favicon.ico', 'ico');
file_put_contents($tempWebRoot . '/site.webmanifest', '{}');

define('ABSPATH', $tempWebRoot . '/');

$GLOBALS['sapi_filters'] = [];

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): void
{
    $GLOBALS['sapi_filters'][$hook][$priority][] = $cb;
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
function home_url(string $path = ''): string
{
    return 'http://localhost' . $path;
}
function esc_url(string $url): string
{
    return $url;
}
function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

require_once dirname(__DIR__) . '/modern-site-icons.php';

$tags = apply_filters('site_icon_meta_tags', []);
echo implode("\n", $tags);
