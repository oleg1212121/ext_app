#!/usr/bin/env bash
# log-doctor — collect new production errors since the last completed run.
#
# Runs on the HOST (the self-hosted prod runner, or manually on dev). Scans:
#   1. laravel/storage/logs/laravel-<date>.log — ERROR/CRITICAL/ALERT/EMERGENCY
#      entries (prod uses LOG_STACK=daily; timestamps are UTC — config app.php)
#   2. the failed_jobs table (artisan tinker inside ext_app_laravel)
#   3. the scheduler container output (docker logs --since)
#
# Usage:
#   collect.sh                    collect now; prints the run dir on stdout
#   collect.sh --mark-done <dir>  advance the state window after tickets were
#                                 successfully filed (workflow's last step)
#
# State lives in $LOG_DOCTOR_STATE_DIR (default ~/.log-doctor): state.env holds
# last_completed (unix ts), advanced ONLY by --mark-done — so a failed
# analyze/report step reprocesses the same window on the next run.
#
# Findings (if any) land in <run_dir>/findings.md for the log-doctor opencode
# agent; <run_dir>/meta.env carries timestamps for the later steps. Human
# progress goes to stderr; stdout carries ONLY the run dir (machine-readable).
set -euo pipefail

CHECKOUT="${PROD_CHECKOUT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
STATE_DIR="${LOG_DOCTOR_STATE_DIR:-$HOME/.log-doctor}"
STATE_FILE="$STATE_DIR/state.env"
RUNS_DIR="$STATE_DIR/runs"
LOGS_DIR="$CHECKOUT/laravel/storage/logs"
CONTAINER="${LOG_DOCTOR_CONTAINER:-ext_app_laravel}"

MAX_GROUPS=8        # unique error groups analyzed per run (overflow is warned)
MAX_BLOCK_LINES=60  # excerpt lines per group in findings.md
MAX_FAILED_JOBS=10  # failed_jobs rows considered per run

