# HTTP Link Header Generator

A lightweight, performance-focused, zero-configuration WordPress plugin that inspects header-enqueued styles and scripts, automatically determining the critical rendering path, and generating HTTP `Link` response headers for CDN HTTP 103 Early Hints.

## Features

- **No Output Buffering (`ob_start()`)**: Fully stateless execution that intercepts scripts and styles early during `template_redirect` (before templates render and write HTML output). Avoids any CPU/memory rendering overhead.
- **Zero-Configuration Automatic Heuristics**: Automatically detects critical, render-blocking CSS and JS printed in the page `<head>`.
  - **Explicit Preloads**: Respects and parses assets explicitly enqueued via WordPress's native `wp_preload_resources` API (like local fonts or LCP hero images).
  - **CSS Heuristics**: Preloads stylesheets enqueued for `all` or `screen` media types, while ignoring `print` stylesheets. Prioritizes the main stylesheet of the active theme.
  - **JS Heuristics**: Preloads only synchronous, render-blocking header scripts, automatically skipping scripts enqueued in the footer or loaded with `defer`/`async` strategies.
- **Block Theme (FSE) Optimization**:
  - Automatically activates conditional block style loading (`should_load_separate_core_block_assets`), loading only CSS for blocks present on the current page.
  - Disables stylesheet inlining in the HTML head (`styles_inline_size_limit = 0`) to ensure block CSS files are output as external cacheable assets that can be preloaded via HTTP headers.
- **Strict Hard Cap**: Automatically limits the maximum number of preloaded assets in the HTTP `Link` header to **5** to prevent network bandwidth contention.

## Configuration

The block theme optimization settings can be easily configured via the WordPress Admin dashboard:

1. Navigate to **Settings > HTTP Link Headers** in the WordPress admin menu.
2. Adjust the following settings:
   - **On-Demand Block CSS**: Toggles whether WordPress should load styles only for blocks rendered on the current page (defaults to checked/enabled).
   - **Inline CSS Size Limit (Bytes)**: Defines the size threshold below which stylesheets are inlined. Setting this to `0` (default) completely disables inlining, forcing all styles to load as cacheable, external files suitable for early preloading.
3. Click **Save Changes**.

*Note: Developers can still programmatically override these values in code using the `hlhg_should_load_separate_core_block_assets` and `hlhg_styles_inline_size_limit` WordPress filters.*

## Installation

1. Copy the plugin folder to your WordPress installation's `/wp-content/plugins/` directory.
2. Activate the plugin in the WordPress admin panel.
3. Configure settings via the **Settings > HTTP Link Headers** menu.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
