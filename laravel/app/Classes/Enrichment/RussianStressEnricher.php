<?php

namespace App\Classes\Enrichment;

use App\Models\Entity;
use App\Models\Form;

/**
 * Russian stress marks: Silero accentor on the python side, fed with the
 * dictionary's stressed-form candidates carrying U+0301 — inflected forms
 * first, then the headword itself (Wiktionary style) (ADR 0052).
 */
class RussianStressEnricher implements Enricher
{
    public function key(): string
    {
        return 'ru_stress';
    }

    public function version(): int
    {
        return 1;
    }

    public function pythonVersion(): int
    {
        return 1;
    }

    public function languages(): array
    {
        return ['ru'];
    }

    public function annotation(): Annotation
    {
        return Annotation::stress();
    }

    public function tokenHints(Entity $entity, array $keys, array $resolved): array
    {
        if ($keys === []) {
            return [];
        }

        $hints = [];

        $forms = Form::query()
            ->whereIn('l_word', $keys)
            ->select('l_word', 'form')
            ->get();

        foreach ($forms as $form) {
            $hints[$form->l_word]['stressed'][] = $form->form;
        }

        foreach ($resolved as $key => $base) {
            if ($base['headword'] !== null && $this->hasStressMarks($base['headword'])) {
                $hints[$key]['stressed'][] = $base['headword'];
            }
        }

        return $hints;
    }

    public function requestExtras(Entity $entity): array
    {
        return [];
    }

    public function toStorage(mixed $output): mixed
    {
        return $output === null ? null : (string) $output;
    }

    private function hasStressMarks(string $word): bool
    {
        return preg_match('/\p{M}/u', $word) === 1 || mb_strpos($word, 'ё') !== false || mb_strpos($word, 'Ё') !== false;
    }
}
