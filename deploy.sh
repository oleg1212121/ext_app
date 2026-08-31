#!/bin/bash
# Deploy master to the running (bind-mounted) containers — no image rebuild,
# no container recreation: git pull, composer install, npm ci + build,
# cache clear/rebuild, migrate, idempotent seeds, queue restart.
#
# Container-recreation guard: ./deploy.sh --stamp (run once after every
# manual rebuild/recreate) records the sha256 of the container-definition
# files in .container-stamp (gitignored). A deploy refuses to run — before
# pulling, so the live bind-mounted code stays at the last-good release —
# when incoming master or the working tree no longer matches the stamp.
# Manual fix when it refuses:
#   docker compose -f docker-compose.yml -f docker-compose.prod.yml build app      # LaravelDockerfile
#   docker compose -f docker-compose.yml -f docker-compose.prod.yml build python   # python Dockerfile / requirements.txt
#   docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile cloudflare up -d
#   ./deploy.sh --stamp
#   ./deploy.sh   # or re-run the Deploy workflow
set -euo pipefail
cd "$(dirname "$0")"

# Files that define the prod containers and images — exactly what
# `docker compose -f docker-compose.yml -f docker-compose.prod.yml` consumes.
# (docker-compose.override.yml is skipped by the explicit -f; gitignored .env
# files never change via git.)
CONTAINER_DEF_FILES=(
    docker-compose.yml
    docker-compose.prod.yml
    LaravelDockerfile
    docker-compose/python/Dockerfile
    docker-compose/python/requirements.txt
)
STAMP_FILE=.container-stamp

# Serialize deploys: a manual SSH run and the CI runner must never interleave.
exec 9>.deploy.lock
flock -n 9 || { echo "Refusing to start: another deploy is already running." >&2; exit 1; }

ref_hashes() { # def-file hashes at a git ref (missing file hashes as empty)
    local ref=$1 file
    for file in "${CONTAINER_DEF_FILES[@]}"; do
        printf '%s  %s\n' "$(git show "$ref:$file" 2>/dev/null | sha256sum | cut -d' ' -f1)" "$file"
    done
}

worktree_hashes() { # def-file hashes in the working tree (missing → empty)
    local file h
    for file in "${CONTAINER_DEF_FILES[@]}"; do
        if [ -f "$file" ]; then h=$(sha256sum <"$file" | cut -d' ' -f1); else h=$(sha256sum </dev/null | cut -d' ' -f1); fi
        printf '%s  %s\n' "$h" "$file"
    done
}

recreate_help() { # printed after a stamp-guard refusal
    echo "Recreate containers manually, then re-deploy:" >&2
    echo "  docker compose -f docker-compose.yml -f docker-compose.prod.yml build app      # LaravelDockerfile changed" >&2
    echo "  docker compose -f docker-compose.yml -f docker-compose.prod.yml build python   # python Dockerfile / requirements.txt changed" >&2
    echo "  docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile cloudflare up -d" >&2
    echo "  ./deploy.sh --stamp" >&2
    echo "  ./deploy.sh   # or re-run the Deploy workflow" >&2
}

case "${1:-}" in
--stamp)
    # Run AFTER manually (re)creating containers: records that the running
    # containers match the current state of the def files.
    worktree_hashes >"$STAMP_FILE"
    echo "Stamped: containers match the current container definitions."
    exit 0
    ;;
"") ;;
*)
    echo "Usage: $0 [--stamp]" >&2
    exit 2
    ;;
esac

branch=$(git rev-parse --abbrev-ref HEAD)
if [ "$branch" != "master" ]; then
    echo "Refusing to deploy from branch '$branch' — checkout master first." >&2
    exit 1
fi

git fetch origin master

if [ ! -f "$STAMP_FILE" ]; then
    echo "Refusing to deploy: $STAMP_FILE not found (containers never stamped on this machine)." >&2
    echo "Verify the stack is current (docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile cloudflare ps)," >&2
    echo "then bootstrap once with: ./deploy.sh --stamp" >&2
    exit 1
fi

if [ "$(ref_hashes origin/master)" != "$(cat "$STAMP_FILE")" ]; then
    echo "Refusing to deploy: incoming master changes container definitions." >&2
    echo "  (< = running containers, > = incoming master)" >&2
    diff "$STAMP_FILE" <(ref_hashes origin/master) | grep '^[<>]' | sed 's/^/  /' >&2 || true
    echo >&2
    recreate_help
    exit 1
fi

git pull --ff-only origin master

if [ "$(worktree_hashes)" != "$(cat "$STAMP_FILE")" ]; then
    echo "Refusing to deploy: working tree no longer matches $STAMP_FILE" >&2
    echo "(hand-edited container definitions?). Restore the files, or recreate + --stamp." >&2
    echo >&2
    recreate_help
    exit 1
fi

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
