# Environment Variable Loading, Precedence & Hardening

`config/application.php` loads, validates, and scrubs configuration across local `.env` files, WP-CLI sessions, and containerized PHP-FPM deployments (such as Google Cloud Run).

---

## 1. Precedence Order

When resolving Bedrock and plugin settings, values follow this strict precedence order (highest to lowest):

1. **Container / PHP-FPM Environment Variables** (`$_ENV` / `$_SERVER` injected by the container runtime, SAPI `fastcgi_param`, or process environment)
2. **`.env.local`** (local overrides when `.env` is also present)
3. **`.env`** (mounted secret file or local project configuration)
4. **Application Defaults** (fallback values defined in `config/application.php` and `config/environments/*.php`)

---

## 2. Why `$_SERVER` -> `$_ENV` Hydration Is Required Under `GPCS`

Three components interact during Bedrock bootstrap:

1. **`php.ini-production` (`variables_order = "GPCS"`)**: Production PHP omits `E` from `variables_order` to avoid allocating `$_ENV` entries for every OS environment variable on every request. As a result, container and FastCGI environment variables populate `$_SERVER`, while `$_ENV` starts empty (`[]`).
2. **`oscarotero/env` (`Env\Env::$options = 31`)**: Bitmask `31` (`USE_ENV_ARRAY | CONVERT_BOOL | CONVERT_NULL | CONVERT_INT | STRIP_QUOTES`) instructs `Env\env()` to read exclusively from `$_ENV` (never calling `getenv()` or reading `$_SERVER` directly) and converts `'true'`/`'false'`, `'null'`, and integer strings into native PHP scalars.
3. **`vlucas/phpdotenv` (`Dotenv::createImmutable`)**: `ServerConstAdapter` inspects **both** `$_ENV` and `$_SERVER` for pre-existing variables. When a container sets an override (e.g. `WP_STATELESS_MEDIA_MODE=disabled` or `IAP_AUTH_ENABLED=true`) alongside a mounted `.env` file:
   - `Dotenv::createImmutable` sees the key in `$_SERVER`, treats it as immutable, and **skips writing that key to `$_ENV`**.
   - Without hydration, `$_ENV[$key]` remains unset and `env($key)` returns `null`—neither the container override nor the `.env` value would take effect.

### How `config/application.php` Resolves This

After `$dotenv->load()` runs (if `.env` exists) and before required database keys are checked (`$dotenv->required(...)`), `config/application.php` flattens `$config_key_groups` into `$managed_config_keys` and backfills missing keys from `$_SERVER` into `$_ENV`:

```php
foreach ($managed_config_keys as $key) {
    if (!array_key_exists($key, $_ENV) && array_key_exists($key, $_SERVER) && is_scalar($_SERVER[$key])) {
        $_ENV[$key] = (string) $_SERVER[$key];
    }
}
```

- If a key was **only in `.env`**, `Dotenv::createImmutable` already wrote it to `$_ENV`, so `!array_key_exists($key, $_ENV)` is `false` and the `.env` value is used.
- If a key was **set in the container (`$_SERVER`)**, `Dotenv::createImmutable` preserved `$_SERVER[$key]` and left `$_ENV[$key]` unset, so the hydration loop copies `(string) $_SERVER[$key]` into `$_ENV[$key]`—giving container `env:` variables clean precedence over `.env` under both `GPCS` and `EGPCS`.
- Untrusted client HTTP headers in CGI/FastCGI are always prefixed with `HTTP_` (e.g. `HTTP_WP_STATELESS_MEDIA_MODE`), so they never match `$config_key_groups` or `/^WP_STATELESS_[A-Z0-9_]+$/`.

---

## 3. PHP-FPM `clear_env` Forwarding

By default, PHP-FPM pool configurations set `clear_env = yes`, stripping OS container environment variables before worker processes spawn. When injecting settings via container `env:` (such as Cloud Run environment variables or Secret Manager env mounts) rather than a mounted `.env` file, ensure PHP-FPM forwards them using one of two approaches in your pool configuration (e.g. `/etc/php/8.5/fpm/pool.d/*.conf`):

