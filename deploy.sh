#!/bin/bash
# Deploy master to the running (bind-mounted) containers — no image rebuild,
# no container recreation: git pull, composer install, npm ci + build,
# cache clear/rebuild, migrate, idempotent seeds, queue restart.
# Images are rebuilt manually only when a Dockerfile changes:
#   docker compose build app      # LaravelDockerfile
#   docker compose build python   # docker-compose/python/Dockerfile
set -euo pipefail
cd "$(dirname "$0")"

branch=$(git rev-parse --abbrev-ref HEAD)
if [ "$branch" != "master" ]; then
    echo "Refusing to deploy from branch '$branch' — checkout master first." >&2
    exit 1
fi

git fetch origin master
git pull --ff-only origin master

docker exec ext_app_laravel composer install --prefer-dist --no-interaction --optimize-autoloader
docker exec ext_app_laravel npm ci --no-audit --no-fund
docker exec ext_app_laravel npm run build
docker exec ext_app_laravel php artisan storage:link

docker exec ext_app_laravel php artisan optimize:clear
docker exec ext_app_laravel php artisan config:cache
docker exec ext_app_laravel php artisan route:cache
docker exec ext_app_laravel php artisan view:cache

docker exec ext_app_laravel php artisan migrate --force
docker exec ext_app_laravel php artisan db:seed --class=SentenceTypeSeeder --force
docker exec ext_app_laravel php artisan db:seed --class=AiProviderSeeder --force
docker exec ext_app_laravel php artisan db:seed --class=LanguageSeeder --force

docker exec ext_app_laravel php artisan queue:restart

echo "Deployed $(git rev-parse --short HEAD)"