log() { printf '[log-doctor] %s\n' "$*" >&2; }
die() { printf '[log-doctor] ERROR: %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- mark-done --
mark_done() {
    local run_dir=$1
    [ -f "$run_dir/meta.env" ] || die "--mark-done: $run_dir/meta.env not found"
    # shellcheck disable=SC1091
    . "$run_dir/meta.env"
    [ -n "${run_start_unix:-}" ] || die "--mark-done: meta.env lacks run_start_unix"
    mkdir -p "$STATE_DIR"
    printf 'last_completed=%s\n' "$run_start_unix" > "$STATE_FILE.tmp"
    mv "$STATE_FILE.tmp" "$STATE_FILE"
    log "window advanced to $(date -u -d "@$run_start_unix" '+%Y-%m-%dT%H:%M:%SZ')"
}

if [ "${1:-}" = "--mark-done" ]; then
    [ $# -eq 2 ] || die "usage: collect.sh --mark-done <run_dir>"
    mark_done "$2"
    exit 0
fi
[ $# -eq 0 ] || die "usage: collect.sh [--mark-done <run_dir>]"

# --------------------------------------------------------------- collection --
run_start=$(date -u +%s)
run_id=$(date -u -d "@$run_start" +%Y%m%dT%H%M%SZ)
run_dir="$RUNS_DIR/$run_id"
n=1
while [ -e "$run_dir" ]; do # two collects in the same second → suffix
    run_dir="$RUNS_DIR/$run_id-$n"
    n=$((n + 1))
done
mkdir -p "$run_dir"

# Window: since the last COMPLETED run (or the last hour on first run).
since=""
if [ -f "$STATE_FILE" ]; then
    since=$(grep -E '^last_completed=[0-9]+$' "$STATE_FILE" | head -n1 | cut -d= -f2 || true)
fi
if [ -z "$since" ]; then
    since=$((run_start - 3600))
    log "no state file — first run, window = last hour"
fi
since_rfc3339=$(date -u -d "@$since" '+%Y-%m-%dT%H:%M:%SZ')
since_sql=$(date -u -d "@$since" '+%Y-%m-%d %H:%M:%S')
since_str=$since_sql          # log timestamps compare lexicographically (UTC)
since_date=${since_str%% *}

printf 'run_start_unix=%s\nrun_start_rfc3339=%s\nsince_rfc3339=%s\n' \
    "$run_start" \
    "$(date -u -d "@$run_start" '+%Y-%m-%dT%H:%M:%SZ')" \
    "$since_rfc3339" > "$run_dir/meta.env"

log "run $run_id — window since $since_rfc3339"

blocks="$run_dir/blocks.raw"
: > "$blocks"

# --- 1. laravel daily logs ----------------------------------------------------
# Header lines look like: "[2026-08-31 12:34:56] production.ERROR: message"
# (mawk-safe: no {n,m} interval regexes — Ubuntu's default awk lacks them).
scan_log_file() {
    awk -v cutoff="$since_str" '
        function is_header(line,    i, c) {
            if (substr(line, 1, 1) != "[") return 0
            for (i = 2; i <= 20; i++) {
                c = substr(line, i, 1)
                if (i == 6 || i == 9) { if (c != "-") return 0 }
                else if (i == 12) { if (c != " ") return 0 }
                else if (i == 15 || i == 18) { if (c != ":") return 0 }
                else if (c < "0" || c > "9") return 0
            }
            return substr(line, 21, 2) == "] "
        }
        BEGIN { inblk = 0 }
        {
            if (is_header($0)) {
                if (inblk) print "@@END"
                inblk = 0
                if (substr($0, 2, 19) >= cutoff && substr($0, 23) ~ /^[A-Za-z]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/) {
                    inblk = 1
                    print "@@BEGIN laravel.log"
                    print
                    next
                }
            } else if (inblk) {
                print
            }
        }
        END { if (inblk) print "@@END" }
    ' "$1"
}

log_files=0
for f in "$LOGS_DIR"/laravel-*.log; do
    [ -f "$f" ] || continue
    fdate=$(basename "$f"); fdate=${fdate#laravel-}; fdate=${fdate%.log}
    # YYYY-MM-DD filenames compare chronologically as strings.
    if [[ "$fdate" < "$since_date" ]]; then continue; fi
    log_files=$((log_files + 1))
    scan_log_file "$f" >> "$blocks"
done
log "scanned $log_files daily log file(s) at/after $since_date"

# --- 2. failed_jobs (in-container tinker) -------------------------------------
# Emits @@FJBEGIN/@@FJEND records; the @@FJDONE sentinel proves the probe ran.
php_code='
$rows = DB::table("failed_jobs")->where("failed_at", ">", "__SINCE__")->orderBy("failed_at")->limit(__MAX__)->get();
foreach ($rows as $row) {
    $payload = json_decode((string) $row->payload);
    $class = is_object($payload) && isset($payload->displayName) ? $payload->displayName : "unknown";
    echo "@@FJBEGIN class=".str_replace(" ", "", (string) $class)." queue=".str_replace(" ", "", (string) $row->queue)." failed_at=".(string) $row->failed_at."\n";
    echo substr((string) $row->exception, 0, 2500)."\n";
    echo "@@FJEND\n";
}
echo "@@FJDONE\n";
'
php_code=${php_code//__SINCE__/$since_sql}
php_code=${php_code//__MAX__/$MAX_FAILED_JOBS}

if docker exec "$CONTAINER" php artisan tinker --execute="$php_code" \
    > "$run_dir/failed-jobs.raw" 2> "$run_dir/failed-jobs.err"; then
    grep -q '^@@FJDONE$' "$run_dir/failed-jobs.raw" \
        || die "tinker failed-jobs probe incomplete — see $run_dir/failed-jobs.err"
    awk '
        /^@@FJBEGIN / { sub(/^@@FJBEGIN /, "@@BEGIN failed_jobs "); next }
        /^@@FJEND$/ { print "@@END"; next }
        /^@@FJDONE$/ { next }
        { print }
    ' "$run_dir/failed-jobs.raw" >> "$blocks"
    log "failed_jobs queried"
else
    die "failed_jobs query failed (docker exec/tinker) — see $run_dir/failed-jobs.err"
fi

# --- 3. scheduler container output --------------------------------------------
sched_id=$(docker compose --project-directory "$CHECKOUT" \
    -f "$CHECKOUT/docker-compose.yml" -f "$CHECKOUT/docker-compose.prod.yml" \
    ps -q scheduler 2>/dev/null || true)
if [ -z "$sched_id" ]; then
    sched_id=$(docker ps -q --filter 'label=com.docker.compose.service=scheduler' | head -n1)
fi
if [ -n "$sched_id" ]; then
    sched_raw="$run_dir/scheduler.raw"
    if docker logs --since "$since_rfc3339" "$sched_id" > "$sched_raw" 2>&1; then
        if grep -qiE 'exception|\.ERROR:|stack trace' "$sched_raw"; then
            {
                printf '@@BEGIN scheduler\n'
                grep -iE -B3 -A40 'exception|\.ERROR:|stack trace' "$sched_raw" | head -n 200
                printf '@@END\n'
            } >> "$blocks"
            log "scheduler output has error-looking lines"
        fi
    else
        log "WARN: docker logs on scheduler failed — skipping scheduler source"
    fi
else
    log "no scheduler container — skipping scheduler source (normal on dev)"
fi

# ---------------------------------------------------------- group & dedupe ----
# Signature = sha1 of the block headline with digits → "#" and long
# identifier-ish runs (session ids, UUIDs, hashes) → "TOKEN". Deterministic
# across runs; identical exceptions collapse into one group.
declare -A SIG_COUNT=()
declare -A SIG_FIRST=()
declare -A SIG_META=()
declare -a SIG_ORDER=()

sig_of() {
    printf '%s' "$1" \
        | sed -E 's/[A-Za-z0-9+/_=-]{16,}/TOKEN/g' \
        | head -c 300 \
        | tr '0-9' '##########' \
        | sha1sum \
        | cut -c1-12
}

process_block() {
    local meta=$1 text=$2
    [ -n "$text" ] || return 0
    local headline material
    headline=$(printf '%s\n' "$text" | head -n1)
    case $meta in
        laravel.log*) material=${headline#*] } ;;  # strip "[ts] " → "env.LEVEL: msg"
        *) material=$headline ;;
    esac
    local sig
    sig=$(sig_of "$material")
    if [ -z "${SIG_COUNT[$sig]+x}" ]; then
        SIG_COUNT[$sig]=1
        SIG_FIRST[$sig]=$text
        SIG_META[$sig]=$meta
        SIG_ORDER+=("$sig")
    else
        SIG_COUNT[$sig]=$((SIG_COUNT[$sig] + 1))
    fi
}

n_blocks=0
src=""
buf=""
while IFS= read -r line || [ -n "$line" ]; do
    case $line in
        "@@BEGIN "*)
            src=${line#@@BEGIN }
            buf=""
            ;;
        "@@END")
            n_blocks=$((n_blocks + 1))
            process_block "$src" "$buf"
            buf=""
            ;;
        *)
            buf+="$line"$'\n'
            ;;
    esac
done < "$blocks"

n_groups=${#SIG_ORDER[@]}
log "$n_blocks block(s) → $n_groups unique error group(s)"

if [ "$n_groups" -eq 0 ]; then
    log "clean — no findings, no analysis needed"
    printf '%s\n' "$run_dir"
    exit 0
fi

if [ "$n_groups" -gt "$MAX_GROUPS" ]; then
    log "WARN: $n_groups groups > cap $MAX_GROUPS — extra groups skipped this run:"
    for sig in "${SIG_ORDER[@]:$MAX_GROUPS}"; do
        log "  skipped signature $sig (${SIG_COUNT[$sig]} occurrence(s), ${SIG_META[$sig]})"
    done
fi

{
    printf '# Production errors since %s (run %s)\n\n' \
        "$since_rfc3339" "$(date -u -d "@$run_start" '+%Y-%m-%dT%H:%M:%SZ')"
    i=0
    for sig in "${SIG_ORDER[@]}"; do
        [ "$i" -ge "$MAX_GROUPS" ] && break
        i=$((i + 1))
        printf '## Group %d — signature: %s — occurrences: %d — source: %s\n\n' \
            "$i" "$sig" "${SIG_COUNT[$sig]}" "${SIG_META[$sig]}"
        printf '```\n'
        printf '%s\n' "${SIG_FIRST[$sig]}" | head -n "$MAX_BLOCK_LINES"
        printf '```\n\n'
    done
} > "$run_dir/findings.md"

log "wrote $run_dir/findings.md"
printf '%s\n' "$run_dir"