- **Explicit Allowlist (Recommended for Least Privilege)**:
  ```ini
  ; Forward specific container overrides into PHP-FPM workers
  env[IAP_AUTH_ENABLED] = $IAP_AUTH_ENABLED
  env[IAP_AUDIENCE] = $IAP_AUDIENCE
  env[WP_STATELESS_MEDIA_MODE] = $WP_STATELESS_MEDIA_MODE
  ```
- **Pass All Container Variables**:
  ```ini
  clear_env = no
  ```

---

## 4. Managed Key Groups (`$config_key_groups`)

`config/application.php` organizes recognized environment variables by core and plugin responsibility in `$config_key_groups`:

| Group | Keys | Purpose |
| :--- | :--- | :--- |
| **`bedrock`** | `WP_ENV`, `WP_ENVIRONMENT_TYPE`, `WP_HOME`, `WP_SITEURL`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `DB_PREFIX`, `DB_SSL`, `DATABASE_URL`, `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY`, `AUTH_SALT`, `SECURE_AUTH_SALT`, `LOGGED_IN_SALT`, `NONCE_SALT`, `DISABLE_WP_CRON`, `DISALLOW_FILE_MODS`, `DISALLOW_INDEXING`, `WP_POST_REVISIONS`, `WP_DEBUG_LOG` | Core Bedrock environment, URL, MySQL connection, WordPress salts, cron, and hardening toggles. |
| **`google-site-kit`** | `GOOGLESITEKIT_ENCRYPTION_KEY`, `GOOGLESITEKIT_ENCRYPTION_SALT` | Optional AES-256-CTR encryption keys shared across Reader and Editor pods for `google-site-kit`. |
| **`iap-auth-shield`** | `IAP_AUTH_ENABLED`, `IAP_AUDIENCE` | Enables Identity-Aware Proxy JWT verification and audience binding on the Editor service. |
| **`wp-stateless`** | `WP_STATELESS_MEDIA_JSON_KEY` plus any `/^WP_STATELESS_[A-Z0-9_]+$/` key in `$_ENV` / `$_SERVER` | Automatically defines WordPress constants for `wp-stateless` GCS media offload (skipping `''` and `null`, while preserving `(bool) false` and `(int) 0`). |
| **`OTEL_*` *(Preserved)*** | `OTEL_SERVICE_NAME`, `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_PROTOCOL`, etc. | Non-secret OpenTelemetry SDK runtime settings. Intentionally **excluded** from hydration/scrubbing so auto-instrumentation exporters can read them throughout the request lifecycle. |

---

## 5. Post-Bootstrap Superglobal Scrubbing & Host Header Validation

### Superglobal Scrubbing (`$scrub_keys`)

Immediately after `Config::apply()` defines immutable PHP constants for WordPress, `config/application.php` removes all `.env`-loaded keys (`array_keys($dotenv_loaded_vars)`) and all `$managed_config_keys` from both `$_ENV` and `$_SERVER` (skipping any `OTEL_*` keys):

- Prevents leaked database passwords, WordPress salts, Site Kit encryption keys, IAP settings, and `WP_STATELESS_MEDIA_JSON_KEY` service account credentials from appearing in `phpinfo()`, debug dumps, or third-party plugin superglobal inspections—even when no `.env` file is present.
- Cleans up temporary bootstrap variables (`$dotenv`, `$dotenv_loaded_vars`, `$config_key_groups`, `$managed_config_keys`, `$scrub_keys`, `$key`, `$value`) before WordPress loads.

### `HTTP_HOST` & `HTTP_X_FORWARDED_HOST` Format Validation

Before constructing dynamic `WP_HOME` and `WP_SITEURL` values on web requests, `config/application.php` validates the incoming host header against:

```text
/^[a-z0-9.-]+(?::\d+)?$/i
```

- **Valid hostnames / IPv4 + port** (e.g. `blog.example.com`, `127.0.0.1:18080`): Used with `HTTP_X_FORWARDED_PROTO` (`https` vs `http`) to define `WP_HOME` and `WP_SITEURL`.
- **Malformed or poisoned host headers** (e.g. path suffixes, XSS characters, comma-separated proxy chains, or `user@host` authority tricks): Rejected immediately; both `$_SERVER['HTTP_HOST']` and `$_SERVER['HTTP_X_FORWARDED_HOST']` are `unset()` so downstream WordPress Core and plugins cannot consume the poisoned header, and configuration falls back to `WP_HOME` (or throws a `RuntimeException` if `WP_HOME` is unset).
