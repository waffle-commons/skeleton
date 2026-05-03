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

ENV APP_ENV=prod

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

# In production, run FrankenPHP directly
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]
