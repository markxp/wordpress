#!/bin/bash
set -eu

# Function to export variables from .env file
load_env_file() {
    local env_file="$1"
    if [ -f "$env_file" ]; then
        echo "Loading environment variables from $env_file"
        set -a
        source "$env_file"
        set +a
    fi
}

# 1. Load .env files (following bedrock pattern: .env then .env.local)
ROOT_DIR="${ROOT_DIR:-/var/www/html}"
load_env_file "${ROOT_DIR}/.env"

# 2. Apply PHP configurations (php.ini & conf.d runtime override)
PHP_INI_FILE="${PHP_INI_DIR:-/usr/local/etc/php}/php.ini"
PHP_CONF_DIR="${PHP_CONF_DIR:-${PHP_INI_SCAN_DIR:-/usr/local/etc/php/conf.d}}"

if [ -f "$PHP_INI_FILE" ]; then
    sed -i \
        -e "s/^;\?memory_limit\s*=.*/memory_limit = ${MEM_LIMIT:-256M}/g" \
        -e "s/^;\?post_max_size\s*=.*/post_max_size = ${POST_MAX_SIZE:-64M}/g" \
        -e "s/^;\?upload_max_filesize\s*=.*/upload_max_filesize = ${UPLOAD_MAX_FILE_SIZE:-64M}/g" \
        -e "s/^;\?max_input_time\s*=.*/max_input_time = ${MAX_INPUT_TIME:-30}/g" \
        -e "s/^;\?max_execution_time\s*=.*/max_execution_time = ${MAX_EXECUTION_TIME:-30}/g" \
        -e "s/^;\?max_file_uploads\s*=.*/max_file_uploads = ${MAX_FILE_UPLOADS:-20}/g" \
        "$PHP_INI_FILE" && \
    echo "[PHP.ini] successfully swapped environment variables"
else
    echo "[PHP.ini] not found at $PHP_INI_FILE. Skipping direct substitutions."
fi

if [ -d "$PHP_CONF_DIR" ]; then
    cat > "${PHP_CONF_DIR}/99-runtime-limits.ini" <<EOF
memory_limit = ${MEM_LIMIT:-256M}
post_max_size = ${POST_MAX_SIZE:-64M}
upload_max_filesize = ${UPLOAD_MAX_FILE_SIZE:-64M}
max_input_time = ${MAX_INPUT_TIME:-30}
max_execution_time = ${MAX_EXECUTION_TIME:-30}
max_file_uploads = ${MAX_FILE_UPLOADS:-20}
EOF
fi

# 3. Apply PHP-FPM pool (www.conf) settings
POOL_CONF="${PHP_FPM_POOL_CONF:-/usr/local/etc/php-fpm.d/www.conf}"
if [ -f "$POOL_CONF" ]; then
    sed -i \
        -e "s/^;\?pm = .*/pm = ${PM:-static}/g" \
        -e "s/^;\?pm\.max_children = .*/pm.max_children = ${PM_MAX_CHILDREN:-5}/g" \
        -e "s/^;\?request_terminate_timeout = .*/request_terminate_timeout = ${REQUEST_TERMINATE_TIMEOUT:-0}/g" \
        -e "s/^;\?pm\.status_listen = .*/pm.status_listen = ${PM_STATUS_LISTEN:-127.0.0.1:9001}/g" \
        -e "s|^;\?pm\.status_path = .*|pm.status_path = /status|g" \
        -e "s|^;\?ping\.path = .*|ping.path = /ping|g" \
        -e "s|^;\?ping\.response = .*|ping.response = pong|g" \
        -e 's|^;\?access\.format = .*|access.format = "%{HTTP_X_REAL_IP}e %s %m %{REQUEST_URI}e duration=%{milliseconds}d mem=%{megabytes}M cpu=%C%%"|g' \
        -e 's|^;\?access\.suppress_path\[\] = /health_check\.php|access.suppress_path[] = /status\naccess.suppress_path[] = /ping|g' \
        -e 's|^;\?slowlog = .*|slowlog = /proc/self/fd/2|g' \
        -e 's|^;\?request_slowlog_timeout = .*|request_slowlog_timeout = 3s|g' \
        -e "s|^;\?chdir = /var/www.*|chdir = ${ROOT_DIR}/web|g" \
        "$POOL_CONF" && \
    if ! grep -q "^; --- entrypoint-env-exports ---$" "$POOL_CONF"; then
        cat << 'EOF' >> "$POOL_CONF"

