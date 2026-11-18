=== Auto Meta Description ===
Contributors: markxp
Tags: seo, meta, description, head, lighthouse
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Emits a clean meta description tag in wp_head using the post excerpt, block content summary, taxonomy description, or site tagline.

== Description ==

Auto Meta Description is a lightweight, zero-configuration plugin that outputs a standards-compliant `<meta name="description">` tag in `wp_head` (priority 1):

* Uses the site tagline on the front page and blog index.
* Prioritizes the manual post excerpt on singular posts and pages, falling back to sanitized Gutenberg block content bounded to the leading ~4 KB block boundary.
* Uses the term description on category, tag, and custom taxonomy archives, falling back to the site tagline.
* Suppresses meta description output on 404 error pages.
* Strips HTML tags, normalizes whitespace, and truncates cleanly at 160 UTF-8 characters.
* Includes an optional WordPress Object Cache toggle (`wp_cache_get` / `wp_cache_set`) for persistent object caches (APCu, Redis, Memcached).
* Exposes the `auto_meta_description_content` and `auto_meta_description_use_object_cache` filters for programmatic customization.

== Installation ==

1. Upload the `auto-meta-description` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. (Optional) To enable WordPress Object Cache storage for singular post descriptions, click **Settings** under **Auto Meta Description** on the **Plugins** screen.

== Changelog ==

= 1.0.0 =
* Initial release.
