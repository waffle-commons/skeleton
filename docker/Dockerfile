# syntax=docker/dockerfile:1

#
# Waffle Framework - Multi-Stage Dockerfile
# Supports both Development (with Xdebug) and Production (Optimized).
#
ARG FRANKEN_VERSION='1.12.2'
ARG PHP_VERSION='8'
ARG OS='trixie'

# -----------------------------------------------------------------------------
# Stage 1: Base (Shared Runtime)
# -----------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKEN_VERSION}-php${PHP_VERSION}-${OS} AS base

# Environment Standardization
# Optimization for Waffle (Worker Mode)
ENV APP_ENV=prod \
    SERVER_NAME=":80" \
    FRANKENPHP_CONFIG="worker ./public/index.php"

# Install system dependencies and common PHP extensions
# libyaml-dev is required for Waffle's native yaml extension
RUN apt-get update && apt-get install -y --no-install-recommends \
    acl \
    file \
    gettext \
    git \
    libyaml-dev \
    zip \
    unzip \
    && install-php-extensions \
        apcu \
        gd \
        intl \
        opcache \
        zip \
        yaml \
    && echo "apc.enable_cli=1" >> "$PHP_INI_DIR/conf.d/docker-php-ext-apcu.ini" \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Global Caddy Configuration
COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
# Global PHP Configuration (app.ini)
COPY docker/frankenphp/conf.d/app.ini $PHP_INI_DIR/conf.d/

WORKDIR /app

# -----------------------------------------------------------------------------
# Stage 2: Development (Dev Tools & Xdebug)
# -----------------------------------------------------------------------------
FROM base AS dev

ARG USER=waffle
ARG UID=1000

ENV APP_ENV=dev \
    XDEBUG_MODE=debug,coverage

# Install Xdebug only in Dev
RUN install-php-extensions xdebug

# Xdebug Configuration (Optimized for Docker)
RUN echo "xdebug.start_with_request=yes" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_port=9003" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.log=/tmp/xdebug.log" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create a non-root user for development (DX Best Practice)
# This aligns permissions with the host Linux user
RUN useradd -m -u ${UID} ${USER} || true && \
    mkdir -p /home/${USER}/.composer && \
    chown -R ${USER}:${USER} /home/${USER}

# We do NOT copy code here in dev, as we use bind mounts in docker-compose

CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]

# -----------------------------------------------------------------------------
# Stage 3: Builder (Dependency Installation for Prod)
# -----------------------------------------------------------------------------
FROM base AS builder

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy only manifests to optimize Docker cache
COPY composer.json composer.lock ./

# Install PROD vendors (no dev, optimized)
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-interaction --optimize-autoloader

# Copy the rest of the source code
COPY . .

# Enable Preload ONLY in production and ONLY after code is copied
RUN echo "opcache.validate_timestamps = 1" >> $PHP_INI_DIR/conf.d/app.ini \
    && echo "opcache.preload=/app/config/preload.php" >> $PHP_INI_DIR/conf.d/app.ini

# Autoloader warmup
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# -----------------------------------------------------------------------------
# Stage 4: Production (Minimal & Secure)
# -----------------------------------------------------------------------------
FROM base AS prod

ARG USER=waffle
ARG UID=1000
ARG GID=1000

#   - XDG_CONFIG_HOME / XDG_DATA_HOME : Caddy's writable state (autosave.json,
#     local-CA/cert storage) relocated off the immutable rootfs — same fix as
#     contributions/waffle-serverless/docker/Dockerfile, required now that
#     docker-compose.prod.yml sets `read_only: true` (mount these as tmpfs).
ENV APP_ENV=prod \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

# Use production PHP configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy prepared files from Builder
# Explicitly copy directories to avoid copying unwanted files
COPY --from=builder --link /app/vendor /app/vendor
COPY --from=builder --link /app/src /app/src
COPY --from=builder --link /app/config /app/config
COPY --from=builder --link /app/public /app/public

# Enable Preload ONLY in production and ONLY after code is copied
RUN echo "opcache.validate_timestamps = 0" >> $PHP_INI_DIR/conf.d/app.ini \
    && echo "opcache.preload=/app/config/preload.php" >> $PHP_INI_DIR/conf.d/app.ini

# Permission management for var directory (Cache/Logs)
RUN mkdir -p var/cache var/log && \
    setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var && \
    setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var

# Writable Caddy state dirs for the immutable-rootfs runtime (see XDG_* above).
RUN mkdir -p /config /data

# Drop root (Beta6 audit FIX-01 #13): same USER/UID convention as the "dev"
# stage above, dedicated group + nologin shell to match the hardened pattern
# already shipped in contributions/waffle-serverless/docker/Dockerfile. Created
# last, after all root-only steps (php.ini swap, COPY, opcache config, setfacl),
# then chown the app tree AND /config, /data — self-sufficient on ownership
# regardless of the runtime's volume setup (docker-compose.prod.yml also
# remounts /config and /data as 1777 tmpfs, but the image must not depend on
# that specific compose file to be writable: a plain `docker run`, or a
# Kubernetes emptyDir without a matching fsGroup, would otherwise leave them
# root-owned and unwritable by ${USER}).
# NOTE: the upstream dunglas/frankenphp base image already grants
# CAP_NET_BIND_SERVICE to the frankenphp binary via setcap (verified:
# `getcap /usr/local/bin/frankenphp` -> cap_net_bind_service=ep), and that
# capability is inherited through `FROM base` (no cross-stage COPY of the
# binary, which would strip it) — so this non-root user can still bind :80/:443
# without any change to the port-binding approach.
RUN groupadd -g ${GID} ${USER} \
    && useradd -u ${UID} -g ${USER} -d /app -s /usr/sbin/nologin ${USER} \
    && chown -R ${USER}:${USER} /app /config /data
USER ${USER}

# In production, run FrankenPHP directly
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]
