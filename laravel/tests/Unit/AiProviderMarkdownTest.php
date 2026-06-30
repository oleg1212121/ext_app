<?php

use App\Classes\Gemini;
use App\Classes\OpenRouter;
use Tests\TestCase;

uses(TestCase::class);

describe('AI markdown arrow normalization', function () {
    it('converts LaTeX arrows to ASCII in the base markdown parser', function () {
        $gemini = new Gemini;

        $html = $gemini->markdownToHtml('go $\rightarrow$ went');

        expect($html)->toContain('go =&gt; went')
            ->not->toContain('rightarrow');
    });

    it('converts LaTeX arrows to ASCII in the OpenRouter markdown parser', function () {
        $openRouter = new OpenRouter;

        $html = $openRouter->markdownToHtml('go $\rightarrow$ went');

        expect($html)->toMatch('/go (=>|=&gt;) went/')
            ->not->toContain('rightarrow');
    });

    it('converts common arrow variants to ASCII', function (string $input) {
        $gemini = new Gemini;

        $html = $gemini->markdownToHtml("change $input form");

        expect($html)->toContain('change =&gt; form')
            ->not->toContain('rightarrow');
    })->with([
        'latex rightarrow' => '$\rightarrow$',
        'bare rightarrow' => '\rightarrow',
        'unicode arrow' => '→',
        'latex to' => '$\to$',
        'latex Rightarrow' => '$\Rightarrow$',
    ]);
});
