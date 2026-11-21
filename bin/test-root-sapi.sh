#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${REPO_ROOT}/deployment/docker-compose.test.yaml"
SAPI_HOST="${SAPI_NGINX_HOST:-127.0.0.2}"
SAPI_PORT="${SAPI_NGINX_PORT:-18080}"
SAPI_HEALTH_PORT="${SAPI_NGINX_HEALTH_PORT:-18081}"
SITE_URL="http://${SAPI_HOST}:${SAPI_PORT}"

WORK_DIR="$(mktemp -d)"
chmod 755 "$WORK_DIR"
DOTFILES_DIR="${WORK_DIR}/dotfiles"
mkdir -p "$DOTFILES_DIR"
chmod 755 "$DOTFILES_DIR"
ENV_FILE="${DOTFILES_DIR}/latest"
MOCK_SQL_FILE="${WORK_DIR}/wp-mock.sql"

cat > "$ENV_FILE" <<EOF
DB_NAME=wordpress
DB_USER=wordpress
DB_PASSWORD=wordpress_test_password
DB_HOST=127.0.0.1
WP_ENV=testing
DISABLE_WP_CRON=true
WP_HOME=${SITE_URL}
WP_SITEURL=${SITE_URL}/wp
AUTH_KEY=sapi-test-auth-key
SECURE_AUTH_KEY=sapi-test-secure-auth-key
LOGGED_IN_KEY=sapi-test-logged-in-key
NONCE_KEY=sapi-test-nonce-key
AUTH_SALT=sapi-test-auth-salt
SECURE_AUTH_SALT=sapi-test-secure-auth-salt
LOGGED_IN_SALT=sapi-test-logged-in-salt
NONCE_SALT=sapi-test-nonce-salt
EOF
chmod 644 "$ENV_FILE"

echo "Generating preset mock WordPress SQL database on-the-fly..."
python3 "${REPO_ROOT}/tests/fixtures/create-mock-db.py" \
    --output "$MOCK_SQL_FILE" \
    --site-url "$SITE_URL"
chmod 644 "$MOCK_SQL_FILE"

export SAPI_DOTFILES_DIR="$DOTFILES_DIR"
export SAPI_INIT_SQL="$MOCK_SQL_FILE"
export SAPI_NGINX_PORT="$SAPI_PORT"
export SAPI_NGINX_HEALTH_PORT="$SAPI_HEALTH_PORT"
export SAPI_PHP_FPM_DOCKERFILE="${SAPI_PHP_FPM_DOCKERFILE:-deployment/php-fpm.Dockerfile}"
export SAPI_WORDPRESS_IMAGE="${SAPI_WORDPRESS_IMAGE:-localhost/wordpress-sapi-test:latest}"

CONTAINER_CLI="docker"
COMPOSE_CMD=(docker compose -f "$COMPOSE_FILE")
if ! docker compose version >/dev/null 2>&1; then
    CONTAINER_CLI="podman"
    if command -v podman-compose >/dev/null 2>&1; then
        COMPOSE_CMD=(podman-compose -f "$COMPOSE_FILE")
    else
        COMPOSE_CMD=(podman compose -f "$COMPOSE_FILE")
    fi
fi

