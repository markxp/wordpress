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
$GLOBALS['sapi_removed_actions'] = [];

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): void
{
    $GLOBALS['sapi_actions'][$hook][$priority][] = $cb;
}
function add_filter(string $hook, $cb, int $priority = 10, int $args = 1): void
{
    $GLOBALS['sapi_filters'][$hook][$priority][] = $cb;
}
function remove_action(string $hook, $cb, int $priority = 10): bool
{
    $GLOBALS['sapi_removed_actions'][] = [$hook, $cb, $priority];
    return true;
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
            $value = is_callable($cb) ? $cb($value, ...$args) : $value;
        }
    }
    return $value;
}
function __return_false(): bool
{
    return false;
}

require_once dirname(__DIR__) . '/remove-wp-default-link-header.php';

do_action('init');

$headers = apply_filters('wp_headers', [
    'X-Pingback' => 'http://localhost/xmlrpc.php',
    'X-Custom' => 'kept',
]);

foreach ($headers as $k => $v) {
    header("{$k}: {$v}");
}

echo json_encode([
    'xmlrpc_enabled' => apply_filters('xmlrpc_enabled', true),
    'removed_actions_count' => count($GLOBALS['sapi_removed_actions']),
]);
