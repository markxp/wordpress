# Auto Meta Description

A lightweight, zero-configuration WordPress plugin that emits a clean `<meta name="description">` tag in `wp_head` to satisfy search engine and Lighthouse SEO requirements without bloated third-party SEO suites.

## Features

- **Automatic Context Resolution**:
  - **Front Page & Blog Index**: Uses the WordPress site tagline (`get_bloginfo('description')`).
  - **Singular Posts & Pages**: Prioritizes the manual post excerpt (`post_excerpt`), falling back to sanitized block content (`excerpt_remove_blocks()` and `strip_shortcodes()`) bounded to the leading ~4 KB block boundary for O(1) performance on long-form posts.
  - **Category, Tag & Taxonomy Archives**: Uses the term description (`term_description()`), falling back to the site tagline.
  - **404 Error Pages**: Automatically suppresses output on 404 responses.
- **Multibyte-Safe Truncation**: Strips HTML tags, normalizes whitespace, and truncates descriptions cleanly at 160 UTF-8 characters (`…`).
- **Optional Object Cache Toggle**: Caches resolved singular post descriptions via `wp_cache_get()` / `wp_cache_set()` keyed by post ID and modification timestamp when enabled on servers with a persistent object cache (APCu, Redis, Memcached).
- **Extensible Filter Hooks**: Provides `auto_meta_description_content` and `auto_meta_description_use_object_cache` for programmatic overrides.

## Configuration

Auto Meta Description works out of the box with zero configuration. To configure optional object caching:

1. Navigate to **Plugins** in the WordPress admin dashboard and click **Settings** under **Auto Meta Description** (`wp-admin/admin.php?page=auto-meta-description`; intentionally hidden from the sidebar menu).
2. Toggle **Enable Object Cache** (`auto_meta_description_use_object_cache`) and click **Save Changes**.

## Extensibility (Filter Hooks)

### `auto_meta_description_content`
Allows themes or plugins to customize or override the resolved meta description string before it is rendered (returning `''` suppresses the tag):

```php
add_filter('auto_meta_description_content', function (string $description): string {
    return $description;
});
```

### `auto_meta_description_use_object_cache`
Programmatically enables or disables WordPress Object Cache storage for singular post descriptions, overriding the saved admin option:

```php
add_filter('auto_meta_description_use_object_cache', function (bool $enabled): bool {
    return wp_using_ext_object_cache();
});
```

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher

## Installation

1. Upload the `auto-meta-description` folder to your WordPress `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