cleanup() {
    "${COMPOSE_CMD[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

if [[ "${SAPI_SKIP_BUILD:-0}" == "1" ]]; then
    "${COMPOSE_CMD[@]}" up -d --no-build
else
    "${COMPOSE_CMD[@]}" up -d --build
fi

echo "Waiting for MySQL 8.4 healthcheck..."
for i in $(seq 1 60); do
    if "$CONTAINER_CLI" exec wp-sapi-mysql mysqladmin ping -h 127.0.0.1 -P 3306 --protocol=TCP -u wordpress -pwordpress_test_password >/dev/null 2>&1; then
        break
    fi
    if [[ "$i" -eq 60 ]]; then
        echo "ERROR: MySQL 8.4 failed healthcheck within 60s." >&2
        "$CONTAINER_CLI" logs wp-sapi-mysql || true
        exit 1
    fi
    sleep 1
done

# Ensure the preset mock database is loaded even if entrypoint init skipped a bind-mount edge case
MOCK_TABLE_COUNT="$("$CONTAINER_CLI" exec wp-sapi-mysql mysql -N -B -h 127.0.0.1 -P 3306 --protocol=TCP -uwordpress -pwordpress_test_password wordpress -e "SHOW TABLES LIKE 'wp_options';" | wc -l | tr -d ' ')"
if [[ "$MOCK_TABLE_COUNT" -eq 0 ]]; then
    echo "Importing preset mock WordPress SQL database into MySQL 8.4..."
    "$CONTAINER_CLI" exec -i wp-sapi-mysql mysql -h 127.0.0.1 -P 3306 --protocol=TCP -uwordpress -pwordpress_test_password wordpress < "$MOCK_SQL_FILE"
fi

echo "Waiting for Nginx and PHP-FPM healthchecks..."
for i in $(seq 1 60); do
    if curl -fsS "http://127.0.0.1:${SAPI_HEALTH_PORT}/nginx_status" >/dev/null 2>&1 \
        && "$CONTAINER_CLI" exec wp-sapi-php-fpm php-fpm-ping >/dev/null 2>&1 \
        && curl -fsS "${SITE_URL}/" >/dev/null 2>&1; then
        break
    fi
    if [[ "$i" -eq 60 ]]; then
        echo "ERROR: Nginx / PHP-FPM / WordPress failed readiness check within 60s." >&2
        curl -i -sS "http://127.0.0.1:${SAPI_HEALTH_PORT}/nginx_status" >&2 || true
        "$CONTAINER_CLI" exec wp-sapi-php-fpm php-fpm-ping >&2 || true
        curl -i -sS "${SITE_URL}/" >&2 || true
        "$CONTAINER_CLI" logs wp-sapi-mysql || true
        "$CONTAINER_CLI" logs wp-sapi-nginx || true
        "$CONTAINER_CLI" logs wp-sapi-php-fpm || true
        exit 1
    fi
    sleep 1
done

echo "Verifying runtime Composer authoritative classmap, OPcache, and Imagick PDF policy..."
"$CONTAINER_CLI" exec wp-sapi-php-fpm php -r '
$loader = require "/var/www/html/vendor/autoload.php";
if (!$loader->isClassMapAuthoritative()) {
    fwrite(STDERR, "Expected Composer classmap-authoritative autoloader to be enabled.\n");
    exit(1);
}
if (!extension_loaded("Zend OPcache")) {
    fwrite(STDERR, "Expected Zend OPcache extension to be loaded.\n");
    exit(1);
}
if (!extension_loaded("imagick")) {
    fwrite(STDERR, "Expected imagick extension to be loaded.\n");
    exit(1);
}
$pdf = "%PDF-1.4\n"
    . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
    . "2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n"
    . "3 0 obj<</Type/Page/MediaBox[0 0 72 72]/Parent 2 0 R/Resources<<>>>>endobj\n"
    . "xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \n"
    . "trailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF\n";
$im = new Imagick();
$im->readImageBlob($pdf);
if ($im->getNumberImages() < 1) {
    fwrite(STDERR, "Imagick failed to decode minimal 1-page PDF blob.\n");
    exit(1);
}
$im->clear();
'

if [[ ! -f "${REPO_ROOT}/vendor/bin/phpunit" ]]; then
    echo "Installing dev dependencies via Composer container for PHPUnit..."
    "$CONTAINER_CLI" run --rm \
        -u "$(id -u):$(id -g)" \
        -v "${REPO_ROOT}:/workspace:z" \
        -w /workspace \
        mirror.gcr.io/composer/composer:latest \
        composer install --no-interaction --prefer-dist --ignore-platform-reqs
fi

if command -v php >/dev/null 2>&1 && OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);'; then
    SAPI_STACK_BASE_URL="${SITE_URL}" OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php "${REPO_ROOT}/vendor/bin/phpunit" -c "${REPO_ROOT}/phpunit.sapi.xml.dist"
else
    "$CONTAINER_CLI" run --rm --network host \
        -e "SAPI_STACK_BASE_URL=${SITE_URL}" \
        -e "OTEL_PHP_DISABLED_INSTRUMENTATIONS=all" \
        -v "${REPO_ROOT}:/workspace:z" \
        -w /workspace \
        mirror.gcr.io/library/php:8.5.12-cli \
        php vendor/bin/phpunit -c phpunit.sapi.xml.dist
fi
