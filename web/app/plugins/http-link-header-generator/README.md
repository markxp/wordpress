# HTTP Link Header Generator

A lightweight, performance-focused, zero-configuration WordPress plugin that inspects header-enqueued styles and scripts, automatically determining the critical rendering path, and generating HTTP `Link` response headers for CDN HTTP 103 Early Hints.

## Features

- **No Output Buffering (`ob_start()`)**: Fully stateless execution that intercepts scripts and styles early during `template_redirect` (before templates render and write HTML output). Avoids any CPU/memory rendering overhead.
- **Zero-Configuration Automatic Heuristics**: Automatically detects critical, render-blocking CSS and JS printed in the page `<head>`.
  - **Explicit Preloads**: Respects and parses assets explicitly enqueued via WordPress's native `wp_preload_resources` API (like local fonts or LCP hero images).
  - **CSS Heuristics**: Preloads stylesheets enqueued for `all` or `screen` media types, while ignoring `print` stylesheets. Prioritizes the main stylesheet of the active theme.
  - **JS Heuristics**: Preloads only synchronous, render-blocking header scripts, automatically skipping scripts enqueued in the footer or loaded with `defer`/`async` strategies.
- **Strict Hard Cap**: Automatically limits the maximum number of preloaded assets in the HTTP `Link` header to **5** to prevent network bandwidth contention.

## Installation

1. Copy the plugin folder to your WordPress installation's `/wp-content/plugins/` directory.
2. Activate the plugin in the WordPress admin panel.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
