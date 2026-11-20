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
$GLOBALS['sapi_did_actions'] = [];
$GLOBALS['sapi_doing_actions'] = [];
$GLOBALS['sapi_head_trace'] = [];
$GLOBALS['sapi_style_engine_store'] = ['block-supports' => []];
$GLOBALS['_wp_current_template_content'] = null;

$tmpSmallCss = tempnam(sys_get_temp_dir(), 'hlhg_sapi_css_');
if (is_string($tmpSmallCss)) {
    file_put_contents($tmpSmallCss, '.wp-block-paragraph{margin:0}');
    register_shutdown_function(static function () use ($tmpSmallCss): void {
        @unlink($tmpSmallCss);
    });
} else {
    $tmpSmallCss = '';
}

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): void
{
    $GLOBALS['sapi_actions'][$hook][$priority][] = $cb;
}

function has_action(string $hook, $cb = false, $priority = false)
{
    if (empty($GLOBALS['sapi_actions'][$hook])) {
        return false;
    }

    ksort($GLOBALS['sapi_actions'][$hook]);

    if ($cb === false || $cb === null) {
        foreach ($GLOBALS['sapi_actions'][$hook] as $callbacks) {
            if (!empty($callbacks)) {
                return true;
            }
        }
        return false;
    }

    if ($priority !== false) {
        $prio = (int) $priority;
        return in_array($cb, $GLOBALS['sapi_actions'][$hook][$prio] ?? [], true);
    }

    foreach ($GLOBALS['sapi_actions'][$hook] as $prio => $callbacks) {
        if (in_array($cb, $callbacks, true)) {
            return (int) $prio;
        }
    }

    return false;
}

function remove_action(string $hook, $cb, int $priority = 10): bool
{
    if (empty($GLOBALS['sapi_actions'][$hook][$priority])) {
        return false;
    }

    $removed = false;
    foreach ($GLOBALS['sapi_actions'][$hook][$priority] as $idx => $registered) {
        if ($registered === $cb) {
            unset($GLOBALS['sapi_actions'][$hook][$priority][$idx]);
            $removed = true;
        }
    }

    if ($removed) {
        $GLOBALS['sapi_actions'][$hook][$priority] = array_values($GLOBALS['sapi_actions'][$hook][$priority]);
        if ($GLOBALS['sapi_actions'][$hook][$priority] === []) {
            unset($GLOBALS['sapi_actions'][$hook][$priority]);
        }
    }

    return $removed;
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
    $GLOBALS['sapi_did_actions'][$hook] = ($GLOBALS['sapi_did_actions'][$hook] ?? 0) + 1;
    $GLOBALS['sapi_doing_actions'][] = $hook;

    try {
        if (!isset($GLOBALS['sapi_actions'][$hook])) {
            return;
        }

        ksort($GLOBALS['sapi_actions'][$hook]);
        foreach ($GLOBALS['sapi_actions'][$hook] as $callbacks) {
            foreach ($callbacks as $cb) {
                $cb(...$args);
            }
        }
    } finally {
        array_pop($GLOBALS['sapi_doing_actions']);
    }
}

function did_action(string $hook): int
{
    return $GLOBALS['sapi_did_actions'][$hook] ?? 0;
}

