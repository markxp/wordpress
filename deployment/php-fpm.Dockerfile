# syntax=docker/dockerfile:1

# require a health-server binary for COPY command.
FROM alpine:latest as downloader
RUN apk add --no-cache git curl
WORKDIR /src
RUN git clone --depth=1 --branch main https://github.com/legispect/php-fpm-healthz . && \
    curl -o /php-fpm-healthcheck https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck && \
    curl -sSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o /install-php-extensions && \
    chmod +x /install-php-extensions

FROM scratch as go-src-cache
COPY --from=downloader /src/* ./

FROM golang:latest as go-build
WORKDIR /usr/src/app

COPY --from=go-src-cache /src/go.* ./
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    go mod download
COPY --from=go-src-cache /src/*.go ./
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    go build -o /health-server .

# This Dockerfile is based on Wordpress:fpm
# Changes are applied for system packages, php extenstions, php configurations, and bedrock-flavored WordPress.
# Since bedrock uses `.env` file to read configurations, we do not reserve WordPress's docker image environment variables. 
# and not use their(the *official* WordPress docker image's) docker-entrypoint.
# We use php:fpm's entrypoint, docker-php-entrypoint.

FROM scratch as install-php-extension-cache
COPY --from=downloader /install-php-extensions /

FROM mirror.gcr.io/library/php:8.5-fpm as php-fpm
# Copy mlocati's installer from downloader stage
COPY --from=install-php-extension-cache --chmod=754 /install-php-extensions /usr/local/bin/

# opt out the Ubuntu/Debian official docker images that automatically clean APT cache.
# because we use `--mount=type=cache` between installation steps, and it enables caches between
# image layers without increasing the final image size.
#
# ( another benifit is that we don'y need to clean up apt cache at the end of installations.
# ie. rm -rf /var/lib/apt )
RUN rm -f /etc/apt/apt.conf.d/docker-clean; echo 'Binary::apt::APT::Keep-Downloaded-Packages "true";' > /etc/apt/apt.conf.d/keep-cache

RUN --mount=type=cache,target=/var/cache/apt \
    --mount=type=cache,target=/var/lib/apt \
    set -eu; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
    libfcgi-bin \
    # Ghostscript is required for rendering PDF previews
    ghostscript \
    imagemagick \
    ; \
    # Enable ImageMagick PDF read/write (https://stackoverflow.com/a/52862052)
    sed -i 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/g' /etc/ImageMagick-7/policy.xml

# Set php.ini
# WARNING: DO NOT use 'pear config-set php_ini' here. 
# install-php-extensions handles its own config files, and manual PEAR modification 
# causes "Module already loaded" errors for extensions like apcu.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# install the PHP extensions we need (https://make.wordpress.org/hosting/handbook/handbook/server-environment/#php-extensions)
# We consolidate all extensions into a single layer for production optimization (smaller image size).
# The installation order is carefully chosen to minimize conflicts and follow user preference.

RUN --mount=type=cache,target=/var/cache/apt \
    --mount=type=cache,target=/var/lib/apt \
    set -ex; \
    echo "Installing all PHP Extensions..." && \
    # 1. Core Extensions: Required by WordPress for basic functionality (intl, gd, mysqli, etc.)
    # 2. Imagick: Advanced image processing
    # 3. PECL Performance & Monitoring: timezonedb, igbinary, protobuf, opentelemetry, grpc
    # 4. APCU: User-land caching, installed last for safety.
    install-php-extensions \
    bcmath exif gd intl mysqli zip \
    imagick \
    timezonedb igbinary protobuf opentelemetry grpc \
    apcu

# Phase 4: Runtime Tooling & Final Configuration
# We consolidate tooling and config files into a single layer for production optimization.
RUN --mount=type=cache,target=/var/cache/apt \
    --mount=type=cache,target=/var/lib/apt \
    set -ex; \
    apt-get update && apt-get install -y --no-install-recommends zip unzip curl; \
    # Enable recommended OPCache settings (https://secure.php.net/manual/en/opcache.installation.php)
    docker-php-ext-enable opcache; \
    { \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=32'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=false'; \
    echo 'opcache.jit=tracing'; \
    echo 'opcache.jit_buffer_size=64M'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini; \
    # Set production error logging settings (https://wordpress.org/support/article/editing-wp-config-php/#configure-error-logging)
    { \
    echo 'error_reporting = E_ALL'; \
    echo 'display_errors = Off'; \
    echo 'display_startup_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /dev/stderr'; \
    echo 'log_errors_max_len = 1024'; \
    echo 'ignore_repeated_errors = On'; \
    echo 'ignore_repeated_source = Off'; \
    echo 'html_errors = Off'; \
    echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/error-logging.ini

# modify PHP.ini at instance starting up. 
ENV MEM_LIMIT=128M
ENV POST_MAX_SIZE=8M
ENV UPLOAD_MAX_FILE_SIZE=64M
ENV MAX_INPUT_TIME=30
ENV MAX_EXECUTION_TIME=30
ENV MAX_FILE_UPLOADS=20

# modify php-fpm pool settings at instance starting up
ENV PM=static
ENV PM_MAX_CHILDREN=15
ENV REQUEST_TERMINATE_TIMEOUT=35s
ENV PM_STATUS_LISTEN=9001

# 1. Because we need to change the above `php.ini` values at instance starting up, this entrypoint reads envs,
# 	and change `php.ini` 
# 	Note: The read path of FPM config does not changed.
# 2. Copy php-fpm-healthcheck script to /usr/local/bin.
# 3. Copy the home-made health-server for wrapping health check calls.
# Note: In Podman/Docker, COPY --from source paths are absolute from the stage root.
# See: https://github.com/containers/podman/issues/14358
COPY --chmod=755 --from=downloader /php-fpm-healthcheck /usr/local/bin/php-fpm-healthcheck
COPY --chmod=755 --from=go-build   /health-server       /usr/local/bin/health-server
COPY --chmod=755 deployment/php-fpm.docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh


ENTRYPOINT ["docker-entrypoint.sh"]
# WORKDIR /var/www/html
# EXPOSE 9000
CMD ["php-fpm"]


FROM php-fpm as builder
# Install build tools that are NOT in the runtime base
RUN --mount=type=cache,target=/var/cache/apt\
    --mount=type=cache,target=/var/lib/apt\
    apt-get update && \
    apt-get install -y --no-install-recommends git && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

RUN find . -mindepth 1 -delete && \
    mkdir -p "dotfiles" && \
    touch "dotfiles/latest" && \
    ln -s "dotfiles/latest" "./.env"

COPY --from=mirror.gcr.io/composer/composer:latest /usr/bin/composer /usr/bin/composer

# Install dependencies before copying the rest of the application to take advantage of layer caching.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-interaction --apcu-autoloader

# Copy the application source code
COPY . .

# Final production stage
FROM php-fpm as app
WORKDIR /var/www/html

# Copy the built application from the builder stage
# This ensures composer and other build-time tools are not in the final image.
# Note: In Podman/Docker, COPY --from source paths are absolute from the stage root.
COPY --from=builder /var/www/html /var/www/html

CMD ["php-fpm"]