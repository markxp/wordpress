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

* WP_ENV
* WP_HOME
* WP_SITEURL

`WP_HOME` is a required variable in bedrock. While `WP_SITEURL` is derived from `${WP_HOME}/wp`, it is also required by WordPress.

`WP_ENV` is the environment identifier. While WordPress >5.5, `WP_ENVIRONMENT_TYPE` is in favor for standardizing plugin behavior in different environments. `WP_ENVIRONMENT_TYPE` has 4 valid values,

* local
* development
* staging
* production

but `WP_ENV` does not have a standard.

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

WordPress settings

* WP_ENVIRONMENT_TYPE (default: production) It controls which file in `config/environments` will be loaded.

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

Open Telemetry

* OTEL_SERVICE_NAME
* OTEL_EXPORTER_OTLP_PROTOCOL=grpc
* OTEL_EXPORTER_OTLP_ENDPOINT (default: <http://localhost:4317>)
