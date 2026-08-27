#!/bin/sh
set -e

# First-boot production bootstrap for the ext_app Laravel image.
# Idempotent: safe on every container start (not just the first).
# Runs as www-data. `migrate --force` skips the prompt and runs against the
# connection pinned by .env.production.

cd /var/www

# Recreate the storage skeleton. Named volumes used to copy this from the image
# on first mount; host bind mounts shadow the image dir instead, so we must
# ensure the directories exist ourselves (runs as www-data).
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
         storage/app/public storage/logs storage/debugbar bootstrap/cache

# Refresh the host bind-mounted public dir with the image's baked assets (handles
# image rebuilds without `docker compose down -v`). --delete keeps the
# bind mount an exact mirror of the canonical public/; storage:link re-adds the
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

# Seed idempotent reference data required by runtime jobs/features.
# SentenceSplitter throws "No sentence types found" if this is empty, which
# caused SplitEntityFileSentences jobs to fail in a retry loop in prod.
php artisan db:seed --class=SentenceTypeSeeder --force

# Seed AI providers (updateOrCreate: idempotent). Runtime features that call
# AI providers fail if this table is empty, so ensure rows exist on every boot.
php artisan db:seed --class=AiProviderSeeder --force

# Seed languages (upsert: idempotent). The picker, reader, and crossword pages
# 404 or break when the languages table is empty, so ensure rows exist on boot.
php artisan db:seed --class=LanguageSeeder --force

# Hand off to the image CMD (php-fpm).
exec "$@"
