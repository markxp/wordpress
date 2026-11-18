# WordPress Security Hardening

A secure-by-default, PSR-4 compliant security plugin for WordPress that systematically mitigates information disclosure, user enumeration, CDN bypasses, and unauthorized API mutations.

---

## Features

### 1. REST API Gatekeeper
* **Anonymous Reads Restricted**: Limits anonymous reads (`GET`, `HEAD`, `OPTIONS` only) to a strict whitelist:
  * `/wp/v2/posts`
  * `/wp/v2/pages`
  * `/wp/v2/categories`
  * `/wp/v2/tags`
  * `/wp/v2/types`
  * `/wp/v2/taxonomies`
  * `/wp/v2/media`
* **Anonymous Writes Blocked**: Explicitly blocks all anonymous database write operations (`POST`, `PUT`, `PATCH`, `DELETE`) globally across all endpoints (including comment submission and media uploads).
* **User & Comment Endpoint Protection**: Anonymous requests to `/wp/v2/users`, `/wp/v2/users/N`, and `/wp/v2/comments` are strictly blocked.
* **Extensible Whitelist (`security_hardening_allowed_rest_prefixes`)**: Allowlist additional public read-only (`GET`, `HEAD`) route prefixes via the `security_hardening_allowed_rest_prefixes` filter:
  ```php
  add_filter('security_hardening_allowed_rest_prefixes', function (array $prefixes): array {
      $prefixes[] = '/my-custom/v1';
      return $prefixes;
  });
  ```

### 2. Query Parameter Bypass & Trackback Blocker
Intercepts initialization (`init` hook at priority 1) and request variable parsing (`request` filter) to block direct query parameter and pretty-permalink bypasses for anonymous requests:
* `?rest_route=` → Returns `400 Bad Request`
* `?feed=` → Returns `404 Not Found`
* `?author=` → Returns `404 Not Found`
* `?tb=` or `/<permalink>/trackback` (`query_vars['tb']`) → Returns `403 Forbidden`
* Forces `pings_open` to `false` (priority `9999`) and blocks `pre_trackback_post` with `403 Forbidden` so legacy posts with `ping_status = 'open'` in `wp_posts` cannot accept trackback spam.
* Leaves `<permalink>/embed` (`?embed=`) available for oEmbed post previews.

*Note: Complements edge/reverse-proxy path blocks (e.g. `location /feed/`) safely without risk of query string bypasses.*

### 3. Global Feed & Author Suppression
* **Feeds Disabled**: Disables feeds globally for all posts, pages, taxonomies, comments, and the root site, returning a `404` response.
* **Author Enumeration Blocked**: Strips both `author` and `author_name` query variables from query compilation and returns a `404` for `/author/` archive pages.

### 4. WordPress Version Hiding
* Removes the `<meta name="generator">` tag from HTML heads and generator strings from RSS templates.
* Replaces WordPress core version query strings (`?ver=X.Y.Z` matching `get_bloginfo('version')`) with a deterministic 8-character HMAC-SHA256 hash using `wp_salt('nonce')` so the core version is concealed while preserving immutable browser cache-busting across WordPress upgrades.

## Status Dashboard

To inspect active protection modules in the WordPress admin dashboard, navigate to **Plugins** and click **Settings** under **WordPress Security Hardening** (`wp-admin/admin.php?page=security-hardening`; intentionally hidden from the sidebar menu).

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher

## Installation

1. Upload the `security-hardening` folder to your WordPress `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
