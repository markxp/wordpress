#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-wordpress}"
DB_USER="${DB_USER:-wordpress}"
DB_PASSWORD="${DB_PASSWORD:-wordpress_test_password}"

if [[ "$DB_HOST" == *:* ]]; then
    WP_DB_HOST="$DB_HOST"
else
    WP_DB_HOST="${DB_HOST}:${DB_PORT}"
fi

find_bin() {
    for candidate in "$@"; do
        if command -v "$candidate" >/dev/null 2>&1; then
            command -v "$candidate"
            return 0
        fi
        if [[ -x "$candidate" ]]; then
            echo "$candidate"
            return 0
        fi
    done
    return 1
}

PHP_FPM_BIN="$(find_bin php-fpm php-fpm8.5 php-fpm8.4 /usr/sbin/php-fpm8.5 /usr/sbin/php-fpm8.4 /usr/local/sbin/php-fpm)" || {
    echo "Error: php-fpm binary not found." >&2
    exit 1
}
NGINX_BIN="$(find_bin nginx /usr/sbin/nginx)" || {
    echo "Error: nginx binary not found." >&2
    exit 1
}
MYSQL_BIN="$(find_bin mysql mariadb)" || {
    echo "Error: mysql/mariadb client binary not found." >&2
    exit 1
}
PYTHON_BIN="$(find_bin python3)" || {
    echo "Error: python3 binary not found." >&2
    exit 1
}

PHPUNIT_BIN="${REPO_ROOT}/vendor/bin/phpunit"
if [[ ! -f "$PHPUNIT_BIN" ]]; then
    echo "Error: ${PHPUNIT_BIN} not found. Run 'composer install' first." >&2
    exit 1
fi
if [[ ! -f "${REPO_ROOT}/web/wp/wp-blog-header.php" ]]; then
    echo "Error: WordPress core (${REPO_ROOT}/web/wp) not installed. Run 'composer install' first." >&2
    exit 1
fi

free_port() {
    php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); fclose($s); echo explode(":", $n)[1];'
}

FPM_PORT="$(free_port)"
NGINX_PORT="$(free_port)"
SITE_URL="http://127.0.0.1:${NGINX_PORT}"
WORK_DIR="$(mktemp -d)"

