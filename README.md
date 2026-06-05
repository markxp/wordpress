# WordPress for nerdy

This repository can be sliced into 3 parts.

1. WordPress plugins: IAP auth shield
2. A WordPress site application code contains opionated theme and plugins.
3. A reference (guide) for how to build an OCI image (with `podman` or `docker`) and deploy to `knative` or `docker-compose` environment.

It includes OpenTelemetry auto-instrument for WordPress.

It does NOT recommeded to use the scripts under `deployment/*` directly without modifications.

## Bedrock WordPress

This WordPress build depends on [roots/bedrock](https://github.com/roots/bedrock), which is a modular, more modernized structured (than official), PHP package management tool wrapped flavor WordPress.

It allows us to use `composer` to install and manage our application code.

## configurations

Bedrock sets configurations through `config/application.php`, which will read related environment defined by `WP_ENV`.
For an example, set WP_ENV="app-engine" then it will trootry to load `config/environments/app-engine.php`.

`config/application.php` loads essential environment variables, and then read `config/environments/{env}` to overwrite old values,
and then apply configurations.

By following this apporoach, it is good to set debug settings in `config/environments/{env}`. And only keeps the environment-based secret values in `.env` file or export as environment variables.

### Must set environment variables

WordPress settings

* `WP_ENV` (canonical name: `WP_ENVIRONMENT_TYPE`, but bedrock use `WP_ENV`)
* `WP_HOME`
* `WP_SITEURL` (in bedrock, default to `${WP_HOME}/wp`)

`WP_HOME` is a required variable in bedrock. While `WP_SITEURL` is derived from `${WP_HOME}/wp`, it is also required by WordPress.

`WP_ENV` is the environment identifier. While WordPress >5.5, `WP_ENVIRONMENT_TYPE` is in favor for standardizing plugin behavior in different environments. `WP_ENVIRONMENT_TYPE` has 4 valid values,

* local
* development
* staging
* production

but `WP_ENV` does not have a WordPress standard, while `WP_ENVIRONMENT_TYPE` is. In bedrock, [`WP_ENVIRONMENT_TYPE` is inferred from `WP_ENV`](https://roots.io/bedrock/docs/environment-variables/#wp_environment_type).

---
database settings

* DB_NAME
* DB_USER
* DB_PASSWORD
* DB_HOST

For simplicity, you can use `DATABASE_URL` for using a DSN instead. It will be parsed into the above variables.

For `DB_HOST`, it has default value in `config/application.php` as `localhost`. But it is not a good practice leaving it empty. So I write it as a must-to.

---
WordPress security salt & key

generate by [root's generater](https://roots.io/salts.html) or `wp salt generate` and manual formatting.

* AUTH_KEY
* SECURE_AUTH_KEY
* LOGGED_IN_KEY
* NONCE_KEY
* AUTH_SALT
* SECURE_AUTH_SALT
* LOGGED_IN_SALT
* NONCE_SALT

---

### optional environment variables

---
debug settings

* WP_DEBUG_DISPLAY
* WP_DEBUG_LOG
* SCRIPT_DEBUG

---
database settings

* DB_CHARSET=utf8mb4
* DB_COLLATE=utf8mb4_unicode_ci

---
others

* [DISABLE_WP_CRON](https://roots.io/bedrock/docs/wp-cron/)=true
* WP_POST_REVISIONS It limits the number of revisions of posts.
* `WORDPRESS_SERVICE_ROLE` Configures the current service role. Can be either `editor` or `reader` (defaults to `editor`).

Open Telemetry

* OTEL_SERVICE_NAME
* OTEL_EXPORTER_OTLP_PROTOCOL=grpc
* OTEL_EXPORTER_OTLP_ENDPOINT (default: <http://localhost:4317>)

---

## Deployment Strategy & Service Roles

Our deployment strategy divides the WordPress stack into two distinct, isolated service roles to optimize performance, security, and task scheduling:

1. **Editor Service (`WORDPRESS_SERVICE_ROLE=editor`)**:
   - Dedicated backend service for administrators, editors, and content creators.
   - Protected by GCP Identity-Aware Proxy (IAP) (`IAP_AUTH_ENABLED=true`) to enforce strict authentication.
   - The **only** service meant to react to and trigger WordPress cron jobs.

2. **Reader Service (`WORDPRESS_SERVICE_ROLE=reader`)**:
   - Public-facing frontend service that serves content to visitors.
   - Accessible publicly without IAP authentication.
   - Utilizes a REST API firewall to restrict access to sensitive endpoints.
   - **Cron Access Blocked**: Direct HTTP requests to `wp-cron.php` are blocked (returning `404 Not Found`) to prevent public clients from hitting the path to trigger cron tasks, avoiding server resource exhaustion and maintaining fast reader response times.

---

## Changelog

### [2.0.0] - 2026-06-05

#### Added
- Implemented a production-only `wp-cron.php` HTTP access blocker in `config/application.php` for the `reader` service role.

#### Breaking Changes
- **WP-Cron Access Restrictions**: Direct HTTP access to `wp-cron.php` is now blocked (returning `404 Not Found`) on the `reader` service in production. This enforces the deployment strategy where only the `editor` service handles cron execution. Any external HTTP calls attempting to trigger `wp-cron.php` on the `reader` service will fail with a `404`. Cron execution should be managed via WP-CLI on the `editor` service or through system cron.
