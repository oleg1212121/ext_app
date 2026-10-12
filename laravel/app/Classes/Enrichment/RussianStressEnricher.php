<?php

namespace App\Classes\Enrichment;

/**
 * Russian stress marks — the Russian key/language of the shared
 * {@see CyrillicStressEnricher} body (ADR 0052).
 */
class RussianStressEnricher extends CyrillicStressEnricher
{
    public function key(): string
    {
        return 'ru_stress';
    }

    public function languages(): array
    {
        return ['ru'];
    }
}
