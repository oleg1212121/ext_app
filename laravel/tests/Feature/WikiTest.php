<?php

use App\Classes\WikiBundle;
use App\Classes\WikiValidator;

it('ships a conformant OKF wiki bundle', function () {
    $root = WikiBundle::resolvePath();

    expect($root)->not->toBeNull('OKF wiki bundle not found (expected repo-root wiki/ or /var/repo/wiki)');

    $validator = (new WikiValidator($root))->validate();

    foreach ($validator->warnings() as $warning) {
        dump("wiki warning: {$warning}");
    }

    expect($validator->errors())->toBeEmpty();
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
