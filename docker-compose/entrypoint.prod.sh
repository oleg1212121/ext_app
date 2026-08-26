#!/bin/sh
set -e

# First-boot production bootstrap for the ext_app Laravel image.
# Idempotent: safe on every container start (not just the first).
# Runs as www-data. `migrate --force` skips the prompt and runs against the
# connection pinned by .env.production.

cd /var/www

# Refresh the nginx-shared volume with the image's baked assets (handles
# image rebuilds without `docker compose down -v`). --delete keeps the
# volume an exact mirror of the canonical public/; storage:link re-adds the
# storage symlink below.
rsync -a --delete /opt/app-assets/public/ /var/www/public/

# Frontend asset symlink (public/storage -> ../storage/app/public).
php artisan storage:link

# Framework caches — must run before serving traffic.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize

# Migrate (non-destructive: only applies pending migrations).
php artisan migrate --force

# Hand off to the image CMD (php-fpm).
exec "$@"
