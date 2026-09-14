#!/bin/bash
# Daily logical backup of the ext_app database (native Ubuntu install, no Docker).
# 14-day retention, gzip-compressed.
#
# Restore (against the prod DB; never the test connection):
#   gunzip -c /home/alex/projects/ext_app/docker-compose/prod/backups/ext_app-YYYYMMDD-HHMMSS.sql.gz \
#     | psql -h localhost -U ext_app -d ext_app
# (Drop/recreate first for a clean restore:
#   dropdb -U ext_app ext_app && createdb -U ext_app ext_app)
set -eu

ENV=/home/alex/projects/ext_app/laravel/.env
BACKUP_DIR=/home/alex/projects/ext_app/docker-compose/prod/backups

DB_HOST=127.0.0.1
DB_USERNAME=$(grep '^DB_USERNAME=' "$ENV" | cut -d= -f2)
DB_DATABASE=$(grep '^DB_DATABASE=' "$ENV" | cut -d= -f2)
DB_PASSWORD=$(grep '^DB_PASSWORD=' "$ENV" | cut -d= -f2)

export PGPASSWORD="$DB_PASSWORD"

TS=$(date -u +%Y%m%d-%H%M%S)
OUT="${BACKUP_DIR}/ext_app-${TS}.sql.gz"

echo "[$(date -u -Iseconds)] dumping -> ${OUT}"
pg_dump -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" --no-owner --no-privileges | gzip > "$OUT"

# 14-day retention.
find "$BACKUP_DIR" -name 'ext_app-*.sql.gz' -type f -mtime +14 -print -delete

echo "[$(date -u -Iseconds)] backup complete: ${OUT} ($(du -h "$OUT" | cut -f1))"