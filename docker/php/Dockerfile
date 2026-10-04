# syntax=docker/dockerfile:1
# Development image for the Symfony backend (FrankenPHP + Caddy).
FROM dunglas/frankenphp:1-php8.5

ARG UID=1000
ARG GID=1000

# Composer 2 (latest) from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# PHP extensions. Xdebug is installed but disabled (XDEBUG_MODE=off) until you opt in.
RUN install-php-extensions \
        pdo_pgsql \
        intl \
        opcache \
        zip \
        xdebug \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/php/Caddyfile /etc/frankenphp/Caddyfile

# Run as a non-root user whose UID/GID match the host user, so files written
# through the ./backend bind mount (vendor/, var/, generated code) stay owned by you.
RUN groupadd --gid "${GID}" app \
    && useradd --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash app \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data/caddy /config/caddy /app

ENV XDEBUG_MODE=off \
    COMPOSER_HOME=/home/app/.composer \
    SERVER_NAME=:80

USER app
WORKDIR /app
