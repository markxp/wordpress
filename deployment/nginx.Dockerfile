FROM mirror.gcr.io/library/php:fpm as compiler
WORKDIR /var/www/html
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && \
    apt-get install -y --no-install-recommends git zip unzip && \
    rm -rf /var/lib/apt/lists/*
RUN find . -mindepth 1 -delete && \
    mkdir -p "dotfiles" && \
    touch "dotfiles/latest" && \
    ln -s "dotfiles/latest" "./.env"
COPY --from=mirror.gcr.io/composer/composer:latest /usr/bin/composer /usr/bin/composer
# install dependencies before copying the whole app.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-interaction --apcu-autoloader --ignore-platform-reqs
# copy our app
COPY . .

# truncate all PHP files to 0 bytes for security and image size optimization
RUN find . -type f -name "*.php" -exec truncate -s 0 {} +


FROM mirror.gcr.io/nginxinc/nginx-unprivileged:otel

# Default environment variables for Nginx and OpenTelemetry
ENV NGINX_PORT=8080 \
    NGINX_HEALTH_PORT=8081 \
    NGINX_BACKEND_HOST=127.0.0.1:9000 \
    NGINX_ENABLE_OTEL=true \
    NGINX_OTEL_ENDPOINT=127.0.0.1:4317 \
    NGINX_OTEL_SERVICE_NAME=wordpress

# ----- issue: dynamically listen to environment "PORT" ------
#
# solution: reuse /docker-entrypoint.d/20-envsubst-on-templates.sh
# add `14-listen-on-ipv6-by-default-template.sh`
# ----- issue: dynamically listen to environment "PORT" ------
ARG UID=101
ARG GID=101

USER root

RUN mkdir -p /etc/nginx/templates && \
    chown -R $UID:root /etc/nginx/templates

# Copy templates
COPY --chown=root:$GID --chmod=640 deployment/nginx.conf.template /etc/nginx/templates/nginx.conf.template
COPY --chown=root:$GID --chmod=640 deployment/nginx.default.conf.template /etc/nginx/templates/default.conf.template

# Copy entrypoint scripts
COPY --chown=root:$GID --chmod=755 deployment/19-setup-otel-vars.envsh /docker-entrypoint.d/19-setup-otel-vars.envsh
COPY --chown=root:$GID --chmod=755 deployment/21-move-nginx-conf.sh /docker-entrypoint.d/21-move-nginx-conf.sh

# update mime.types: use canonical header name on MDN.
# this is a temporary patch before we maintain our own nginx image.
COPY --chown=root:$GID --chmod=640 deployment/nginx.mime.types /etc/nginx/mime.types

USER $UID
WORKDIR /var/www/html
RUN find . -mindepth 1 -delete
# Note: In Podman/Docker, COPY --from source paths are absolute from the stage root.
# See: https://github.com/containers/podman/issues/14358
COPY --from=compiler --chown=root:$GID --chmod=750 /var/www/html .


CMD ["nginx", "-g", "daemon off;"]