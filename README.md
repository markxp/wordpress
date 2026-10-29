# WordPress for nerdy

A low-level, cost-optimized, and environment-agnostic WordPress codebase built on [Roots Bedrock](https://github.com/roots/bedrock).

---

## Core Goal & Motivation

Hosting a decoupled, production-grade WordPress site on modern cloud infrastructure (such as Google Cloud) frequently incurs a continuous **"GCLB tax"**—paying minimum hourly forwarding-rule fees for Google Cloud Load Balancing (Application Load Balancers) solely to handle URL path routing, SSL termination, or backend separation.

This repository is purpose-built to eliminate that overhead by standardizing on the **Twin-Service pattern** on serverless compute (e.g. Google Cloud Run):

* **Twin-Service Decoupling Without L7 Load Balancers**: Rather than paying for an ALB to route `/wp-admin` vs public traffic, the site runs as paired Cloud Run services:
  * **Reader Service**: Public-facing, stateless, heavily cached, stripped of attack vectors, and optimized for anonymous visitors.
  * **Editor Service**: Isolated behind Google Cloud Identity-Aware Proxy (IAP) using `iap-auth-shield`, dedicated to administrative workflows, Site Kit OAuth handshakes, and content authoring for editors.
* **Strict Statelessness**: Media is stored in Google Cloud Storage (`wp-stateless`), encryption keys and configuration are loaded purely from environment variables, and distributed tracing is auto-instrumented via OpenTelemetry.

While infrastructure targets may evolve, maintaining a resilient, highly observable WordPress architecture without cloud load balancer cost locks is the explicit goal of this repository.

---

## Plugin Suite

### Third-Party Plugins
Managed via Composer or official Google vendor archives:
* **`google-site-kit`**: Official Google plugin for Search Console, Analytics 4, PageSpeed Insights, and Consent Mode v2 (installed via Composer: `wp-plugin/google-site-kit`).
* **`google-site-kit-dev-settings`**: Official Google [Site Kit helper plugin](https://sitekit.withgoogle.com/documentation/how-tos/staging/) (downloaded from [`https://sitekit.withgoogle.com/service/download/google-site-kit-dev-settings.zip`](https://sitekit.withgoogle.com/service/download/google-site-kit-dev-settings.zip)) enabling Custom Site URL and Search Console property overrides for decoupled staging and Twin-Service architectures.
* **`wp-stateless`**: Media offloader by Usability Dynamics storing uploads in Google Cloud Storage buckets (installed via Composer: `wp-plugin/wp-stateless`).

### Custom (Homemade) Plugins
Purpose-built plugins developed in this codebase located in `web/app/plugins/`. Refer to each plugin's individual `README.md` for in-depth architecture, configuration, and hook references:
* **`iap-auth-shield`** ([`web/app/plugins/iap-auth-shield/README.md`](web/app/plugins/iap-auth-shield/README.md)): Authenticates users against Google Cloud Identity-Aware Proxy (IAP) JWT assertions and enforces a REST API firewall on protected administrative instances.
* **`security-hardening`** ([`web/app/plugins/security-hardening/README.md`](web/app/plugins/security-hardening/README.md)): Restricts anonymous REST API access, strips user enumeration vectors, suppresses RSS/Atom feeds for unauthenticated visitors, and cleans WordPress generator version tags.
  * *Extensibility*: Both security plugins provide independent, plugin-namespaced filter hooks (`iap_auth_shield_api_whitelist` and `security_hardening_allowed_rest_prefixes`) to allowlist custom public REST routes without cross-plugin coupling.
* **`http-103-early-hints`** ([`web/app/plugins/http-103-early-hints/README.md`](web/app/plugins/http-103-early-hints/README.md)): Stateless generator for CDN HTTP 103 Early Hints and `Link` preload headers.
* **`remove-wp-default-link-header`** ([`web/app/plugins/remove-wp-default-link-header/README.md`](web/app/plugins/remove-wp-default-link-header/README.md)): Cleans redundant WordPress core REST API and RSD `<link>` headers from HTTP responses.

---

## Configuration & Environment Variables

Configurations are defined in `config/application.php` and loaded using [`phpdotenv`](https://github.com/phpdotenv/phpdotenv) (`vlucas/phpdotenv`) and `oscarotero/env`.

> [!IMPORTANT]
> **OS Environment Variables vs. `.env` Files in Bedrock**:
> In `config/application.php`, `Env\Env::$options = 31` enables `USE_ENV_ARRAY`, instructing `env()` to read exclusively from PHP's `$_ENV` superglobal (ignoring `getenv()` and `$_SERVER`). Because production PHP (`php.ini`) defaults to `variables_order = "GPCS"` (omitting `E`), ordinary OS process environment variables (e.g. `export VAR=val`) do **not** populate `$_ENV` and will **not** work for Bedrock settings unless `variables_order` includes `E`. Always supply Bedrock configuration via `.env` / `.env.local` files (which `Dotenv::createImmutable` loads directly into `$_ENV`) or explicitly enable `E` in `variables_order`.

### Site URL Configuration (`WP_HOME`, `WP_SITEURL`)

WordPress requires canonical home and site URLs. In this repository, URL resolution uses the following precedence:

1. **Primary: Dynamic Request Host (HTTP Web Requests)**:
   For incoming HTTP requests, `config/application.php` dynamically resolves `WP_HOME` and `WP_SITEURL` directly from web server headers (`HTTP_HOST` / `HTTP_X_FORWARDED_HOST` and `HTTP_X_FORWARDED_PROTO`):
   ```php
   $protocol = (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
   $dynamic_home = "{$protocol}://{$http_host}";
   Config::define('WP_HOME', $dynamic_home);
   Config::define('WP_SITEURL', "{$dynamic_home}/wp");
   ```
   * **Optional in `.env` for Web Traffic**: Omitting `WP_HOME` and `WP_SITEURL` in `.env` will **not** crash web requests because the incoming request host is evaluated first.
   * **Security Best Practice**: Ensure the upstream reverse proxy (e.g. Nginx, Cloud Run) strictly controls the `Host` header (`proxy_set_header Host $host;` and trusted `set_real_ip_from`) to prevent HTTP Host Header Poisoning attacks.

2. **Fallback: Configuration File / Environment Variables (CLI Calls)**:
   Background jobs, WP-CLI executions, and cron tasks run without an HTTP request context (`$_SERVER['HTTP_HOST']` is unset). For these calls, `config/application.php` falls back to the values defined in `.env` or system environment variables:
   ```bash
   WP_HOME=https://example.com
   WP_SITEURL=${WP_HOME}/wp # optional, defaults to ${WP_HOME}/wp
   ```

### Serverless Optimization & Background Jobs
* **`DISABLE_WP_CRON=true`**: **Serverless Best Practice**. Native WP-Cron triggers opportunistically on visitor HTTP requests. On serverless platforms (like Cloud Run), pods scale to zero (causing scheduled tasks to be missed) or scale out concurrently (causing cron tasks to run multiple duplicate times across pods). Disabling native cron and triggering `wp-cron.php` externally via **Google Cloud Scheduler** or a deterministic Linux cron runner ensures reliable, single-execution scheduling.
* **`WP_POST_REVISIONS`**: Integer limit on post revisions (e.g. `5` or `10`) to prevent database table bloat on managed relational databases.

### Environment & Database Settings
* `WP_ENV`: Environment identifier (`development`, `staging`, `production`, `local`).
* `DATABASE_URL`: Full database DSN (e.g. `mysql://user:pass@127.0.0.1:3306/db_name`), OR individual variables:
  * `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`.

### WordPress Salts & Encryption Keys
* `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY`
* `AUTH_SALT`, `SECURE_AUTH_SALT`, `LOGGED_IN_SALT`, `NONCE_SALT`
* **Google Site Kit Integration**:
  * `GOOGLESITEKIT_ENCRYPTION_KEY` & `GOOGLESITEKIT_ENCRYPTION_SALT` (*Optional*): Site Kit encrypts stored OAuth refresh tokens in the database using AES-256-CTR. By default, Site Kit automatically falls back to standard WordPress `LOGGED_IN_KEY` and `LOGGED_IN_SALT`. Setting dedicated `GOOGLESITEKIT_ENCRYPTION_*` keys in `.env` is optional, but if specified, **must remain identical across all services sharing the database** (e.g. Reader and Editor services) so tokens can be decrypted consistently across pods.
  * *Sign in with Google (Login Helper)*: If enabling Site Kit's "Sign in with Google" module for frontend users or subscribers on the Reader service, configure a separate OAuth 2.0 Web Client ID in Google Cloud Console with the Reader origin, distinct from administrative reporting credentials.

### Observability (OpenTelemetry)
* `OTEL_SERVICE_NAME`: Service identifier (e.g. `wordpress-reader` or `wordpress-editor`).
* `OTEL_EXPORTER_OTLP_PROTOCOL`: `grpc` (default).
* `OTEL_EXPORTER_OTLP_ENDPOINT`: Collector endpoint (default: `http://localhost:4317`).

---

## Developer Workflows

```bash
# Run unit test suite (via Podman/Docker container)
composer test:container

# Run code style linter (Laravel Pint - PSR-12)
composer lint:container

# Fix code style
composer lint:fix:container
```
