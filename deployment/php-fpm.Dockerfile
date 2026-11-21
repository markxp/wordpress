# syntax=docker/dockerfile:1

# Primary PHP-FPM runtime: Debian Trixie Slim + precompiled packages.sury.org PHP 8.5 APT packages.
# Eliminates all C/C++ PECL compilation (`install-php-extensions`) and legacy CLI `update-alternatives` manpage warnings.
FROM mirror.gcr.io/library/debian:trixie-slim AS php-fpm

# Retain downloaded .deb archives inside BuildKit cache mounts without bloating image layers.
RUN rm -f /etc/apt/apt.conf.d/docker-clean; \
    echo 'Binary::apt::APT::Keep-Downloaded-Packages "true";' > /etc/apt/apt.conf.d/keep-cache

# Install precompiled PHP 8.5 FPM, core WordPress extensions, Imagick, OpenTelemetry/gRPC/Protobuf, APCu, and runtime tools.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    set -eu; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl; \
    curl -fsSL https://packages.sury.org/debsuryorg-archive-keyring.deb -o /tmp/sury.deb; \
    dpkg -i /tmp/sury.deb; \
    rm -f /tmp/sury.deb; \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ trixie main" > /etc/apt/sources.list.d/php.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        php8.5-fpm \
        php8.5-cli \
        php8.5-common \
        php8.5-bcmath \
        php8.5-curl \
        php8.5-gd \
        php8.5-intl \
        php8.5-mbstring \
        php8.5-mysql \
        php8.5-xml \
        php8.5-zip \
        php8.5-imagick \
        php8.5-igbinary \
        php8.5-protobuf \
        php8.5-opentelemetry \
        php8.5-grpc \
        php8.5-apcu \
        ghostscript \
        zip \
        unzip \
        tzdata; \
    # Symlink php-fpm8.5 -> /usr/local/sbin/php-fpm so `php-fpm` works identically across base images
    ln -sf /usr/sbin/php-fpm8.5 /usr/local/sbin/php-fpm; \
    mkdir -p /run/php /var/www/html; \
    chown www-data:www-data /run/php; \
    chown root:root /var/www/html && chmod 755 /var/www/html; \
    # Enable ImageMagick PDF read/write across ImageMagick 6/7 policy layouts
    for f in /etc/ImageMagick-*/policy.xml; do \
        if [ -f "$f" ]; then \
            sed -i \
                -e 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/g' \
                -e 's/pattern="{GIF,JPEG,PNG,WEBP}"/pattern="{GIF,JPEG,PNG,WEBP,PDF}"/g' \
                "$f"; \
        fi; \
    done

# Configure foreground container-native PHP-FPM global & pool defaults (matching official docker-library/php:fpm behavior),
# recommended OPCache settings, and production error logging across both FPM and CLI SAPIs.
RUN <<'EOT'
set -eu

cat > /etc/php/8.5/fpm/php-fpm.conf <<'EOF'
[global]
error_log = /proc/self/fd/2
log_limit = 8192
daemonize = no
include = /etc/php/8.5/fpm/pool.d/*.conf
EOF

sed -i 's|^listen = .*|listen = 9000|g' /etc/php/8.5/fpm/pool.d/www.conf

cat > /etc/php/8.5/fpm/pool.d/zz-docker.conf <<'EOF'
[www]
listen = 9000
access.log = /proc/self/fd/2
catch_workers_output = yes
decorate_workers_output = no
EOF

for conf_dir in /etc/php/8.5/fpm/conf.d /etc/php/8.5/cli/conf.d; do
    cat > "${conf_dir}/opcache-recommended.ini" <<'EOF'
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=false
opcache.jit=tracing
opcache.jit_buffer_size=64M
EOF

    cat > "${conf_dir}/error-logging.ini" <<'EOF'
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
done
EOT

# Configure paths consumed by php-fpm.docker-entrypoint.sh
ENV PHP_INI_DIR=/etc/php/8.5/fpm \
    PHP_CONF_DIR=/etc/php/8.5/fpm/conf.d \
    PHP_FPM_POOL_CONF=/etc/php/8.5/fpm/pool.d/www.conf

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
