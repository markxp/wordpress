# GCP IAP Auth & API Shield (Stateless)

A stateless WordPress plugin that provides identity-aware authentication and a REST API firewall for WordPress sites running behind Google Cloud Identity-Aware Proxy (IAP).

## Features

- **IAP JWT Verification**: Verifies Google IAP ES256 JWT assertions against Google's public keys (`https://www.gstatic.com/iap/verify/public_key`, cached via WordPress transients with automatic key-rotation retry) to map verified Google identities to WordPress users.
- **Opportunistic Auto-Login & Fast-Path Session Reuse**: Runs at `init` priority `0` to establish WordPress sessions whenever `HTTP_X_GOOG_IAP_JWT_ASSERTION` is present (while keeping public frontends open when absent), synchronizes newly minted auth cookies into `$_COOKIE` in memory to eliminate `wp-login.php?reauth=1` bounces, and skips redundant OpenSSL verification on subsequent authenticated requests when the session email and admin cookie match.
- **REST API Firewall**:
  - Protects sensitive REST API endpoints and blocks anonymous write (`POST`/`PUT`/`DELETE`) requests—including unauthenticated `POST /wp/v2/comments`—with `401 rest_forbidden`.
  - Whitelists common read-only (`GET`) endpoints (`/wp/v2/posts`, `/wp/v2/pages`, `/wp/v2/media`, `/wp/v2/categories`, `/wp/v2/tags`, `/wp/v2/taxonomies`, `/wp/v2/types`, `/wp/v2/comments`) for unauthenticated requests, customizable via `iap_auth_shield_api_whitelist`.
- **Editor Origin Normalization**: Strips the internal IAP-protected Editor origin (`home_url()`) from local `/app/` and `/wp/` URLs (both HTML attributes and JSON-escaped Gutenberg block attributes) when saving post content so internal hostnames never leak to public readers.
- **Stateless Configuration**: Controlled entirely via environment variables (`IAP_AUTH_ENABLED` and `IAP_AUDIENCE`) or PHP constants, allowing paired Reader/Editor instances sharing a database to exhibit asymmetric runtime behavior safely.

## Configuration

Set the following environment variables (or `wp-config.php` constants) on the IAP-protected instance:

- `IAP_AUTH_ENABLED`: Set to `true` to enable IAP verification and firewall hooks on the current instance.
- `IAP_AUDIENCE`: Expected Google Cloud IAP JWT `aud` claim (e.g., `/projects/PROJECT_NUMBER/global/backendServices/SERVICE_ID`).

To inspect live environment variable and operational status in the WordPress admin dashboard, navigate to **Plugins** and click **Settings** under **GCP IAP Auth & API Shield (Stateless)** (`wp-admin/admin.php?page=iap-auth-shield`; intentionally hidden from the sidebar menu).

## Extensibility (Filter Hooks)

### `iap_auth_shield_api_whitelist`
Allows themes or plugins to allowlist additional read-only (`GET`) REST API route prefixes for unauthenticated requests:

```php
add_filter('iap_auth_shield_api_whitelist', function (array $whitelist): array {
    $whitelist[] = '/my-custom/v1';
    return $whitelist;
});
```

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher
- [`firebase/php-jwt`](https://github.com/firebase/php-jwt) (`^7.0`)

## Installation

1. Upload the `iap-auth-shield` folder to your WordPress `/wp-content/plugins/` directory.
2. Ensure `firebase/php-jwt` is installed via Composer in your project or inside the plugin's `vendor/` folder.
3. Configure `IAP_AUTH_ENABLED` and `IAP_AUDIENCE` in your environment.
4. Activate the plugin through the **Plugins** menu in WordPress.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

---

## Standalone Development & Distribution

> **Note:** Composer ignores `require-dev` in `path`/`vcs` dependencies when installed from a parent Bedrock project. To run PHPUnit or Laravel Pint **standalone** inside this plugin directory, first run `composer install` here:

```bash
# Inside the Bedrock monorepo (uses root vendor/ automatically, or local vendor/ if installed):
composer test:container
composer lint:container

# If extracted outside the monorepo (standalone repository):
composer install
composer test
composer lint
```

- **Standalone distribution archive (`iap-auth-shield.zip`)**: Run `composer build:dist` inside this directory to install production dependencies in a temporary directory, namespace-prefix them with **Strauss** (`vendor-prefixed/`), and output a conflict-free `iap-auth-shield.zip` ready for non-Composer WordPress installations.
