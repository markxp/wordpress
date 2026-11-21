# syntax=docker/dockerfile:1

# Companion Cloud Run `google-24-full` PHP 8.5 runtime image.
# Core WordPress extensions (`bcmath`, `exif`, `gd`, `intl`, `mysqli`, `zip`, `opcache`), `curl`, `unzip`, `gs`,
# and dynamic `.so` modules (`imagick.so`, `protobuf.so`, `grpc.so`, `opentelemetry.so`) are prebuilt in the runtime.
FROM us-central1-docker.pkg.dev/serverless-runtimes/google-24-full/runtimes/php85 AS php-fpm

# Switch from default CNB `www-data` user to `root` so APT packages can be installed and PHP-FPM master can drop privileges cleanly.
USER root

# Retain downloaded .deb archives inside BuildKit cache mounts without bloating image layers.
RUN rm -f /etc/apt/apt.conf.d/docker-clean; \
    echo 'Binary::apt::APT::Keep-Downloaded-Packages "true";' > /etc/apt/apt.conf.d/keep-cache

# Install `zip` for Composer archive extraction and WordPress package handling.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    set -eu; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        zip; \
    mkdir -p /etc/php/conf.d /etc/php-fpm.d /run/php /var/www/html; \
    chown www-data:www-data /run/php; \
    chown root:root /var/www/html && chmod 755 /var/www/html; \
    chmod 644 /etc/php.ini; \
    # Enable ImageMagick PDF read/write across ImageMagick 6/7 policy layouts
    for f in /etc/ImageMagick-*/policy.xml; do \
        if [ -f "$f" ]; then \
            sed -i \
                -e 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/g' \
                -e 's/pattern="{GIF,JPEG,PNG,WEBP}"/pattern="{GIF,JPEG,PNG,WEBP,PDF}"/g' \
                "$f"; \
        fi; \
    done

# Enable precompiled dynamic extensions, OPCache settings, error logging, and foreground PHP-FPM pool config.
RUN <<'EOT'
set -eu

cat > /etc/php/conf.d/00-extensions.ini <<'EOF'
extension=imagick.so
extension=protobuf.so
extension=grpc.so
extension=opentelemetry.so
EOF

cat > /etc/php/conf.d/opcache-recommended.ini <<'EOF'
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=false
opcache.jit=tracing
opcache.jit_buffer_size=64M
EOF

cat > /etc/php/conf.d/error-logging.ini <<'EOF'
error_reporting = E_ALL
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /dev/stderr
log_errors_max_len = 1024
ignore_repeated_errors = On
ignore_repeated_source = Off
html_errors = Off
expose_php = Off
EOF

cat > /etc/php-fpm.conf <<'EOF'
[global]
error_log = /proc/self/fd/2
log_limit = 8192
daemonize = no
include = /etc/php-fpm.d/*.conf
EOF

cp /etc/php-fpm.d/www.conf.default /etc/php-fpm.d/www.conf
sed -i \
    -e 's/^user = .*/user = www-data/g' \
    -e 's/^group = .*/group = www-data/g' \
    -e 's/^listen = .*/listen = 9000/g' \
    /etc/php-fpm.d/www.conf

cat > /etc/php-fpm.d/zz-docker.conf <<'EOF'
[www]
listen = 9000
access.log = /proc/self/fd/2
catch_workers_output = yes
decorate_workers_output = no
EOF
EOT

# Point PHP's scan dir to /etc/php/conf.d (instead of default /srv -> /workspace) and configure entrypoint paths
ENV PHP_INI_DIR=/etc \
    PHP_INI_SCAN_DIR=/etc/php/conf.d \
    PHP_CONF_DIR=/etc/php/conf.d \
    PHP_FPM_POOL_CONF=/etc/php-fpm.d/www.conf

# Runtime PHP & PHP-FPM tunables swapped by docker-entrypoint.sh at container startup
ENV MEM_LIMIT=128M \
    POST_MAX_SIZE=8M \
    UPLOAD_MAX_FILE_SIZE=64M \
    MAX_INPUT_TIME=30 \
    MAX_EXECUTION_TIME=30 \
    MAX_FILE_UPLOADS=20 \
    PM=static \
    PM_MAX_CHILDREN=15 \
    REQUEST_TERMINATE_TIMEOUT=35s \
    PM_STATUS_LISTEN=127.0.0.1:9001

COPY --chmod=755 deployment/php-fpm-ping.php /usr/local/bin/php-fpm-ping
COPY --chmod=755 deployment/php-fpm.docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]


FROM php-fpm AS builder
# Install build-only VCS tool not needed in final runtime image
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    export DEBIAN_FRONTEND=noninteractive && \
    apt-get update && \
    apt-get install -y --no-install-recommends git

WORKDIR /var/www/html

RUN find . -mindepth 1 -delete && \
    mkdir -p "dotfiles" && \
    touch "dotfiles/latest" && \
    ln -s "dotfiles/latest" "./.env"

COPY --from=mirror.gcr.io/composer/composer:latest /usr/bin/composer /usr/bin/composer

# Install dependencies before copying the rest of the application to take advantage of layer caching.
COPY composer.json composer.lock ./
COPY custom-plugins ./custom-plugins
RUN --mount=type=cache,target=/root/.composer/cache \
    COMPOSER_MIRROR_PATH_REPOS=1 composer install --no-dev --no-interaction --classmap-authoritative

# Copy the application source code and dereference custom-plugin path repository symlinks
COPY . .
RUN set -eu; \
    for link in web/app/plugins/*; do \
        if [ -L "$link" ]; then \
            target="$(readlink -f "$link")"; \
            rm "$link"; \
            cp -a "$target" "$link"; \
        fi; \
    done; \
    rm -rf custom-plugins

# Final production stage
FROM php-fpm AS app
WORKDIR /var/www/html

# Copy the built application from the builder stage so Composer and git are excluded from the final image.
COPY --from=builder /var/www/html /var/www/html
RUN chown root:root /var/www/html && chmod 755 /var/www/html

CMD ["php-fpm"]
