=== SEO Meta Description ===
Contributors: Antigravity
Tags: seo, meta, description, head, lighthouse
Requires at least: 6.8
Requires PHP: 8.3
Tested up to: 6.9
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Lightweight plugin that emits a clean <meta name="description"> tag in wp_head using the post excerpt, content summary, taxonomy description, or site tagline.

== Description ==

SEO Meta Description is a lightweight, zero-configuration plugin that outputs a standards-compliant `<meta name="description">` tag in `wp_head` (priority 1):

* Uses the site tagline on the front page and blog index.
* Uses the manual post excerpt or sanitized Gutenberg block content on singular posts and pages.
* Uses the term description on category, tag, and custom taxonomy archives.
* Suppresses meta description output on 404 error pages.
* Truncates cleanly at 160 UTF-8 characters and exposes the `seo_meta_description_content` filter.

== Installation ==

1. Upload the `seo-meta-description` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Changelog ==

= 1.0.0 =
* Initial release.
