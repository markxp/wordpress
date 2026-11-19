#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${REPO_ROOT}/deployment/docker-compose.test.yaml"
SAPI_PORT="${SAPI_NGINX_PORT:-18080}"
SAPI_HEALTH_PORT="${SAPI_NGINX_HEALTH_PORT:-18081}"

WORK_DIR="$(mktemp -d)"
ENV_FILE="${WORK_DIR}/test.env"

cat > "$ENV_FILE" <<EOF
DB_NAME=wordpress
DB_USER=wordpress
DB_PASSWORD=wordpress_test_password
DB_HOST=127.0.0.1
WP_ENV=testing
WP_HOME=http://127.0.0.1:${SAPI_PORT}
WP_SITEURL=http://127.0.0.1:${SAPI_PORT}/wp
AUTH_KEY=sapi-test-auth-key
SECURE_AUTH_KEY=sapi-test-secure-auth-key
LOGGED_IN_KEY=sapi-test-logged-in-key
NONCE_KEY=sapi-test-nonce-key
AUTH_SALT=sapi-test-auth-salt
SECURE_AUTH_SALT=sapi-test-secure-auth-salt
LOGGED_IN_SALT=sapi-test-logged-in-salt
NONCE_SALT=sapi-test-nonce-salt
EOF

export SAPI_ENV_FILE="$ENV_FILE"
export SAPI_NGINX_PORT="$SAPI_PORT"
export SAPI_NGINX_HEALTH_PORT="$SAPI_HEALTH_PORT"

COMPOSE_CMD=(docker compose -f "$COMPOSE_FILE")
if ! docker compose version >/dev/null 2>&1; then
    COMPOSE_CMD=(podman-compose -f "$COMPOSE_FILE")
fi

cleanup() {
    "${COMPOSE_CMD[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

"${COMPOSE_CMD[@]}" up -d --build

echo "Waiting for Nginx and PHP-FPM healthchecks..."
for _ in $(seq 1 40); do
    if curl -fsS "http://127.0.0.1:${SAPI_HEALTH_PORT}/nginx_status" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

echo "Installing test WordPress database and activating custom plugins..."
docker exec wp-sapi-php-fpm sh -c "
    php -r '
        define(\"ABSPATH\", \"/var/www/html/web/wp/\");
        require \"/var/www/html/vendor/autoload.php\";
        require \"/var/www/html/config/application.php\";
        require_once ABSPATH . \"wp-admin/includes/upgrade.php\";
        wp_install(\"SAPI Test\", \"admin\", \"admin@example.com\", true, \"\", \"password\");
        activate_plugins([
            \"auto-meta-description/auto-meta-description.php\",
            \"http-103-early-hints/http-103-early-hints.php\",
            \"modern-site-icons/modern-site-icons.php\",
            \"remove-wp-default-link-header/remove-wp-default-link-header.php\",
            \"security-hardening/security-hardening.php\",
        ]);
    '
"

SAPI_STACK_BASE_URL="http://127.0.0.1:${SAPI_PORT}" \
    docker run --rm --network host -v "${REPO_ROOT}:/workspace:z" -w /workspace mirror.gcr.io/library/php:8.5.12-cli \
    php vendor/bin/phpunit -c phpunit.sapi.xml.dist
