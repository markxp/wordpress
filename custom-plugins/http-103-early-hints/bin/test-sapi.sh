#!/usr/bin/env bash
set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

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

if [[ -x "${PLUGIN_ROOT}/vendor/bin/phpunit" ]]; then
    PHPUNIT_BIN="${PLUGIN_ROOT}/vendor/bin/phpunit"
elif [[ -x "${PLUGIN_ROOT}/../../vendor/bin/phpunit" ]]; then
    PHPUNIT_BIN="${PLUGIN_ROOT}/../../vendor/bin/phpunit"
else
    echo "Error: phpunit binary not found." >&2
    exit 1
fi

free_port() {
    php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); fclose($s); echo explode(":", $n)[1];'
}

FPM_PORT="$(free_port)"
NGINX_PORT="$(free_port)"
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

mkdir -p "${WORK_DIR}/tmp-body" "${WORK_DIR}/tmp-proxy" "${WORK_DIR}/tmp-fastcgi" "${WORK_DIR}/tmp-uwsgi" "${WORK_DIR}/tmp-scgi"

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
pm.max_children = 2
clear_env = no
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
    access_log ${WORK_DIR}/nginx-access.log;
    client_body_temp_path ${WORK_DIR}/tmp-body;
    proxy_temp_path ${WORK_DIR}/tmp-proxy;
    fastcgi_temp_path ${WORK_DIR}/tmp-fastcgi;
    uwsgi_temp_path ${WORK_DIR}/tmp-uwsgi;
    scgi_temp_path ${WORK_DIR}/tmp-scgi;

    server {
        listen 127.0.0.1:${NGINX_PORT};
        server_name localhost;
        root ${PLUGIN_ROOT}/tests-sapi;

        location / {
            fastcgi_pass 127.0.0.1:${FPM_PORT};
            fastcgi_param SCRIPT_FILENAME ${PLUGIN_ROOT}/tests-sapi/front-controller.php;
            fastcgi_param REQUEST_URI \$request_uri;
            fastcgi_param REQUEST_METHOD \$request_method;
            fastcgi_param QUERY_STRING \$query_string;
            fastcgi_param CONTENT_TYPE \$content_type;
            fastcgi_param CONTENT_LENGTH \$content_length;
            fastcgi_param HTTP_HOST \$http_host;
            fastcgi_param SERVER_NAME \$server_name;
            fastcgi_param SERVER_PORT \$server_port;
            fastcgi_param SERVER_PROTOCOL \$server_protocol;
            fastcgi_param REMOTE_ADDR \$remote_addr;
        }
    }
}
EOF

"$PHP_FPM_BIN" "${ROOT_FPM_FLAGS[@]}" -y "${WORK_DIR}/php-fpm.conf"
"$NGINX_BIN" -c "${WORK_DIR}/nginx.conf"

for _ in $(seq 1 30); do
    if php -r '$r = @file_get_contents($argv[1]); exit($r === false ? 1 : 0);' "http://127.0.0.1:${NGINX_PORT}/?sapi_health=1"; then
        break
    fi
    sleep 0.1
done

SAPI_BASE_URL="http://127.0.0.1:${NGINX_PORT}" php "$PHPUNIT_BIN" -c "${PLUGIN_ROOT}/phpunit.sapi.xml.dist" "$@"