cleanup() {
    if [[ -f "${WORK_DIR}/nginx.pid" ]]; then
        kill "$(cat "${WORK_DIR}/nginx.pid")" 2>/dev/null || true
    fi
    if [[ -f "${WORK_DIR}/php-fpm.pid" ]]; then
        kill "$(cat "${WORK_DIR}/php-fpm.pid")" 2>/dev/null || true
    fi
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

mkdir -p \
    "${WORK_DIR}/tmp-body" \
    "${WORK_DIR}/tmp-proxy" \
    "${WORK_DIR}/tmp-fastcgi" \
    "${WORK_DIR}/tmp-uwsgi" \
    "${WORK_DIR}/tmp-scgi"

MOCK_SQL_FILE="${WORK_DIR}/wp-mock.sql"
echo "Generating preset mock WordPress SQL database on-the-fly (${SITE_URL})..."
"$PYTHON_BIN" "${REPO_ROOT}/tests/fixtures/create-mock-db.py" \
    --output "$MOCK_SQL_FILE" \
    --site-url "$SITE_URL"

echo "Waiting for MySQL (${DB_HOST}:${DB_PORT}/${DB_NAME}) readiness..."
MYSQL_READY=0
for _ in $(seq 1 60); do
    if MYSQL_PWD="$DB_PASSWORD" "$MYSQL_BIN" --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" -e "SELECT 1;" >/dev/null 2>&1; then
        MYSQL_READY=1
        break
    fi
    sleep 0.5
done

if [[ "$MYSQL_READY" -ne 1 ]]; then
    echo "ERROR: MySQL at ${DB_HOST}:${DB_PORT} (${DB_NAME}) did not become ready within 30s." >&2
    exit 1
fi

echo "Loading preset mock WordPress SQL database into MySQL..."
MYSQL_PWD="$DB_PASSWORD" "$MYSQL_BIN" --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" < "$MOCK_SQL_FILE"

ROOT_FPM_FLAGS=()
NGINX_USER_DIRECTIVE=""
if [[ "$(id -u)" == "0" ]]; then
    ROOT_FPM_FLAGS=(-R)
    FPM_USER_LINE="user = nobody"
    NGINX_USER_DIRECTIVE="user nobody;"
else
    FPM_USER_LINE=""
fi

cat > "${WORK_DIR}/php-fpm.conf" <<EOF
[global]
pid = ${WORK_DIR}/php-fpm.pid
error_log = ${WORK_DIR}/php-fpm.log
daemonize = yes

[www]
${FPM_USER_LINE}
listen = 127.0.0.1:${FPM_PORT}
pm = static
pm.max_children = 4
clear_env = no
php_admin_value[variables_order] = EGPCS
env[DB_NAME] = "${DB_NAME}"
env[DB_USER] = "${DB_USER}"
env[DB_PASSWORD] = "${DB_PASSWORD}"
env[DB_HOST] = "${WP_DB_HOST}"
env[DB_PREFIX] = "wp_"
env[DB_SSL] = "false"
env[DATABASE_URL] = "null"
env[WP_ENV] = "testing"
env[WP_HOME] = "${SITE_URL}"
env[WP_SITEURL] = "${SITE_URL}/wp"
env[DISABLE_WP_CRON] = "true"
env[IAP_AUTH_ENABLED] = "false"
env[AUTH_KEY] = "sapi-test-auth-key"
env[SECURE_AUTH_KEY] = "sapi-test-secure-auth-key"
env[LOGGED_IN_KEY] = "sapi-test-logged-in-key"
env[NONCE_KEY] = "sapi-test-nonce-key"
env[AUTH_SALT] = "sapi-test-auth-salt"
env[SECURE_AUTH_SALT] = "sapi-test-secure-auth-salt"
env[LOGGED_IN_SALT] = "sapi-test-logged-in-salt"
env[NONCE_SALT] = "sapi-test-nonce-salt"
env[OTEL_SDK_DISABLED] = "true"
env[OTEL_PHP_AUTOLOAD_ENABLED] = "false"
env[OTEL_PHP_DISABLED_INSTRUMENTATIONS] = "all"
EOF

cat > "${WORK_DIR}/nginx.conf" <<EOF
${NGINX_USER_DIRECTIVE}
worker_processes 1;
pid ${WORK_DIR}/nginx.pid;
error_log ${WORK_DIR}/nginx-error.log warn;

events {
    worker_connections 64;
}

http {
    types {
        text/html                             html htm shtml;
        text/css                              css;
        text/xml                              xml;
        application/javascript                js mjs;
        application/json                      json;
        image/gif                             gif;
        image/jpeg                            jpeg jpg;
        image/png                             png;
        image/svg+xml                         svg svgz;
        image/webp                            webp;
        image/x-icon                          ico;
        text/plain                            txt;
    }
    default_type application/octet-stream;

    access_log ${WORK_DIR}/nginx-access.log;
    client_body_temp_path ${WORK_DIR}/tmp-body;
    proxy_temp_path ${WORK_DIR}/tmp-proxy;
    fastcgi_temp_path ${WORK_DIR}/tmp-fastcgi;
    uwsgi_temp_path ${WORK_DIR}/tmp-uwsgi;
    scgi_temp_path ${WORK_DIR}/tmp-scgi;

    server_tokens off;
    fastcgi_hide_header X-Powered-By;

    server {
        listen 127.0.0.1:${NGINX_PORT};
        server_name localhost 127.0.0.1;
        root ${REPO_ROOT}/web;
        index index.php index.htm index.html;

        location ~ ^/app/uploads/.*\.php$ {
            return 403;
        }

        location ~ /\.(?!well-known).* {
            return 404;
        }

        location / {
            try_files \$uri \$uri/ /index.php?\$args;
        }

        location ~ \.php$ {
            fastcgi_split_path_info ^(.+?\.php)(/.*)$;
            if (!-f \$document_root\$fastcgi_script_name) {
                return 404;
            }
            fastcgi_pass 127.0.0.1:${FPM_PORT};
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_param SCRIPT_NAME \$fastcgi_script_name;
            fastcgi_param PATH_INFO \$fastcgi_path_info;
            fastcgi_param DOCUMENT_ROOT \$document_root;
            fastcgi_param DOCUMENT_URI \$document_uri;
            fastcgi_param REQUEST_URI \$request_uri;
            fastcgi_param REQUEST_METHOD \$request_method;
            fastcgi_param QUERY_STRING \$query_string;
            fastcgi_param CONTENT_TYPE \$content_type;
            fastcgi_param CONTENT_LENGTH \$content_length;
            fastcgi_param GATEWAY_INTERFACE CGI/1.1;
            fastcgi_param SERVER_SOFTWARE nginx/\$nginx_version;
            fastcgi_param HTTP_HOST \$http_host;
            fastcgi_param SERVER_NAME \$server_name;
            fastcgi_param SERVER_ADDR \$server_addr;
            fastcgi_param SERVER_PORT \$server_port;
            fastcgi_param SERVER_PROTOCOL \$server_protocol;
            fastcgi_param REMOTE_ADDR \$remote_addr;
            fastcgi_param REMOTE_PORT \$remote_port;
            fastcgi_param HTTPS \$https if_not_empty;
            fastcgi_param HTTP_PROXY "";
        }
    }
}
EOF

"$PHP_FPM_BIN" "${ROOT_FPM_FLAGS[@]}" -d variables_order=EGPCS -y "${WORK_DIR}/php-fpm.conf"
"$NGINX_BIN" -c "${WORK_DIR}/nginx.conf"

HTTP_READY=0
for _ in $(seq 1 100); do
    if php -r '
        $ctx = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 2]]);
        @file_get_contents($argv[1], false, $ctx);
        $statusLine = $http_response_header[0] ?? "";
        exit(preg_match("#^HTTP/\S+\s+200\b#", $statusLine) ? 0 : 1);
    ' "${SITE_URL}/"; then
        HTTP_READY=1
        break
    fi
    sleep 0.1