function doing_action(?string $hook = null): bool
{
    if ($hook === null) {
        return !empty($GLOBALS['sapi_doing_actions']);
    }

    return in_array($hook, $GLOBALS['sapi_doing_actions'], true);
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

function wp_is_block_theme(): bool
{
    return true;
}

function current_theme_supports(string $feature): bool
{
    return $feature === 'block-templates';
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
    if ($name === 'hlhg_load_separate_assets') {
        return '1';
    }
    if ($name === 'hlhg_inline_size_limit') {
        return isset($_GET['inline_limit']) ? (string) max(0, (int) $_GET['inline_limit']) : '0';
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
    public array $queue = [];
    public array $registered = [];
    public array $to_do = [];
    public array $done = [];
    private array $data = [];

    public function add(string $handle, $src, array $deps = [], $ver = null, string $args = 'all'): bool
    {
        if (isset($this->registered[$handle])) {
            return false;
        }

        $this->registered[$handle] = (object) [
            'handle' => $handle,
            'src' => $src,
            'deps' => $deps,
            'ver' => $ver,
            'args' => $args,
            'extra' => [],
        ];

        return true;
    }

    public function enqueue(string $handle): void
    {
        if (!in_array($handle, $this->queue, true)) {
            $this->queue[] = $handle;
        }
    }

    public function all_deps($queue): bool
    {
        foreach ((array) $queue as $handle) {
            if (
                isset($this->registered[$handle])
                && !in_array($handle, $this->done, true)
                && !in_array($handle, $this->to_do, true)
            ) {
                $this->to_do[] = $handle;
            }
        }

        return true;
    }

    public function add_data(string $handle, string $key, $value): bool
    {
        if (!isset($this->registered[$handle])) {
            return false;
        }

        $this->data[$handle][$key] = $value;
        $this->registered[$handle]->extra[$key] = $value;

        return true;
    }

    public function get_data(string $handle, string $key)
    {
        return $this->registered[$handle]->extra[$key] ?? $this->data[$handle][$key] ?? null;
    }

    public function add_inline_style(string $handle, string $css): bool
    {
        if (!isset($this->registered[$handle])) {
            return false;
        }

        $after = $this->get_data($handle, 'after');
        if (!is_array($after)) {
            $after = [];
        }
        $after[] = $css;

        return $this->add_data($handle, 'after', $after);
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
        $this->to_do = (array) $queue;
        return true;
    }

    public function get_data(string $handle, string $key)
    {
        return null;
    }
}

class WP_Duotone
{
    public static array $block_css_declarations = [];
    public static array $used_global_styles_presets = [];

    public static function output_block_styles(): void
    {
        if (doing_action('wp_head')) {
            $GLOBALS['sapi_head_trace'][] = 'WP_Duotone::output_block_styles';
        }
        foreach (self::$block_css_declarations as $rule) {
            if (!in_array($rule, $GLOBALS['sapi_style_engine_store']['block-supports'], true)) {
                $GLOBALS['sapi_style_engine_store']['block-supports'][] = $rule;
            }
        }
    }

    public static function output_global_styles(): void
    {
        if (doing_action('wp_head')) {
            $GLOBALS['sapi_head_trace'][] = 'WP_Duotone::output_global_styles';
        }
        if (!empty(self::$used_global_styles_presets)) {
            wp_add_inline_style('global-styles', implode('', self::$used_global_styles_presets));
        }
    }
}

function wp_register_style(string $handle, $src, array $deps = [], $ver = null, string $media = 'all'): bool
{
    return $GLOBALS['wp_styles']->add($handle, $src, $deps, $ver, $media);
}

function wp_enqueue_style(string $handle): void
{
    $GLOBALS['wp_styles']->enqueue($handle);
}

function wp_add_inline_style(string $handle, string $data): bool
{
    return $GLOBALS['wp_styles']->add_inline_style($handle, $data);
}

function wp_enqueue_scripts(): void
{
    if (doing_action('wp_head')) {
        $GLOBALS['sapi_head_trace'][] = 'wp_enqueue_scripts';
    }
    do_action('wp_enqueue_scripts');
}

function wp_enqueue_global_styles(): void
{
    wp_register_style('global-styles', false);
    wp_add_inline_style('global-styles', ':root{--wp--preset--color--contrast:#111;}');
    wp_enqueue_style('global-styles');
}

function wp_enqueue_block_style_variation_styles(): void
{
    if (doing_action('wp_head')) {
        $GLOBALS['sapi_head_trace'][] = 'wp_enqueue_block_style_variation_styles';
    }
    wp_enqueue_style('block-style-variation-styles');
}

function wp_enqueue_block_custom_css(): void
{
    if (doing_action('wp_head')) {
        $GLOBALS['sapi_head_trace'][] = 'wp_enqueue_block_custom_css';
    }
    wp_enqueue_style('wp-block-custom-css');
}

function wp_enqueue_stored_styles(): void
{
    if (doing_action('wp_head')) {
        $GLOBALS['sapi_head_trace'][] = 'wp_enqueue_stored_styles';
    }
    $compiled = implode('', $GLOBALS['sapi_style_engine_store']['block-supports'] ?? []);
    if ($compiled !== '') {
        wp_register_style('core-block-supports', false);
        wp_add_inline_style('core-block-supports', $compiled);
        wp_enqueue_style('core-block-supports');
    }
}

function the_block_template_skip_link(): void
{
    echo '<script id="legacy-skip-link-footer"></script>';
}

function wp_enqueue_block_template_skip_link(): void
{
    if (doing_action('wp_head')) {
        $GLOBALS['sapi_head_trace'][] = 'wp_enqueue_block_template_skip_link';
    }

    if (!has_action('wp_footer', 'the_block_template_skip_link')) {
        return;
    }
    remove_action('wp_footer', 'the_block_template_skip_link');

    if (!current_theme_supports('block-templates')) {
        return;
    }

    if (empty($GLOBALS['_wp_current_template_content'])) {
        return;
    }

    wp_enqueue_style('wp-block-template-skip-link');
}

function wp_maybe_inline_styles(): void
{
    if (doing_action('wp_head')) {
        $GLOBALS['sapi_head_trace'][] = 'wp_maybe_inline_styles';
    }

    /** @var WP_Styles $wpStyles */
    $wpStyles = $GLOBALS['wp_styles'];
    $limit = (int) apply_filters('styles_inline_size_limit', 40000);
    if ($limit <= 0) {
        return;
    }

    $candidates = [];
    foreach ($wpStyles->queue as $handle) {
        if (!isset($wpStyles->registered[$handle])) {
            continue;
        }
        $src = $wpStyles->registered[$handle]->src;
        $path = $wpStyles->get_data($handle, 'path');
        if (is_string($src) && $src !== '' && is_string($path) && $path !== '' && is_file($path)) {
            $size = (int) filesize($path);
            $candidates[] = ['handle' => $handle, 'path' => $path, 'size' => $size];
        }
    }

    usort($candidates, static function (array $a, array $b): int {
        return $a['size'] <=> $b['size'];
    });

    $used = 0;
    foreach ($candidates as $item) {
        if (($used + $item['size']) > $limit) {
            break;
        }
        $css = (string) file_get_contents($item['path']);
        $wpStyles->registered[$item['handle']]->src = false;
        $after = $wpStyles->get_data($item['handle'], 'after');
        if (!is_array($after)) {
            $after = [];
        }
        array_unshift($after, $css);
        $wpStyles->add_data($item['handle'], 'after', $after);
        $used += $item['size'];
    }
}

function wp_print_styles(): void
{
    if (doing_action('wp_head')) {
        $GLOBALS['sapi_head_trace'][] = 'wp_print_styles';
    }

    /** @var WP_Styles $wpStyles */
    $wpStyles = $GLOBALS['wp_styles'];
    $wpStyles->all_deps($wpStyles->queue);

    foreach ($wpStyles->to_do as $key => $handle) {
        if (in_array($handle, $wpStyles->done, true) || !isset($wpStyles->registered[$handle])) {
            continue;
        }
        $style = $wpStyles->registered[$handle];
        if (is_string($style->src) && $style->src !== '') {
            $href = site_url($style->src);
            if (!empty($style->ver)) {
                $href = add_query_arg('ver', (string) $style->ver, $href);
            }
            echo '<link rel="stylesheet" id="' . $handle . '-css" href="' . $href . '" />' . "\n";
        }
        $after = $wpStyles->get_data($handle, 'after');
        if (is_array($after) && $after !== []) {
            echo '<style id="' . $handle . '-inline-css">' . implode("\n", $after) . "</style>\n";
        }
        $wpStyles->done[] = $handle;
        unset($wpStyles->to_do[$key]);
    }
}

$wpStyles = new WP_Styles();
$wpStyles->add('theme-style', '/app/themes/site/style.css', [], '1.0');
$wpStyles->add('block-small-style', '/wp-includes/blocks/paragraph/style.min.css', [], '7.1');
if ($tmpSmallCss !== '') {
    $wpStyles->add_data('block-small-style', 'path', $tmpSmallCss);
}
$wpStyles->add('wp-block-template-skip-link', '/wp-includes/css/wp-block-template-skip-link.min.css', [], '7.1');
if ($tmpSmallCss !== '') {
    $wpStyles->add_data('wp-block-template-skip-link', 'path', $tmpSmallCss);
}

$GLOBALS['wp_styles'] = $wpStyles;
$GLOBALS['wp_scripts'] = new WP_Scripts();

// Register WordPress default-filters.php lifecycle hooks.
add_action('wp_head', 'wp_enqueue_scripts', 1);
add_action('wp_head', 'wp_maybe_inline_styles', 1);
add_action('wp_head', 'wp_print_styles', 8);

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('theme-style');
    wp_enqueue_style('block-small-style');
}, 10);
add_action('wp_enqueue_scripts', 'wp_enqueue_block_style_variation_styles', 1);
add_action('wp_enqueue_scripts', 'wp_enqueue_block_custom_css', 1);
add_action('wp_enqueue_scripts', ['WP_Duotone', 'output_block_styles'], 9);
add_action('wp_enqueue_scripts', 'wp_enqueue_global_styles', 10);
add_action('wp_enqueue_scripts', 'wp_enqueue_stored_styles', 10);
add_action('wp_enqueue_scripts', 'wp_enqueue_block_template_skip_link', 10);
add_action('wp_enqueue_scripts', ['WP_Duotone', 'output_global_styles'], 11);

