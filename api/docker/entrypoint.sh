#!/bin/sh
set -e

# Create log directory
mkdir -p /var/log/scanarr

# Wait for database to be ready
echo "Waiting for database..."
until php -r "new PDO('${DATABASE_URL}');" 2>/dev/null; do
    sleep 2
done
echo "Database is ready."

# Run migrations
echo "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Clear and warm up cache
php bin/console cache:clear --no-warmup
php bin/console cache:warmup

# Fix permissions
chown -R www-data:www-data var

exec "$@"
