#!/bin/sh
# Daily logical backup of the ext_app database (run by the `backups` sidecar
# via crond). 14-day retention. gzip-compressed.
#
# Restore (run against the prod DB, never the test connection):
#   gunzip -c /backups/ext_app-YYYYMMDD-HHMMSS.sql.gz \
#     | docker exec -i ext_pgdb psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"
# (Drop/recreate the DB first if you want a clean restore:
#   docker exec ext_pgdb dropdb -U "$POSTGRES_USER" "$POSTGRES_DB"
#   docker exec ext_pgdb createdb -U "$POSTGRES_USER" "$POSTGRES_DB")
set -eu

: "${POSTGRES_USER:?POSTGRES_USER missing in env}"
: "${POSTGRES_DB:?POSTGRES_DB missing in env}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD missing in env}"

export PGPASSWORD="$POSTGRES_PASSWORD"

TS=$(date -u +%Y%m%d-%H%M%S)
OUT="/backups/ext_app-${TS}.sql.gz"

echo "[$(date -u -Iseconds)] dumping -> ${OUT}"
pg_dump -h db -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --no-privileges | gzip > "$OUT"

# 14-day retention.
find /backups -name 'ext_app-*.sql.gz' -type f -mtime +14 -print -delete
