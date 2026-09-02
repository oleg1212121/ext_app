<?php

/**
 * log-doctor — file or update GitHub issues from the agent's JSON analysis.
 *
 * Runs INSIDE the ext_app_laravel container (plain php, no Laravel boot):
 *
 *   docker exec -i -e GITHUB_TOKEN -e GITHUB_REPOSITORY ... ext_app_laravel \
 *       php scripts/log-doctor/issue-report.php < agent-output.json
 *
 * stdin: the JSON array produced by the log-doctor opencode agent (schema in
 * .opencode/agents/log-doctor.md). For every item it searches OPEN issues for
 * the deterministic signature line "log-doctor-sig: <hash>" embedded in issue
 * bodies: found → comment "still occurring"; not found → create an issue
 * labelled bug / log-doctor / automated. Deduplication is entirely
 * deterministic — the LLM never decides whether to file.
 *
 * --dry-run skips all GitHub API calls and prints what it would do.
 *
 * Exit 0 = every item filed; exit 1 = invalid input or any item failed (the
 * workflow stays red and the collection window is reprocessed next run).
 */
$dryRun = in_array('--dry-run', $argv ?? [], true);

$token = (string) getenv('GITHUB_TOKEN');
$repository = (string) getenv('GITHUB_REPOSITORY');
$serverUrl = rtrim((string) getenv('GITHUB_SERVER_URL') ?: 'https://github.com', '/');
$runId = (string) getenv('GITHUB_RUN_ID');
$runAt = (string) getenv('LOG_DOCTOR_RUN_AT') ?: gmdate('Y-m-d\TH:i:s\Z');
$sinceAt = (string) getenv('LOG_DOCTOR_SINCE_AT') ?: 'unknown';

if (! $dryRun && ($token === '' || $repository === '')) {
    fwrite(STDERR, "[log-doctor] GITHUB_TOKEN / GITHUB_REPOSITORY env missing.\n");
    exit(1);
}

/**
 * GitHub REST call. Returns [httpStatus, decodedBodyArray].
 */
function github(string $method, string $path, ?array $body = null): array
{
    global $token;

    $url = 'https://api.github.com'.$path;
    $payload = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer '.$token,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: ext_app-log-doctor',
    ];
    if ($payload !== '') {
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ];
        if ($payload !== '') {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            $raw = '';
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context) ?: '';
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', (string) ($http_response_header[0] ?? ''), $m);
            $status = (int) ($m[1] ?? 0);
        }
    }

    $decoded = json_decode((string) $raw, true);

    return [$status, is_array($decoded) ? $decoded : []];
}

/**
 * Create the bug / log-doctor / automated labels if the repo lacks them
 * (never recolors existing labels).
 */
function ensureLabels(): void
{
    global $repository;

    [$status, $labels] = github('GET', "/repos/{$repository}/labels?per_page=100");
    if ($status !== 200) {
        fwrite(STDERR, "[log-doctor] WARN: cannot list labels (HTTP {$status}) — issue creation may fail if labels are missing.\n");

        return;
    }
    $have = [];
    foreach ($labels as $label) {
        $have[] = (string) ($label['name'] ?? '');
    }
    foreach (['bug' => 'd73a4a', 'log-doctor' => '5319e7', 'automated' => '0e8a16'] as $name => $color) {
        if (in_array($name, $have, true)) {
            continue;
        }
        [$status] = github('POST', "/repos/{$repository}/labels", ['name' => $name, 'color' => $color]);
        if ($status !== 201 && $status !== 422) {
            fwrite(STDERR, "[log-doctor] WARN: label '{$name}' not created (HTTP {$status}).\n");
        }
    }
}

function runLine(): string
{
    global $serverUrl, $repository, $runId;

    if ($runId === '') {
        return '';
    }

    return ' [View the workflow run]('.$serverUrl.'/'.$repository.'/actions/runs/'.$runId.')';
}

function issueBody(array $g): string
{
    $signature = $g['signature'];
    $sinceAt = $g['sinceAt'];
    $runAt = $g['runAt'];
    $source = $g['source'];
    $occurrences = $g['occurrences'];
    $severity = $g['severity'];
    $evidence = $g['evidence'] === '' ? '(no excerpt provided)' : $g['evidence'];
    $cause = $g['cause'];
    $solution = $g['solution'];
    $link = runLine();

    return <<<MD
Automated error analysis from the hourly log-doctor workflow.

log-doctor-sig: {$signature}

- **Window:** new occurrences after {$sinceAt} (analyzed at {$runAt})
- **Source:** {$source} — {$occurrences} new occurrence(s)
- **Severity:** {$severity}

## Error excerpt

````
{$evidence}
````

## Root cause

{$cause}

## Suggested solution

{$solution}

---
:robot: AI-generated diagnosis — verify before acting.{$link}
MD;
}

