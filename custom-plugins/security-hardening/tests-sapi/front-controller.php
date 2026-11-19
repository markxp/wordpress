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
function add_filter(string $hook, $cb, int $priority = 10, int $args = 1): void
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
            $value = is_callable($cb) ? $cb($value, ...$args) : $value;
        }
    }
    return $value;
}
function is_admin(): bool
{
    return false;
}
function is_user_logged_in(): bool
{
    return false;
}
function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}
function rest_get_url_prefix(): string
{
    return 'wp-json';
}
function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}
function remove_action(string $hook, $cb, int $priority = 10): bool
{
    return true;
}
function remove_filter(string $hook, $cb, int $priority = 10): bool
{
    return true;
}
function nocache_headers(): void
{
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
}
function get_query_template(string $type): string
{
    return '/nonexistent-template.php';
}
$GLOBALS['wp_query'] = new class () {
    public function set_404(): void
    {
    }
};
function is_author(): bool
{
    return ($_GET['scenario'] ?? '') === 'author';
}
function status_header(int $code): void
{
    http_response_code($code);
}
function wp_die(string $message, string $title = '', array $args = []): void
{
    http_response_code((int) ($args['response'] ?? 403));
    echo $message;
    exit;
}
function __($text, $domain = 'default')
{
    return $text;
}
function __return_empty_string(): string
{
    return '';
}
function __return_false(): bool
{
    return false;
}
function plugin_basename(string $file): string
{
    return 'security-hardening/security-hardening.php';
}

class WP_Error
{
    private string $code;
    private string $message;
    private array $data;
    public function __construct(string $code = '', string $message = '', $data = '')
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = is_array($data) ? $data : ['status' => $data];
    }
    public function get_error_code(): string
    {
        return $this->code;
    }
    public function get_error_message(): string
    {
        return $this->message;
    }
    public function get_error_data(): array
    {
        return $this->data;
    }
}

require_once dirname(__DIR__) . '/security-hardening.php';

$scenario = $_GET['scenario'] ?? '';
if ($scenario === 'rest') {
    $res = apply_filters('rest_authentication_errors', null);
    if ($res instanceof WP_Error) {
        http_response_code((int) ($res->get_error_data()['status'] ?? 401));
        echo $res->get_error_code();
        exit;
    }
    echo 'allowed';
    exit;
}

if ($scenario === 'feed') {
    do_action('do_feed');
    exit;
}

if ($scenario === 'author') {
    do_action('template_redirect');
    exit;
}

echo 'ok';
