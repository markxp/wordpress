#!/bin/bash
set -eux

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

# 2. Apply PHP configurations (php.ini)
# Using sed to swap default values as requested
PHP_INI_FILE="${PHP_INI_DIR:-/usr/local/etc/php}/php.ini"

if [ -f "$PHP_INI_FILE" ]; then
    sed -i \
        -e "s/^memory_limit = 128M/memory_limit = ${MEM_LIMIT:-256M}/g" \
        -e "s/^post_max_size = 8M/post_max_size = ${POST_MAX_SIZE:-64M}/g" \
        -e "s/^upload_max_filesize = 2M/upload_max_filesize = ${UPLOAD_MAX_FILE_SIZE:-64M}/g" \
        -e "s/^max_input_time = 60/max_input_time = ${MAX_INPUT_TIME:-30}/g" \
        -e "s/^max_execution_time = 60/max_execution_time = ${MAX_EXECUTION_TIME:-30}/g" \
        -e "s/^max_file_uploads = 20/max_file_uploads = ${MAX_FILE_UPLOADS:-3}/g" \
        "$PHP_INI_FILE" && \
    echo "[PHP.ini] successfully swap environment variables"
else
    echo "[PHP.ini] not found at $PHP_INI_FILE. Skipping substitutions."
fi

# 3. Apply PHP-FPM pool (www.conf) settings
POOL_CONF="/usr/local/etc/php-fpm.d/www.conf"
if [ -f "$POOL_CONF" ]; then
    sed -i \
        -e "s/^;pm.max_children = 5/pm.max_children = ${PM_MAX_CHILDREN:-5}/g" \
        -e "s/^;request_terminate_timeout = 0/request_terminate_timeout = ${REQUEST_TERMINATE_TIMEOUT:-0}/g" \
        -e "s/^;pm.status_listen = 127.0.0.1:9001/pm.status_listen = ${PM_STATUS_LISTEN:-127.0.0.1:9001}/g" \
        -e "s|^;pm.status_path = /status|pm.status_path = /status|g" \
        -e 's|^;access.format = "%r - %u %t \\"%m %r%q%q\\" %s %f %{milli}d %{kilo}m %c%%"|access.format = "%{x-real-ip}o %s %{request_method}e %{request_uri}e duration=%{milliseconds}d mem=%{megabytes}m cpu=%c%%"|g' \
        -e 's|^;access.suppress_path[] = /health_check.php|access.suppress_path[] = /status|g' \
        -e 's|^;slowlog = log/$pool.log.slow|slowlog = /dev/stderr|g' \
        -e 's|^;request_slowlog_timeout = 0|request_slowlog_timeout = 3s|g' \
        -e 's|^;chdir = /var/www|chdir = web|g' \
        "$POOL_CONF" && \
    echo "[PHP-FPM.conf] successfully swap environment variables" 
else
    echo "[PHP-FPM.conf] www.conf does not exist. Skip to modify" 
fi

# 4. DEV_ON Logic (OpCache and Error Logging)
DEV_ON="${DEV_ON:-false}"
PHP_CONF_DIR="/usr/local/etc/php/conf.d"
OPCACHE_CONF="${PHP_CONF_DIR}/opcache-recommended.ini"
ERROR_CONF="${PHP_CONF_DIR}/error-logging.ini"

if [ "$DEV_ON" = "true" ]; then
    echo "Development mode is ON. Applying development settings via sed..."
    
    if [ -f "$OPCACHE_CONF" ]; then
        sed -i \
            -e "s/opcache.validate_timestamps=false/opcache.validate_timestamps=true/g" \
            "$OPCACHE_CONF"
        # Ensure revalidate_freq is set (might not exist in the file)
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
    # Production settings are baked into the image, but we can ensure they are enforced if needed
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

if [ "$1" = 'php-fpm' ]; then
	HEALTH_SERVER='/usr/local/bin/health-server'
	CHECK_SCRIPT='/usr/local/bin/php-fpm-healthcheck'
	if [ -x "$HEALTH_SERVER" -a -x "$CHECK_SCRIPT" ]; then
		health-server > /proc/1/fd/1 2> /proc/1/fd/2 &
	else
		echo 'health-server or php-fpm-healthcheck does not exist. liveness probe is unavaliable.'
	fi
fi

# Execute the main command 
echo "Starting application..."
exec docker-php-entrypoint "$@"