function commentBody(array $g): string
{
    $sinceAt = $g['sinceAt'];
    $runAt = $g['runAt'];
    $occurrences = $g['occurrences'];
    $severity = $g['severity'];
    $cause = $g['cause'];
    $solution = $g['solution'];
    $link = runLine();

    return <<<MD
**Still occurring** — {$occurrences} new occurrence(s) in the window after {$sinceAt} (analyzed at {$runAt}).

**Severity:** {$severity}

**Latest root cause:** {$cause}

**Latest suggested solution:** {$solution}

---
:robot: AI-generated — see the issue body above for the original report.{$link}
MD;
}

// ------------------------------------------------------------------ input ----

$stdin = (string) stream_get_contents(STDIN);
$items = json_decode($stdin, true);
if (! is_array($items) || $items === []) {
    fwrite(STDERR, "[log-doctor] stdin is not a non-empty JSON array.\n");
    exit(1);
}

$failures = 0;
if (count($items) > 10) {
    fwrite(STDERR, sprintf("[log-doctor] %d items exceeds the cap of 10 — extras dropped.\n", count($items)));
    $failures++;
}

$groups = [];
foreach (array_slice($items, 0, 10) as $i => $item) {
    if (! is_array($item)) {
        fwrite(STDERR, "[log-doctor] item {$i}: not an object — skipped.\n");
        $failures++;

        continue;
    }
    $signature = strtolower(trim((string) ($item['signature'] ?? '')));
    if (! preg_match('/^[0-9a-f]{6,16}$/', $signature)) {
        fwrite(STDERR, "[log-doctor] item {$i}: signature '{$signature}' is not a hex hash — skipped.\n");
        $failures++;

        continue;
    }
    $title = trim((string) preg_replace('/\s+/', ' ', (string) ($item['title'] ?? '')) ?? '');
    if ($title === '') {
        $title = 'Production error '.$signature;
    }
    $title = mb_substr($title, 0, 70);
    $severity = strtolower((string) ($item['severity'] ?? ''));
    if (! in_array($severity, ['high', 'medium', 'low'], true)) {
        $severity = 'medium';
    }
    $cause = trim((string) ($item['cause'] ?? ''));
    if ($cause === '') {
        fwrite(STDERR, "[log-doctor] item {$i}: empty cause — skipped.\n");
        $failures++;

        continue;
    }
    $evidenceLines = array_slice(preg_split('/\r\n|\r|\n/', trim((string) ($item['evidence'] ?? ''))) ?: [], 0, 15);
    $groups[] = [
        'signature' => $signature,
        'title' => $title,
        'severity' => $severity,
        'source' => mb_substr(trim((string) ($item['source'] ?? 'unknown')), 0, 120),
        'occurrences' => max(1, (int) ($item['occurrences'] ?? 1)),
        'evidence' => mb_substr(implode("\n", $evidenceLines), 0, 2000),
        'cause' => mb_substr($cause, 0, 4000),
        'solution' => mb_substr(trim((string) ($item['solution'] ?? '(none proposed)')), 0, 4000),
        'sinceAt' => $sinceAt,
        'runAt' => $runAt,
    ];
}

if ($groups === []) {
    fwrite(STDERR, "[log-doctor] no valid groups in agent output.\n");
    exit(1);
}

// ------------------------------------------------------------------ filing ---

if (! $dryRun) {
    ensureLabels();
}

foreach ($groups as $g) {
    if ($dryRun) {
        echo "[dry-run] CREATE issue — {$g['title']} (sig {$g['signature']}, {$g['occurrences']}x, {$g['severity']})\n";
        echo "--- body ---\n".issueBody($g)."\n--- end body ---\n";

        continue;
    }

    [$status, $data] = github(
        'GET',
        '/search/issues?q='.rawurlencode('repo:'.$repository.' is:issue is:open "log-doctor-sig: '.$g['signature'].'"')
    );
    if ($status !== 200) {
        fwrite(STDERR, "[log-doctor] issue search failed (HTTP {$status}) for sig {$g['signature']} — skipped.\n");
        $failures++;

        continue;
    }
    $existing = (int) ($data['total_count'] ?? 0) > 0 ? ($data['items'][0]['number'] ?? null) : null;

    if ($existing !== null) {
        [$status] = github('POST', "/repos/{$repository}/issues/{$existing}/comments", ['body' => commentBody($g)]);
        if ($status !== 201) {
            fwrite(STDERR, "[log-doctor] comment on #{$existing} failed (HTTP {$status}).\n");
            $failures++;

            continue;
        }
        echo "[log-doctor] commented on #{$existing} — sig {$g['signature']} still occurring ({$g['occurrences']}x)\n";
    } else {
        [$status, $created] = github('POST', "/repos/{$repository}/issues", [
            'title' => '[log-doctor] '.$g['title'],
            'body' => issueBody($g),
            'labels' => ['bug', 'log-doctor', 'automated'],
        ]);
        if ($status !== 201) {
            fwrite(STDERR, "[log-doctor] issue create failed (HTTP {$status}) for sig {$g['signature']} — skipped.\n");
            $failures++;

            continue;
        }
        echo '[log-doctor] created issue #'.($created['number'] ?? '?')." — {$g['title']} (sig {$g['signature']})\n";
    }
}

exit($failures > 0 ? 1 : 0);
