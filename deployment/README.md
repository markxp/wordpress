# The E2E test environment

## This folder is for E2E test environment & demostration purpose

### It contains application configuration

* A `php-fpm` Dockerfile for building an application container image.
* `nginx.default.conf.template` (and `nginx.conf`) with reference Nginx security hardening for Bedrock WordPress, including:
  * **Read-Only Public Server Guard**: Restricts the default public server to `GET`, `HEAD`, and `OPTIONS` (`405` for `POST`/`PUT`/`DELETE`/`PATCH`).
  * **Query-Parameter Bypass Blocker**: Blocks `?tb=`, `?feed=`, `?author=`, `?author_name=`, and `?rest_route=` before PHP-FPM while leaving `<permalink>/embed` (`?embed=`) available.
  * **Core Script & Pretty-Permalink Endpoint Blocking**: Blocks `xmlrpc.php`, `wp-login.php`, `wp-comments-post.php`, `wp-cron.php`, `wp-trackback.php`, `/wp-admin/` (except `/wp/wp-admin/admin-ajax.php`), pretty-permalink `/trackback` and `/feed` routes, `/author` archives, `/wp-json/wp/v2/comments`, and canonical admin/login redirect aliases (`/admin`, `/dashboard`, `/login`).
  * **Explicit Front-Controller Whitelist**: Restricts PHP-FPM execution on the public server strictly to `location = /index.php` and `location = /wp/wp-admin/admin-ajax.php`, returning `404` for all other `.php` paths.
  * **OpenTelemetry Tracing**: Exports Nginx request spans to the sidecar collector.
* A `otel-config.yaml` for `OpenTelemetry` collector configuration.

### It does not contains mysql configuration

* A `mysql` container image. Because we use external database though TCP or unix socket in the application container.

### As the deployment orchestrator, it also contains `knative` and `docker-compose` deployable script (yaml)

* A `knative.yaml` (and `docker-compose.yaml`) for deploying a multi-container service. (The `docker-compose.yaml` should have the same meaning as `knative.yaml`)
* A [`wp-cron-trigger.md`](wp-cron-trigger.md) guide with a recommended zero-cost Google Apps Script (`apps-script-oauth2` + Service Account OIDC token) and Cloud Scheduler implementation for triggering `/wp/wp-cron.php` when `DISABLE_WP_CRON=true`.

The `knative` service is fully stateless.
