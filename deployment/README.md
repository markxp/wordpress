# The E2E test environment

## This folder is for E2E test environment & demostration purpose

### It contains application configuration

* A `php-fpm` Dockerfile for building an application container image.
* A `nginx.conf` for `nginx` configuration for web-server, which uses `fastcgi` to connect to the application container. Also providing OpenTelemetry tracing spans.
* A `otel-config.yaml` for `OpenTelemetry` collector configuration.

### It does not contains mysql configuration

* A `mysql` container image. Because we use external database though TCP or unix socket in the application container.

### As the deployment orchestrator, it also contains `knative` and `docker-compose` deployable script (yaml)

* A `knative.yaml` (and `docker-compose.yaml`) for deploying a multi-container service. (The `docker-compose.yaml` should have the same meaning as `knative.yaml`)

The `knative` service is fully stateless.
