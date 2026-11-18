# Modern Site Icons

A lightweight, zero-configuration WordPress plugin that filters `site_icon_meta_tags` to emit modern multi-format favicon and W3C Web App Manifest `<link>` tags whenever static icon assets (`/icon.svg`, `/favicon-96x96.png`, `/favicon.ico`, `/apple-touch-icon.png`, `/site.webmanifest`) are staged at the web root.

## Features

- **Modern Multi-Format Icons**: Emits PNG (`96x96`), SVG (`/icon.svg`), ICO (`/favicon.ico`), Apple Touch Icon (`180x180`), and Web App Manifest (`/site.webmanifest`) links across `wp_head`, `admin_head`, and `login_head` via `wp_site_icon()`.
- **Safe Fallback & Zero-Attachment Support**: Checks once per request for `/icon.svg` under the web root (`dirname(WP_CONTENT_DIR)`). When present, it also provides a `get_site_icon_url` fallback when no database `site_icon` attachment is set so `has_site_icon()` returns `true` and `wp_site_icon()` runs cleanly. When `/icon.svg` is absent, WordPress core's default `site_icon` behavior is left untouched.
- **Extensible Filter Hook**: Exposes the `modern_site_icons_meta_tags` filter for downstream customization.

## Extensibility (Filter Hooks)

### `modern_site_icons_meta_tags`
Allows themes or plugins to customize the array of modern favicon and manifest `<link>` tags before output:

```php
add_filter('modern_site_icons_meta_tags', function (array $tags, array $originalTags): array {
    return $tags;
}, 10, 2);
```

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher

## Installation

1. Upload the `modern-site-icons` folder to your WordPress `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
