#!/bin/bash
# Native (no-Docker) deploy: ship origin/master to the running Ubuntu services.
# git pull, python venv sync (restart ext-python + /health gate when its code
# or deps changed), composer install, npm ci + build, cache clear/rebuild,
# additive migrate, idempotent seeds, restart queue + scheduler units.
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

before=$(git rev-parse HEAD)
git fetch origin master
git pull --ff-only origin master

# The python venv is git-ignored (machine-local), so sync it from
# requirements.txt on every deploy: pip is a no-op when satisfied and
# self-heals a venv that drifted from the repo.
docker-compose/python/ai/ai_env/bin/pip install --no-input \
    -r docker-compose/python/requirements.txt

# ext-python serves ai/ from the checkout without --reload: a pull that
# touched docker-compose/python/ or the unit file needs a restart to take
# effect. Gate the restart on this pull's diff so unrelated deploys don't
# drop in-flight python requests.
if [ -n "$(git diff --name-only "$before" HEAD -- docker-compose/python ext-python.service)" ]; then
    sudo systemctl restart ext-python
    # /health answers 200 only once the app import graph resolved, so a
    # missing/broken requirement fails the deploy here, not in users'
    # requests ("status": "loading" is also a 200 — imports are fine).
    ok=""
    for _ in $(seq 1 30); do
        if curl -fsS --max-time 5 -o /dev/null http://127.0.0.1:8000/health; then ok=1; break; fi
        sleep 2
    done
    if [ -z "$ok" ]; then
        sudo journalctl -u ext-python -n 30 --no-pager >&2 || true
        echo "ext-python failed /health within 60s of restart" >&2
        exit 1
    fi
fi

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
php artisan db:seed --class=UiStringSeeder --force

# Recycle queue workers + scheduler onto the new code (workers finish current job).
# daemon-reload first: a unit-file change shipped by git pull only reaches
# systemctl restart after the manager reloads its unit definitions.
sudo systemctl daemon-reload
sudo systemctl restart ext-queue@1 ext-queue@2 ext-scheduler

echo "Deployed $(git rev-parse --short HEAD)"