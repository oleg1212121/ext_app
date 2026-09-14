#!/bin/bash
# Native (no-Docker) deploy: ship origin/master to the running Ubuntu services.
# git pull, composer install, npm ci + build, cache clear/rebuild, additive
# migrate, idempotent seeds, restart queue + scheduler units.
#
# Runs in-place in the checkout on the server. Guarded by a flock so manual
# SSH runs and the GitHub Actions self-hosted runner can never interleave.
set -euo pipefail
cd "$(dirname "$0")"

exec 9>.deploy.lock
flock -n 9 || { echo "Refusing to start: another deploy is already running." >&2; exit 1; }

branch=$(git rev-parse --abbrev-ref HEAD)
if [ "$branch" != "master" ]; then
    echo "Refusing to deploy from branch '$branch' — checkout master first." >&2
    exit 1
fi

git fetch origin master
git pull --ff-only origin master

cd laravel

composer install --prefer-dist --no-interaction --optimize-autoloader
npm ci --no-audit --no-fund
npm run build

php artisan storage:link  # idempotent; keeps public/storage wired to uploads

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force
php artisan db:seed --class=SentenceTypeSeeder --force
php artisan db:seed --class=AiProviderSeeder --force
php artisan db:seed --class=LanguageSeeder --force

# Recycle queue workers + scheduler onto the new code (workers finish current job).
sudo systemctl restart ext-queue@1 ext-queue@2 ext-scheduler

echo "Deployed $(git rev-parse --short HEAD)"