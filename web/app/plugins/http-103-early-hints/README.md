# HTTP 103 Early Hints & Link Header Generator

A lightweight, performance-focused, zero-configuration WordPress plugin that inspects header-enqueued styles and scripts, automatically determines the critical rendering path, and generates HTTP `Link` response headers for CDN HTTP 103 Early Hints.

## Features

- **No Output Buffering (`ob_start()`)**: Fully stateless execution that intercepts scripts and styles early during `template_redirect` (before templates render and write HTML output), avoiding CPU and memory rendering overhead.
- **Zero-Configuration Automatic Heuristics**: Automatically detects critical, render-blocking CSS and JS printed in the page `<head>`.
  - **Explicit Preloads**: Respects and parses assets explicitly registered via WordPress's native `wp_preload_resources` filter (such as local fonts or LCP hero images).
  - **CSS Heuristics**: Preloads stylesheets enqueued for `all` or `screen` media types while ignoring `print` and inlined stylesheets, prioritizing the active theme's primary stylesheet.
  - **JS Heuristics**: Preloads only synchronous, render-blocking header scripts, skipping footer scripts and scripts registered with `defer` or `async` strategies.
- **Block Theme (FSE) Optimization**:
  - Activates conditional block style loading (`should_load_separate_core_block_assets`), loading CSS only for blocks rendered on the current page.
  - Disables stylesheet inlining in the HTML head (`styles_inline_size_limit = 0`) by default so block CSS files load as external, cacheable assets eligible for HTTP 103 Early Hints preloading.
- **Strict Hard Cap**: Limits the maximum number of preloaded assets in the HTTP `Link` header to **5** to prevent network bandwidth contention.

## Configuration

To customize Block Theme and CSS inlining optimizations via the WordPress admin dashboard:

1. Navigate to **Plugins** in the WordPress admin dashboard and click **Settings** under **HTTP 103 Early Hints & Link Header Generator** (`wp-admin/admin.php?page=hlhg-settings`; intentionally hidden from the sidebar menu).
2. Adjust the following settings:
   - **Inline CSS Size Limit (Bytes)** (`hlhg_inline_size_limit`): Maximum cumulative size (in bytes) of small block stylesheets allowed to be inlined into `<head>`. Defaults to `0` (disabled) so stylesheets remain external and preloadable.
   - **On-Demand Block CSS** (`hlhg_load_separate_assets`): Toggles loading styles only for blocks present on the current page (enabled by default).
3. Click **Save Changes**.

## Extensibility (Filter Hooks)

Developers can programmatically override the configured settings using the following filters:

```php
add_filter('hlhg_should_load_separate_core_block_assets', function (bool $loadSeparate): bool {
    return $loadSeparate;
});

add_filter('hlhg_styles_inline_size_limit', function (int $byteLimit): int {
    return $byteLimit;
});
```

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher

## Installation

1. Upload the `http-103-early-hints` folder to your WordPress `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
