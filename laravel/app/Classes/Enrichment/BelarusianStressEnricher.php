<?php

namespace App\Classes\Enrichment;

/**
 * Belarusian stress marks — the Belarusian key/language of the shared
 * {@see CyrillicStressEnricher} body (ADR 0052). Python marks with the
 * package's Belarusian-trained Silero accentor; until Belarusian dictionary
 * data is imported the token hints contribute nothing, which is harmless.
 */
class BelarusianStressEnricher extends CyrillicStressEnricher
{
    public function key(): string
    {
        return 'be_stress';
    }

    public function languages(): array
    {
        return ['be'];
    }
}
