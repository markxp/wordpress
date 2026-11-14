# Legispect SEO Meta Description

A lightweight, zero-configuration WordPress plugin that emits a clean `<meta name="description">` tag in `wp_head` to satisfy search engine and Lighthouse SEO requirements without bloated third-party SEO suites.

## Features

- **Automatic Context Resolution**:
  - **Front Page & Blog Index**: Uses the WordPress site tagline (`get_bloginfo('description')`).
  - **Singular Posts & Pages**: Prioritizes the manual post excerpt (`post_excerpt`), falling back to sanitized block content (`excerpt_remove_blocks()` and `strip_shortcodes()`) bounded to the leading ~4 KB block boundary for O(1) performance on long-form posts.
  - **Category, Tag & Taxonomy Archives**: Uses the term description (`term_description()`), falling back to the site tagline.
  - **404 Error Pages**: Automatically suppresses output on 404 responses.
- **Multibyte-Safe Truncation**: Strips HTML tags, normalizes whitespace, and truncates descriptions cleanly at 160 UTF-8 characters (`…`).
- **Optional Object Cache Toggle**: Provides a Settings toggle (`legispect_seo_meta_description_use_object_cache` option and filter hook) to cache resolved singular post descriptions via `wp_cache_get()` / `wp_cache_set()` when the server enables a persistent object cache (APCu, Redis, Memcached).
- **Extensible Filter**: Provides the `legispect_seo_meta_description_content` filter hook for custom programmatic overrides.

## Extensibility (Filter Hooks)

### `legispect_seo_meta_description_content`
Allows themes or plugins to customize or override the resolved meta description string before it is rendered:

```php
add_filter('legispect_seo_meta_description_content', function (string $description): string {
    return $description;
});
```

## Installation

1. Copy the `legispect-seo-meta-description` folder to `web/app/plugins/`.
2. Activate the plugin in the WordPress admin panel.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
