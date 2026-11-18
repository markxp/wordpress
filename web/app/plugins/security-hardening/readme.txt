=== WordPress Security Hardening ===
Contributors: markxp
Tags: security, hardening, rest-api, xml-rpc, privacy
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Secures the WordPress site systematically against data leaks, scraper bots, and username enumeration.

== Description ==

WordPress Security Hardening is a zero-configuration, PSR-4 compliant security plugin that mitigates information disclosure, user enumeration, query-parameter bypasses, and unauthorized REST API mutations:

* **REST API Gatekeeper**: Restricts anonymous visitors to read-only (`GET`, `HEAD`, `OPTIONS`) access on public content prefixes (`/wp/v2/posts`, `/wp/v2/pages`, `/wp/v2/categories`, `/wp/v2/tags`, `/wp/v2/types`, `/wp/v2/taxonomies`, `/wp/v2/media`) and blocks `/wp/v2/users`, `/wp/v2/comments`, and all anonymous write operations.
* **Extensible Route Whitelist**: Exposes the `security_hardening_allowed_rest_prefixes` filter so themes or plugins can allowlist custom public read-only routes.
* **Query Parameter & Trackback Blocker**: Blocks anonymous `?rest_route=`, `?feed=`, `?author=`, and `?tb=` / `/trackback` bypass vectors, and disables trackbacks globally.
* **Feed & Author Archive Suppression**: Disables RSS/Atom/RDF feeds and author archives (`404 Not Found`) for unauthenticated requests.
* **WordPress Version Hiding**: Removes generator tags and replaces core `?ver=X.Y.Z` asset query parameters with a deterministic 8-character HMAC-SHA256 hash to preserve browser cache busting.

== Installation ==

1. Upload the `security-hardening` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. (Optional) View active protection status by clicking **Settings** under **WordPress Security Hardening** on the **Plugins** screen.

== Changelog ==

= 1.0.0 =
* Initial release.
