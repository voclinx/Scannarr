# syntax=docker/dockerfile:1

# ============================================================
# Stage 1 — Install PHP extensions (cached unless base image changes)
# ============================================================
FROM php:8.3-fpm-alpine AS php-base

# install-php-extensions uses pre-built binaries instead of compiling from source
# This alone saves ~15-20 minutes vs docker-php-ext-install for intl (ICU)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl zip opcache

# ============================================================
# Stage 2 — Install Composer dependencies (cached unless composer.json/lock change)
# ============================================================
FROM php-base AS composer-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./

# BuildKit cache mount keeps Composer download cache between builds
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# ============================================================
# Stage 3 — Final production image
# ============================================================
FROM php-base AS production

RUN apk add --no-cache \
    nginx \
    supervisor \
    dcron \
    openssl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy vendor from composer-deps stage
COPY --from=composer-deps /app/vendor ./vendor
COPY --from=composer-deps /app/composer.json ./composer.json
COPY --from=composer-deps /app/composer.lock ./composer.lock

# Copy application source
COPY . .

# Run post-install scripts (cache:clear, assets:install)
ENV APP_ENV=prod \
    APP_DEBUG=0
RUN composer run-script post-install-cmd --no-interaction 2>/dev/null || true

# Nginx config
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Crontab
COPY docker/crontab /etc/crontabs/root

# Supervisord config
COPY docker/supervisord.conf /etc/supervisord.conf

# Entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Ensure var directory exists with correct permissions
RUN mkdir -p var/cache var/log && \
    chown -R www-data:www-data var

EXPOSE 8080 8081

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
