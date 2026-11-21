<?php

declare(strict_types=1);

header('X-PHP-SAPI: ' . PHP_SAPI);

if (isset($_GET['sapi_health'])) {
    echo 'ok';
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');
define('HOUR_IN_SECONDS', 3600);

putenv('IAP_AUTH_ENABLED=true');
$_ENV['IAP_AUTH_ENABLED'] = 'true';
putenv('IAP_AUDIENCE=/projects/123/global/backendServices/456');
$_ENV['IAP_AUDIENCE'] = '/projects/123/global/backendServices/456';

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
    return str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/wp-admin');
}
function is_user_logged_in(): bool
{
    return false;
}
function plugin_basename(string $file): string
{
    return 'iap-auth-shield/iap-auth-shield.php';
}
function __($text, $domain = 'default')
{
    return $text;
}
function esc_html(string $text): string
{
    return $text;
}
function wp_die(string $message, string $title = '', array $args = []): void
{
    http_response_code((int) ($args['response'] ?? 403));
    echo $message;
    exit;
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

$GLOBALS['pagenow'] = str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/wp-login.php') ? 'wp-login.php' : 'index.php';

require_once dirname(__DIR__) . '/iap-auth-shield.php';

if (($_GET['scenario'] ?? '') === 'rest_private') {
    $request = new class () {
        public function get_route(): string
        {
            return '/wp/v2/users';
        }
        public function get_method(): string
        {
            return 'GET';
        }
    };
    $res = apply_filters('rest_pre_dispatch', null, null, $request);
    if ($res instanceof WP_Error) {
        http_response_code((int) ($res->get_error_data()['status'] ?? 401));
        echo $res->get_error_code();
        exit;
    }
    echo 'allowed';
    exit;
}

if (($_GET['scenario'] ?? '') === 'rest_comments_post') {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
    $request = new class ($method) {
        public function __construct(private string $method)
        {
        }
        public function get_route(): string
        {
            return '/wp/v2/comments';
        }
        public function get_method(): string
        {
            return $this->method;
        }
    };
    $res = apply_filters('rest_pre_dispatch', null, null, $request);
    if ($res instanceof WP_Error) {
        http_response_code((int) ($res->get_error_data()['status'] ?? 401));
        echo $res->get_error_code();
        exit;
    }
    echo 'allowed';
    exit;
}

do_action('init');
echo 'ok';
