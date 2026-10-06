<?php

namespace App\Classes\Enrichment;

use Closure;
use Filament\Tables\Columns\TextColumn;

/**
 * The reader-facing vertical of one enrichment analysis (ADR 0067): the
 * entity_sentences column its results persist into, the Reading-row payload
 * key they ship under, and the per-user display preference — the ui_settings
 * key both reading surfaces read, the Filament Sentences preview column.
 * Distinct from the analysis-side Enricher (ADR 0057): one Annotation can be
 * fed by two Enrichers — the Russian and English stress analyses share the
 * stress marks annotation — and the registry hands out the deduplicated set.
 */
class Annotation
{
    /**
     * @param  string  $payloadKey  the Reading-row sentence key the stored value ships under
     * @param  string  $column  the entity_sentences column the value persists into
     * @param  string  $settingKey  the ui_settings key (reader.* / simulator.*) holding the display preference
     * @param  Closure(): TextColumn  $adminPreview  the Filament Sentences preview column
     */
    public function __construct(
        public readonly string $payloadKey,
        public readonly string $column,
        public readonly string $settingKey,
        public readonly Closure $adminPreview,
    ) {}

    /**
     * The shared stress-marks annotation — the one annotation two enrichers
     * feed, so its values are declared once, here.
     */
    public static function stress(): self
    {
        return new self(
            payloadKey: 'stressed',
            column: 'stressed_content',
            settingKey: 'stress_marks',
            adminPreview: fn (): TextColumn => TextColumn::make('stressed_content')
                ->label('Stress marks')
                ->wrap()
                ->toggleable(isToggledHiddenByDefault: true)
                ->placeholder('—'),
        );
    }
}
