<?php

/**
 * Prepares a container-local git repository at the Laravel project root
 * so Pest's Tia Engine can derive a project key and detect changed files.
 *
 * Why this exists:
 *   - The Laravel app is bind-mounted at /var/www (only the laravel/ subdir).
 *   - The full repo (with .git) is bind-mounted at /var/repo.
 *   - Pest TIA refuses to run when the project root is a subdirectory of a
 *     larger git repo, and /var/www has no .git of its own.
 *
 * What it does (idempotent):
 *   1. If /var/www is not a git repo, `git init` and set the remote URL
 *      (copied from /var/repo so the project key matches the host + CI).
 *   2. If the repo has no commits, create a single baseline commit of the
 *      current working tree. This commit is the reference point TIA diffs
 *      against. It is NOT updated on subsequent runs — user edits stay
 *      uncommitted so TIA can detect them.
 *
 * This script is safe to run before every `composer test:tia` invocation.
 * It only does work the first time (or after a container rebuild wipes .git).
 */
$projectRoot = realpath(__DIR__.'/..');
$repoRoot = '/var/repo';

if (! is_dir($repoRoot)) {
    fwrite(STDERR, "[tia-setup] Refusing to run: not inside the container (\$repoRoot={$repoRoot} missing).\n");
    fwrite(STDERR, "[tia-setup] Run via `docker exec ext_app_laravel composer run test:tia` instead.\n");
    exit(1);
}

if (! is_dir($projectRoot.'/.git')) {
    echo "[tia-setup] Initializing container-local git repo at {$projectRoot}\n";

    passthru("git -C {$projectRoot} init --quiet", $exit);
    if ($exit !== 0) {
        fwrite(STDERR, "[tia-setup] `git init` failed.\n");
        exit(1);
    }

    $remoteUrl = '';
    if (is_dir($repoRoot.'/.git')) {
        $remoteUrl = trim((string) shell_exec("git -C {$repoRoot} config --get remote.origin.url 2>/dev/null"));
    }
    if ($remoteUrl === '') {
        $remoteUrl = trim((string) shell_exec("git -C {$projectRoot}/.. config --get remote.origin.url 2>/dev/null"));
    }
    if ($remoteUrl === '') {
        $remoteUrl = 'https://github.com/oleg1212121/ext_app.git';
    }

    passthru("git -C {$projectRoot} remote add origin ".escapeshellarg($remoteUrl), $exit);
    if ($exit !== 0) {
        fwrite(STDERR, "[tia-setup] Warning: could not add origin remote.\n");
    } else {
        echo "[tia-setup] Set origin to {$remoteUrl}\n";
    }
} else {
    echo "[tia-setup] Git repo already present at {$projectRoot}\n";
}

$hasHead = trim((string) shell_exec("git -C {$projectRoot} rev-parse --verify HEAD 2>/dev/null"));
if ($hasHead === '') {
    echo "[tia-setup] Creating TIA baseline commit of current working tree\n";

    passthru("git -C {$projectRoot} add -A", $exit);
    if ($exit !== 0) {
        fwrite(STDERR, "[tia-setup] `git add -A` failed.\n");
        exit(1);
    }

    $commitEnv = 'GIT_AUTHOR_NAME="TIA Setup" GIT_AUTHOR_EMAIL="tia@local" GIT_COMMITTER_NAME="TIA Setup" GIT_COMMITTER_EMAIL="tia@local"';
    passthru("{$commitEnv} git -C {$projectRoot} commit --quiet -m 'TIA baseline commit'", $exit);
    if ($exit !== 0) {
        fwrite(STDERR, "[tia-setup] Baseline commit failed (nothing to commit?).\n");
        exit(1);
    }

    echo "[tia-setup] Baseline commit created.\n";
} else {
    $shortSha = trim((string) shell_exec("git -C {$projectRoot} rev-parse --short HEAD"));
    echo "[tia-setup] Baseline commit {$shortSha} already exists; leaving it untouched.\n";
}

echo "[tia-setup] TIA git context ready.\n";
