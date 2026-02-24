#!/bin/sh
set -e

# Create log directory
mkdir -p /var/log/scanarr

# Wait for database to be ready using pg_isready-style check
echo "Waiting for database..."
until php -r "
    \$dsn = 'pgsql:host=db;port=5432;dbname=scanarr';
    try { new PDO(\$dsn, 'scanarr', getenv('POSTGRES_PASSWORD') ?: 'scanarr_secret'); echo 'ok'; }
    catch (Exception \$e) { exit(1); }
" 2>/dev/null; do
    sleep 2
done
echo "Database is ready."

# Install dependencies in dev mode (when volume is mounted)
if [ "$APP_ENV" = "dev" ] && [ -f composer.json ]; then
    echo "Installing composer dependencies (dev)..."
    composer install --no-interaction
fi

# Run migrations (only if doctrine is installed)
if php bin/console list 2>/dev/null | grep -q "doctrine:migrations:migrate"; then
    echo "Running migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

# Clear and warm up cache
php bin/console cache:clear --no-warmup
php bin/console cache:warmup

# Fix permissions
chown -R www-data:www-data var

exec "$@"
