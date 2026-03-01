#!/bin/sh
set -e

# Create log directory
mkdir -p /var/log/scanarr

# Wait for database to be ready — parse DATABASE_URL to extract credentials
# DATABASE_URL format: postgresql://user:password@host:port/dbname
echo "Waiting for database..."
_DB_URL="${DATABASE_URL:-postgresql://scanarr:scanarr_secret@db:5432/scanarr}"
_DB_USER=$(echo "$_DB_URL" | sed 's|.*://\([^:]*\):.*|\1|')
_DB_PASS=$(echo "$_DB_URL" | sed 's|.*://[^:]*:\([^@]*\)@.*|\1|')
_DB_HOST=$(echo "$_DB_URL" | sed 's|.*@\([^:/]*\).*|\1|')
_DB_PORT=$(echo "$_DB_URL" | sed 's|.*@[^:]*:\([0-9]*\)/.*|\1|')
_DB_NAME=$(echo "$_DB_URL" | sed 's|.*/\([^?]*\).*|\1|')

until php -r "
    \$dsn = 'pgsql:host=${_DB_HOST};port=${_DB_PORT};dbname=${_DB_NAME}';
    try { new PDO(\$dsn, '${_DB_USER}', '${_DB_PASS}'); echo 'ok'; }
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
