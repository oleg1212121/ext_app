<?php

use App\Classes\WikiBundle;
use App\Classes\WikiValidator;

it('ships a conformant OKF wiki bundle', function () {
    $root = WikiBundle::resolvePath();

    expect($root)->not->toBeNull('OKF wiki bundle not found (expected repo-root wiki/ or /var/repo/wiki)');

    $validator = (new WikiValidator($root))->validate();

    foreach ($validator->unacknowledgedWarnings() as $warning) {
        dump("wiki unacknowledged warning: {$warning}");
    }

    expect($validator->errors())->toBeEmpty()
        ->and($validator->baselineErrors())->toBeEmpty()
        ->and($validator->unacknowledgedWarnings())->toBeEmpty();
});

it('resolves the wiki bundle and lists concept documents', function () {
    $root = WikiBundle::resolvePath();

    expect($root)->not->toBeNull();

    $files = WikiBundle::markdownFiles($root);

    expect($files)->toContain('index.md')
        ->and($files)->toContain('log.md');
});

it('parses OKF frontmatter constructs used by the bundle', function () {
    $parsed = WikiBundle::parse(<<<'MD'
---
type: Feature
title: Demo
tags: [a, b]
generated: { by: agent/demo, at: 2026-07-26T12:00:00Z }
sources:
  - id: one
    resource: laravel/composer.json
  - id: two
    resource: https://example.com/docs
---

Body [link](/domains/demo.md).
MD);

    expect($parsed)->not->toBeNull()
        ->and($parsed['frontmatter']['type'])->toBe('Feature')
        ->and($parsed['frontmatter']['tags'])->toBe(['a', 'b'])
        ->and($parsed['frontmatter']['generated']['by'])->toBe('agent/demo')
        ->and($parsed['frontmatter']['sources'][0]['resource'])->toBe('laravel/composer.json')
        ->and($parsed['frontmatter']['sources'][1]['id'])->toBe('two')
        ->and($parsed['body'])->toContain('[link](/domains/demo.md)');
});

/*
|--------------------------------------------------------------------------
| wiki:validate unacknowledged-warning gate (owner+date baseline)
|--------------------------------------------------------------------------
|
| Fixture tests run against a throwaway temp-dir bundle whose single
| concept has a source file newer than generated.at, with the mtime check
| forced ON via config() — CI sets WIKI_SKIP_MTIME_CHECKS=1 at the job
| level, so the fixtures must not depend on the ambient setting.
*/

/**
 * Build the fixture bundle. Layout mirrors the real repo:
 * <tmp>/src/demo.php is the source, <tmp>/wiki the bundle root.
 */
function wikiGateFixtureBundle(): string
{
    $tmp = sys_get_temp_dir().'/wiki-gate-'.uniqid();
    mkdir($tmp.'/wiki/domains', 0777, true);
    mkdir($tmp.'/src', 0777, true);

    file_put_contents($tmp.'/src/demo.php', "<?php\n\n");
    file_put_contents($tmp.'/wiki/index.md', "---\nokf_version: '0.2'\n---\n\n# Fixture wiki\n");
    file_put_contents($tmp.'/wiki/domains/demo.md', <<<'MD'
---
type: Feature
title: Demo
generated: { by: agent/demo, at: 2026-01-01T00:00:00Z }
sources:
  - id: one
    resource: src/demo.php
---

Body without links.
MD);

    return $tmp.'/wiki';
}

/**
 * Write a baseline file independently of the code under test (plain
 * json_encode, not the validator's own encoder).
 *
 * @param  array<int, array<string, mixed>>  $entries
 */
function wikiGateWriteBaseline(string $baselinePath, array $entries): void
{
    file_put_contents(
        $baselinePath,
        json_encode(['entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
    );
}

function wikiGateRemoveDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;

        is_dir($path) ? wikiGateRemoveDir($path) : unlink($path);
    }

    rmdir($dir);
}

