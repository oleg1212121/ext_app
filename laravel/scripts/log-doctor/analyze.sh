#!/usr/bin/env bash
# log-doctor — analyze collected findings with the read-only log-doctor agent.
#
# Usage: analyze.sh <run_dir>    (run_dir = the dir printed by collect.sh)
#
# Env:
#   LOG_DOCTOR_MODEL    "<provider>/<model>" — set by the workflow; locally
#                      unset → your default opencode model/auth is used.
#   LOG_DOCTOR_API_KEY  provider API key — when set (with MODEL), a throwaway
#                      per-run opencode config maps it to the provider, so no
#                      auth.json is needed on the machine.
#
# The agent (defined in .opencode/agents/log-doctor.md at the checkout root)
# runs with --dir <checkout> so it can read the actual source, and is strictly
# read-only — this checkout IS the running production app (bind mount).
#
# Output: <run_dir>/agent-output.json (the last ```json block of the agent's
# reply). Exits 1 if opencode fails or emits no JSON block — the workflow
# stays red and the window is reprocessed next run.
set -euo pipefail

run_dir=${1:?usage: analyze.sh <run_dir>}
[ -d "$run_dir" ] || { printf '[log-doctor] run dir not found: %s\n' "$run_dir" >&2; exit 1; }
if [ ! -f "$run_dir/findings.md" ]; then
    printf '[log-doctor] no findings.md in run dir — nothing to analyze\n' >&2
    exit 0
fi

CHECKOUT="${PROD_CHECKOUT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
MODEL=${LOG_DOCTOR_MODEL:-}
API_KEY=${LOG_DOCTOR_API_KEY:-}

command -v opencode >/dev/null 2>&1 \
    || { printf '[log-doctor] opencode binary not found on PATH (install: curl -fsSL https://opencode.ai/install | bash)\n' >&2; exit 1; }

run_dir=$(cd "$run_dir" && pwd) # absolute — the prompt must point the agent at it
findings="$run_dir/findings.md"

# Throwaway per-run opencode config (takes precedence over any global config
# for this invocation only): no snapshots (the checkout is huge), no session
# sharing (production data must never be uploaded), no autoupdates on a server.
cfg="$run_dir/opencode.json"
{
    printf '{\n'
    printf '  "autoupdate": false,\n'
    printf '  "snapshot": false,\n'
    printf '  "share": "disabled"'
    if [ -n "$MODEL" ]; then
        printf ',\n'
        if [ -n "$API_KEY" ]; then
            printf '  "model": "%s",\n' "$MODEL"
            printf '  "provider": {\n'
            printf '    "%s": { "options": { "apiKey": "{env:LOG_DOCTOR_API_KEY}" } }\n' "${MODEL%%/*}"
            printf '  }\n'
        else
            printf '  "model": "%s"\n' "$MODEL"
        fi
    else
        printf '\n'
    fi
    printf '}\n'
} > "$cfg"

cat > "$run_dir/prompt.txt" <<EOF
Analyze the production errors listed in the findings file: $findings

For each group in that file:
1. Investigate this repository to determine the ROOT CAUSE. Start from
   wiki/index.md for architecture context, then read the code the stack
   traces point at. Do not guess — if the code cannot explain the error,
   say so.
2. Decide a concrete, actionable fix and describe it in words (you cannot
   edit files, by design).

Then end your reply with EXACTLY ONE fenced json code block and NO text
after it — a JSON array with one object per group, in this shape:

[
  {
    "signature": "<signature from the group heading, copied verbatim>",
    "occurrences": <integer from the heading>,
    "source": "<source text from the heading, copied verbatim>",
    "severity": "high" | "medium" | "low",
    "title": "<short imperative title, max 70 characters, no double quotes>",
    "evidence": "<the most diagnostic lines from the group excerpt, verbatim, max 15 lines, joined with \\n>",
    "cause": "<root cause with file:line evidence, 2-6 sentences>",
    "solution": "<concrete fix description, 2-6 sentences>"
  }
]

Rules:
- Include EVERY group from the findings file, in the same order.
- Copy "signature", "occurrences" and "source" EXACTLY from the group
  headings — downstream automation keys on them.
- If the root cause cannot be determined from the code, state that
  explicitly in "cause" and suggest what evidence to collect next.
EOF

printf '[log-doctor] running opencode agent (this can take a few minutes)...\n' >&2
OPENCODE_CONFIG="$cfg" opencode run \
    --agent log-doctor \
    --dir "$CHECKOUT" \
    "$(cat "$run_dir/prompt.txt")" \
    > "$run_dir/agent-output.txt" 2> "$run_dir/opencode-stderr.log"

# Extract the LAST ```json fenced block from the reply (tolerates leading
# whitespace and mixed case; mawk-safe regex).
awk '
    BEGIN { keep = 0; out = "" }
    tolower($0) ~ /^[[:space:]]*```json/ { keep = 1; buf = ""; next }
    /^[[:space:]]*```/ { if (keep) { keep = 0; out = buf } next }
    keep { buf = buf $0 "\n" }
    END { printf "%s", out }
' "$run_dir/agent-output.txt" > "$run_dir/agent-output.json"

if [ ! -s "$run_dir/agent-output.json" ]; then
    printf '[log-doctor] no json block found in agent output — inspect %s/agent-output.txt\n' "$run_dir" >&2
    exit 1
fi

printf '[log-doctor] analysis complete — %s bytes of JSON\n' "$(wc -c < "$run_dir/agent-output.json")" >&2
