FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    libzip-dev \
    nginx \
    supervisor \
    dcron \
    && docker-php-ext-install \
    pdo_pgsql \
    intl \
    zip \
    opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

COPY . .
RUN composer run-script post-install-cmd --no-interaction 2>/dev/null || true

# Force production environment — overrides APP_ENV=dev from .env
# Runtime env vars (Portainer/Docker Compose) can still override this
ENV APP_ENV=prod \
    APP_DEBUG=0

# Generate JWT keys
ARG JWT_PASSPHRASE=scanarr_jwt
RUN mkdir -p config/jwt && \
    openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:${JWT_PASSPHRASE} && \
    openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:${JWT_PASSPHRASE} && \
    chmod 644 config/jwt/private.pem config/jwt/public.pem

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