describe('wiki:validate unacknowledged-warning gate', function () {
    beforeEach(function () {
        config(['wiki.skip_mtime_staleness' => false]);

        $this->root = wikiGateFixtureBundle();
        $this->baselinePath = $this->root.'/validate-baseline.json';
        $this->driftWarning = "domains/demo.md: source 'src/demo.php' changed after generated.at — possibly stale";
        $this->staleEntryWarning = "domains/gone.md: source 'src/gone.php' changed after generated.at — possibly stale";
    });

    afterEach(function () {
        wikiGateRemoveDir(dirname($this->root));
    });

    it('reports fresh drift without a baseline as unacknowledged', function () {
        $validator = (new WikiValidator($this->root))->validate();

        expect($validator->warnings())->toBe([$this->driftWarning])
            ->and($validator->unacknowledgedWarnings())->toBe([$this->driftWarning])
            ->and($validator->acknowledgedWarnings())->toBe([])
            ->and($validator->staleBaselineEntries())->toBe([])
            ->and($validator->baselineErrors())->toBe([]);
    });

    it('acknowledges a warning whose message matches a baseline entry exactly', function () {
        wikiGateWriteBaseline($this->baselinePath, [
            ['warning' => $this->driftWarning, 'owner' => 'human:alex', 'date' => '2026-10-23', 'note' => 'benign drift'],
        ]);

        $validator = (new WikiValidator($this->root))->validate();

        expect($validator->warnings())->toBe([$this->driftWarning])
            ->and($validator->acknowledgedWarnings())->toBe([$this->driftWarning])
            ->and($validator->unacknowledgedWarnings())->toBe([])
            ->and($validator->staleBaselineEntries())->toBe([])
            ->and($validator->baselineErrors())->toBe([]);
    });

    it('reports baseline entries matching no current warning as stale', function () {
        wikiGateWriteBaseline($this->baselinePath, [
            ['warning' => $this->driftWarning, 'owner' => 'human:alex', 'date' => '2026-10-23', 'note' => 'benign drift'],
            ['warning' => $this->staleEntryWarning, 'owner' => 'human:alex', 'date' => '2026-10-23', 'note' => 'source removed since'],
        ]);

        $validator = (new WikiValidator($this->root))->validate();
        $stale = $validator->staleBaselineEntries();

        expect($validator->acknowledgedWarnings())->toBe([$this->driftWarning])
            ->and($stale)->toHaveCount(1)
            ->and($stale[0]['warning'])->toBe($this->staleEntryWarning);
    });

    it('reports a malformed baseline as an error and acknowledges nothing', function () {
        file_put_contents($this->baselinePath, '{ not valid json');

        $validator = (new WikiValidator($this->root))->validate();

        expect($validator->baselineErrors())->toHaveCount(1)
            ->and($validator->baselineErrors()[0])->toContain($this->baselinePath)
            ->and($validator->acknowledgedWarnings())->toBe([])
            ->and($validator->unacknowledgedWarnings())->toBe([$this->driftWarning]);
    });

    it('still flags non-mtime warnings as unacknowledged when the mtime check is skipped', function () {
        config(['wiki.skip_mtime_staleness' => true]);
        file_put_contents($this->root.'/domains/demo.md', <<<'MD'
---
type: Feature
title: Demo
status: quixotic
generated: { by: agent/demo, at: 2026-01-01T00:00:00Z }
sources:
  - id: one
    resource: src/demo.php
---

Body without links.
MD);

        $validator = (new WikiValidator($this->root))->validate();

        expect($validator->warnings())->toBe(["domains/demo.md: unknown status 'quixotic' (expected draft|stable|deprecated)"])
            ->and($validator->unacknowledgedWarnings())->toHaveCount(1);
    });

    it('fails with remediation guidance when a warning is unacknowledged', function () {
        $this->artisan('wiki:validate', ['--path' => $this->root])
            ->expectsOutputToContain('[warn] '.$this->driftWarning)
            ->expectsOutputToContain("bump the listing concept's generated.at, or acknowledge in the baseline with owner+date")
            ->assertExitCode(1);
    });

    it('passes and summarizes acknowledged warnings in one line naming the baseline', function () {
        wikiGateWriteBaseline($this->baselinePath, [
            ['warning' => $this->driftWarning, 'owner' => 'human:alex', 'date' => '2026-10-23', 'note' => 'benign drift'],
        ]);

        $this->artisan('wiki:validate', ['--path' => $this->root])
            ->expectsOutputToContain('1 warning(s) acknowledged in '.$this->baselinePath)
            ->assertSuccessful();
    });

    it('prunes stale baseline entries and reports the dropped count', function () {
        wikiGateWriteBaseline($this->baselinePath, [
            ['warning' => $this->driftWarning, 'owner' => 'human:alex', 'date' => '2026-10-23', 'note' => 'benign drift'],
            ['warning' => $this->staleEntryWarning, 'owner' => 'human:alex', 'date' => '2026-10-23', 'note' => 'source removed since'],
        ]);

        $this->artisan('wiki:validate', ['--path' => $this->root, '--prune' => true])
            ->expectsOutputToContain('dropped 1 stale')
            ->assertSuccessful();

        $entries = json_decode((string) file_get_contents($this->baselinePath), true)['entries'];

        expect($entries)->toHaveCount(1)
            ->and($entries[0]['warning'])->toBe($this->driftWarning)
            ->and((new WikiValidator($this->root))->validate()->staleBaselineEntries())->toBe([]);
    });

    it('refuses to prune a malformed baseline and leaves the file untouched', function () {
        file_put_contents($this->baselinePath, '{ not valid json');

        $this->artisan('wiki:validate', ['--path' => $this->root, '--prune' => true])
            ->expectsOutputToContain('Refusing to prune')
            ->assertExitCode(1);

        expect(file_get_contents($this->baselinePath))->toBe('{ not valid json');
    });

    it('leaves the baseline untouched when a prune would change nothing', function () {
        wikiGateWriteBaseline($this->baselinePath, [
            ['warning' => $this->driftWarning, 'owner' => 'human:alex', 'date' => '2026-10-23', 'note' => 'benign drift'],
        ]);
        $originalContent = file_get_contents($this->baselinePath);
        touch($this->baselinePath, time() - 3600);
        $originalMtime = filemtime($this->baselinePath);

        $this->artisan('wiki:validate', ['--path' => $this->root, '--prune' => true])
            ->expectsOutputToContain('file untouched')
            ->assertSuccessful();

        clearstatcache();

        expect(file_get_contents($this->baselinePath))->toBe($originalContent)
            ->and(filemtime($this->baselinePath))->toBe($originalMtime);
    });

    it('refuses to prune while the mtime staleness check is skipped', function () {
        config(['wiki.skip_mtime_staleness' => true]);
        wikiGateWriteBaseline($this->baselinePath, [
            ['warning' => $this->staleEntryWarning, 'owner' => 'human:alex', 'date' => '2026-10-23', 'note' => 'source removed since'],
        ]);
        $originalContent = file_get_contents($this->baselinePath);

        $this->artisan('wiki:validate', ['--path' => $this->root, '--prune' => true])
            ->expectsOutputToContain('Refusing to prune')
            ->assertSuccessful();

        expect(file_get_contents($this->baselinePath))->toBe($originalContent);
    });

    it('reports a baseline entry without owner and date as an error and acknowledges nothing', function () {
        wikiGateWriteBaseline($this->baselinePath, [
            ['warning' => $this->driftWarning, 'note' => 'missing the owner+date that makes an acknowledgement accountable'],
        ]);

        $validator = (new WikiValidator($this->root))->validate();

        expect($validator->baselineErrors())->toHaveCount(1)
            ->and($validator->baselineErrors()[0])->toContain('"owner" and "date"')
            ->and($validator->acknowledgedWarnings())->toBe([])
            ->and($validator->unacknowledgedWarnings())->toBe([$this->driftWarning])
            ->and($validator->baselineEntries())->toBe([]);
    });
});