if (empty($_GET['disable_skip_link'])) {
    add_action('wp_footer', 'the_block_template_skip_link', 10);
}

require_once dirname(__DIR__) . '/http-103-early-hints.php';

// 1. template_redirect runs before locate_block_template() / get_the_block_template_html().
do_action('template_redirect');

// 2. locate_block_template() + get_the_block_template_html() render blocks before wp_head().
$GLOBALS['_wp_current_template_content'] = '<!-- wp:group --><main class="wp-block-group">early-hints-ok</main><!-- /wp:group -->';
$GLOBALS['sapi_style_engine_store']['block-supports'][] = '.wp-container-core-group-is-layout-1{display:flex;gap:1rem;}';
WP_Duotone::$block_css_declarations[] = '.wp-duotone-sunrise{filter:url(#wp-duotone-sunrise);}';
WP_Duotone::$used_global_styles_presets[] = ':root{--wp--preset--duotone--sunrise:url(#wp-duotone-sunrise);}';

wp_register_style('block-style-variation-styles', false, ['global-styles']);
wp_add_inline_style('block-style-variation-styles', '.is-style-outline--1{border-width:1px;}');

wp_register_style('wp-block-custom-css', false, ['global-styles']);
wp_add_inline_style('wp-block-custom-css', '.wp-custom-css-1{color:#111;}');

$skipLinkMarkup = (
    has_action('wp_footer', 'the_block_template_skip_link')
    && has_action('wp_enqueue_scripts', 'wp_enqueue_block_template_skip_link')
)
    ? '<a id="wp-skip-link" class="skip-link screen-reader-text" href="#wp--skip-link--target">Skip to content</a>'
    : '';

$templateHtml = $skipLinkMarkup . '<div class="wp-site-blocks"><main id="wp--skip-link--target">early-hints-ok</main></div>';

// 3. wp_head() executes in template-canvas.php after get_the_block_template_html().
ob_start();
do_action('wp_head');
$headHtml = (string) ob_get_clean();

header('X-WP-Enqueue-Scripts-Count: ' . did_action('wp_enqueue_scripts'));
header('X-WP-Head-Trace: ' . implode(',', $GLOBALS['sapi_head_trace']));
header('X-WP-Footer-Skip-Link-Remaining: ' . (has_action('wp_footer', 'the_block_template_skip_link') ? '1' : '0'));

echo "<!DOCTYPE html>\n<html>\n<head>\n" . $headHtml . "</head>\n<body>\n" . $templateHtml . "\n</body>\n</html>";