; --- entrypoint-env-exports ---
clear_env = yes
env[PATH] = /usr/local/bin:/usr/bin:/bin
env[TMP] = /tmp
env[TMPDIR] = /tmp
env[TEMP] = /tmp
env[LIBMYSQL_ENABLE_CLEARTEXT_PLUGIN] = $LIBMYSQL_ENABLE_CLEARTEXT_PLUGIN
env[OTEL_SDK_DISABLED] = $OTEL_SDK_DISABLED
env[OTEL_PHP_AUTOLOAD_ENABLED] = $OTEL_PHP_AUTOLOAD_ENABLED
env[OTEL_PHP_DISABLED_INSTRUMENTATIONS] = $OTEL_PHP_DISABLED_INSTRUMENTATIONS
env[OTEL_SERVICE_NAME] = $OTEL_SERVICE_NAME
env[OTEL_RESOURCE_ATTRIBUTES] = $OTEL_RESOURCE_ATTRIBUTES
env[OTEL_PROPAGATORS] = $OTEL_PROPAGATORS
env[OTEL_EXPORTER_OTLP_ENDPOINT] = $OTEL_EXPORTER_OTLP_ENDPOINT
env[OTEL_EXPORTER_OTLP_PROTOCOL] = $OTEL_EXPORTER_OTLP_PROTOCOL
env[OTEL_TRACES_EXPORTER] = $OTEL_TRACES_EXPORTER
env[OTEL_LOGS_EXPORTER] = $OTEL_LOGS_EXPORTER
env[OTEL_METRICS_EXPORTER] = $OTEL_METRICS_EXPORTER
env[WP_ENV] = $WP_ENV
env[WP_ENVIRONMENT_TYPE] = $WP_ENVIRONMENT_TYPE
env[WP_HOME] = $WP_HOME
env[WP_SITEURL] = $WP_SITEURL
env[IAP_AUTH_ENABLED] = $IAP_AUTH_ENABLED
env[IAP_AUDIENCE] = $IAP_AUDIENCE
env[GOOGLE_APPLICATION_CREDENTIALS] = $GOOGLE_APPLICATION_CREDENTIALS
env[GOOGLE_AUTH_SUPPRESS_CREDENTIALS_WARNING] = $GOOGLE_AUTH_SUPPRESS_CREDENTIALS_WARNING
env[GCE_METADATA_HOST] = $GCE_METADATA_HOST
EOF
    fi
    echo "[PHP-FPM.conf] successfully swapped environment variables"
else
    echo "[PHP-FPM.conf] www.conf does not exist at $POOL_CONF. Skipping modification."
fi

# 4. DEV_ON Logic (OpCache and Error Logging)
DEV_ON="${DEV_ON:-false}"
OPCACHE_CONF="${PHP_CONF_DIR}/opcache-recommended.ini"
ERROR_CONF="${PHP_CONF_DIR}/error-logging.ini"

if [ "$DEV_ON" = "true" ]; then
    echo "Development mode is ON. Applying development settings via sed..."

    if [ -f "$OPCACHE_CONF" ]; then
        sed -i \
            -e "s/opcache.validate_timestamps=false/opcache.validate_timestamps=true/g" \
            "$OPCACHE_CONF"
        if ! grep -q "opcache.revalidate_freq" "$OPCACHE_CONF"; then
            echo "opcache.revalidate_freq=2" >> "$OPCACHE_CONF"
        else
            sed -i "s/opcache.revalidate_freq=.*/opcache.revalidate_freq=2/g" "$OPCACHE_CONF"
        fi
    fi

    if [ -f "$ERROR_CONF" ]; then
        sed -i \
            -e "s/display_errors = Off/display_errors = On/g" \
            -e "s/display_startup_errors = Off/display_startup_errors = On/g" \
            -e "s/html_errors = Off/html_errors = On/g" \
            -e "s/expose_php = Off/expose_php = On/g" \
            "$ERROR_CONF"
    fi
else
    echo "Development mode is OFF. Ensuring production settings..."
    if [ -f "$OPCACHE_CONF" ]; then
        sed -i "s/opcache.validate_timestamps=true/opcache.validate_timestamps=false/g" "$OPCACHE_CONF"
    fi
    if [ -f "$ERROR_CONF" ]; then
        sed -i \
            -e "s/display_errors = On/display_errors = Off/g" \
            -e "s/display_startup_errors = On/display_startup_errors = Off/g" \
            -e "s/html_errors = On/html_errors = Off/g" \
            -e "s/expose_php = On/expose_php = Off/g" \
            "$ERROR_CONF"
    fi
fi

# Normalize flag-only invocations (e.g. `-F` -> `php-fpm -F`)
if [ "${1#-}" != "$1" ]; then
    set -- php-fpm "$@"
fi

# Execute the main command
echo "Starting application..."
if command -v docker-php-entrypoint >/dev/null 2>&1; then
    exec docker-php-entrypoint "$@"
fi
exec "$@"
