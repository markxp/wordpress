# WordPress Security Hardening

A secure-by-default, PSR-4 compliant security plugin for WordPress that systematically mitigates information disclosure, user enumeration, CDN bypasses, and unauthorized API mutations.

---

## Features

### 1. Environment-Based Activation Toggle
Supports twin-service/multi-container architectures sharing the same database. Control whether the firewall is active on a specific server container using:
*   **`SECURITY_HARDENING_ENABLED`**: Set to `true` to activate. Defaults to `false` (fail-safe / disabled).

### 2. REST API Gatekeeper
*   **Anonymous Reads Restricted**: Limits anonymous reads (`GET`, `HEAD`, `OPTIONS` only) to a strict whitelist:
    *   `/wp/v2/posts`
    *   `/wp/v2/pages`
    *   `/wp/v2/categories`
    *   `/wp/v2/tags`
    *   `/wp/v2/types`
    *   `/wp/v2/taxonomies`
    *   `/wp/v2/media`
    *   `/wp/v2/comments`
*   **Anonymous Writes Blocked**: Explicitly blocks all anonymous database write operations (`POST`, `PUT`, `PATCH`, `DELETE`) globally across all endpoints (including comment submission and media uploads).
*   **User Endpoint Protection**: Anonymous requests to `/wp/v2/users` or `/wp/v2/users/N` are strictly blocked.

### 3. Query Parameter Bypass Blocker
Intercepts initialization (`init` hook at priority 1) and blocks direct query parameter access to standard bypass parameters for anonymous requests:
*   `?rest_route=` → Returns `400 Bad Request`
*   `?feed=` → Returns `404 Not Found`
*   `?author=` → Returns `404 Not Found`
*   `?tb=` → Returns `403 Forbidden`
*   `?embed=` → Returns `404 Not Found`

*Note: Forwards Nginx path blocks (e.g. `location /feed/`) safely without any risk of query string bypasses.*

### 4. Global Feed & Author Suppression
*   **Feeds Disabled**: Disables feeds globally for all posts, pages, taxonomies, comments, and the root site. Returns a 404 response.
*   **Author Enumeration Blocked**: Strips the `author` query variable from query compilation, and returns a 404 for all `/author/` archive pages.

### 5. WP Version Hiding
*   Removes the `<meta name="generator">` tag from HTML heads.
*   Suppresses version query strings (`?ver=X.Y.Z` matching the core version) from script and style asset URLs.
*   Removes generator strings from RSS templates.

---

## Installation & Setup

1. Place the `security-hardening` folder under `web/app/plugins/`.
2. Activate the plugin in the WordPress Dashboard.
3. Enable it on the desired server instances by setting the environment variable:
   ```bash
   SECURITY_HARDENING_ENABLED=true
   ```