done

if [[ "$HTTP_READY" -ne 1 ]]; then
    echo "ERROR: Host Bedrock SAPI stack (${SITE_URL}/) did not return HTTP 200 within 10s." >&2
    php -r '
        $ctx = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 2]]);
        $body = @file_get_contents($argv[1], false, $ctx);
        fwrite(STDERR, "=== Last HTTP Probe Headers ===\n" . implode("\n", $http_response_header ?? []) . "\n");
        fwrite(STDERR, "=== Last HTTP Probe Body (first 1000 bytes) ===\n" . substr((string) $body, 0, 1000) . "\n");
    ' "${SITE_URL}/" || true
    if [[ -f "${WORK_DIR}/php-fpm.log" ]]; then
        echo "=== php-fpm.log ===" >&2
        cat "${WORK_DIR}/php-fpm.log" >&2 || true
    fi
    if [[ -f "${WORK_DIR}/nginx-error.log" ]]; then
        echo "=== nginx-error.log ===" >&2
        cat "${WORK_DIR}/nginx-error.log" >&2 || true
    fi
    if [[ -f "${WORK_DIR}/nginx-access.log" ]]; then
        echo "=== nginx-access.log ===" >&2
        cat "${WORK_DIR}/nginx-access.log" >&2 || true
    fi
    exit 1
fi

SAPI_STACK_BASE_URL="${SITE_URL}" OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php "$PHPUNIT_BIN" -c "${REPO_ROOT}/phpunit.sapi.xml.dist" "$@"
