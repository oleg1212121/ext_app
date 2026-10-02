<?php

namespace App\Classes\Enrichment;

use App\Classes\EntityWordLinker;
use App\Models\Entity;
use App\Models\Word;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * English stress marks: python marks syllable-aligned acutes from the token's
 * Wiktionary-style IPA variants (pyphen alignment, ADR 0053). Laravel supplies
 * the stress-first-capped IPA variants plus per-part IPA for hyphenated
 * compounds the dictionary lacks.
 */
class EnglishStressEnricher implements Enricher
{
    /** IPA transcription variants passed per token. */
    private const IPA_VARIANTS_LIMIT = 3;

    public function key(): string
    {
        return 'en_stress';
    }

    public function version(): int
    {
        return 1;
    }

    public function languages(): array
    {
        return ['en'];
    }

    public function column(): string
    {
        return 'stressed_content';
    }

    public function tokenHints(Entity $entity, array $keys, array $resolved): array
    {
        $wordIds = collect($resolved)
            ->pluck('word_id')
            ->filter()
            ->unique()
            ->values();

        $ipaByWordId = $this->ipaByWordId($wordIds);

        $hints = [];

        foreach ($resolved as $key => $base) {
            $ipa = $base['word_id'] !== null ? ($ipaByWordId[$base['word_id']] ?? null) : null;

            if ($ipa !== null) {
                $hints[$key]['ipa'] = $ipa;
            }
        }

        $this->attachHyphenParts($entity, $keys, $hints);

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

    /**
     * Hyphenated compounds the dictionary has no whole-word entry for
     * ("seven-sided"): resolve each part's IPA separately so python can mark
     * the compound per part (ADR 0053). Only fires when the whole-token
     * lookup came up empty.
     *
     * @param  list<string>  $keys
     * @param  array<string, array<string, mixed>>  $hints
     */
    private function attachHyphenParts(Entity $entity, array $keys, array &$hints): void
    {
        $partKeys = [];

        foreach ($keys as $key) {
            if (str_contains($key, '-') && empty($hints[$key]['ipa'])) {
                foreach (explode('-', $key) as $part) {
                    if ($part !== '') {
                        $partKeys[$part] = true;
                    }
                }
            }
        }

        if ($partKeys === []) {
            return;
        }

        $partWords = Word::query()
            ->where('language_id', $entity->language_id)
            ->whereIn('l_word', array_keys($partKeys))
            ->with('wordClass:id,slug')
            ->get()
            ->groupBy('l_word')
            ->map(fn ($group) => $group
                ->sortBy(fn (Word $w) => [EntityWordLinker::classPriority($w->wordClass?->slug ?? ''), $w->id])
                ->first());

        $partIpa = $this->ipaByWordId($partWords->pluck('id')->values());

        foreach ($keys as $key) {
            if (! str_contains($key, '-') || ! empty($hints[$key]['ipa'])) {
                continue;
            }

            $parts = [];

            foreach (explode('-', $key) as $part) {
                $word = $partWords[$part] ?? null;
                $parts[] = [
                    'surface' => $part,
                    'ipa' => $word !== null ? ($partIpa[$word->id] ?? null) : null,
                ];
            }

            if (collect($parts)->contains(fn (array $p): bool => $p['ipa'] !== null && $p['ipa'] !== [])) {
                $hints[$key]['parts'] = $parts;
            }
        }
    }

    /**
     * @param  Collection<int, int>  $wordIds
     * @return array<int, list<string>>
     */
    private function ipaByWordId(Collection $wordIds): array
    {
        if ($wordIds->isEmpty()) {
            return [];
        }

        // Stress-bearing variants first (Postgres sorts false before true) so
        // the per-word cap below can't cut off the only variant with a primary
        // stress mark; id order keeps the selection deterministic.
        $rows = DB::table('transcriptions')
            ->join('transcription_types', 'transcription_types.id', '=', 'transcriptions.transcription_type_id')
            ->whereIn('transcriptions.word_id', $wordIds)
            ->where('transcription_types.slug', 'ipa')
            ->orderByRaw('(transcriptions.transcription NOT LIKE ?)', ['%ˈ%'])
            ->orderBy('transcriptions.id')
            ->select('transcriptions.word_id', 'transcriptions.transcription')
            ->get();

        $byWordId = [];

        foreach ($rows as $row) {
            if (count($byWordId[$row->word_id] ?? []) >= self::IPA_VARIANTS_LIMIT) {
                continue;
            }

            $byWordId[$row->word_id][] = $row->transcription;
        }

        return $byWordId;
    }
}
