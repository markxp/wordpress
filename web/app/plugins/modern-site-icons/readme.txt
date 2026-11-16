=== Modern Site Icons ===
Contributors: Antigravity
Tags: favicon, site-icon, manifest, pwa, head
Requires at least: 6.8
Requires PHP: 8.3
Tested up to: 6.9
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Emits modern multi-format favicon and Web App Manifest tags via site_icon_meta_tags when static root icon assets are present.

== Description ==

Modern Site Icons filters WordPress core's `site_icon_meta_tags` output to emit modern multi-format favicons (`favicon-96x96.png`, `icon.svg`, `favicon.ico`, `apple-touch-icon.png`) and a Web App Manifest (`site.webmanifest`) when static icon assets exist at the web root. It also supplies a `get_site_icon_url` fallback when no database `site_icon` attachment is set so `wp_site_icon()` still emits the static tags on fresh installs.

If `/icon.svg` is not present at the web root, the plugin cleanly falls back to WordPress core's default `site_icon` meta tags.

== Installation ==

1. Upload the `modern-site-icons` folder to `/wp-content/plugins/` (or `web/app/plugins/` in Bedrock).
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Changelog ==

= 1.0.0 =
* Initial release.
