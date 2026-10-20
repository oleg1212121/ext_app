<?php

namespace App\Classes;

use App\Models\Definition;
use App\Models\Form;
use App\Models\Word;
use Illuminate\Support\Collection;

/**
 * The Word popup's entry set: the surface token's own headword group plus the
 * class-scoped headword groups of every Base word the forms table maps it to
 * (ADR 0045, ADR 0047).
 *
 * Kaikki's "form-of" entries are imported as standalone words whose only
 * definitions relay to the base word ("simple past and past participle of
 * melt"). Once the base groups are present, those relay entries are hidden
 * (the popup shows a "form of" pointer line instead) and stray relay glosses
 * are filtered out of partially-relay entries — kaikki merges form-of lines
 * into real entries, so e.g. "saw/verb" carries real saw senses AND
 * "simple past of see". Both are display-only: the filtering swaps the
 * definitions relation AFTER the completeness recalc and the ordering below,
 * which must judge the word's stored definition set — the same set
 * words:recalc-completeness's SQL mirrors (a relay gloss is an unlabelled,
 * live row there) — or the two paths would score such an entry differently
 * and words.completeness would flap depending on which ran last.
 *
 * The assembled entries then stable-sort [live entry before demoted entry,
 * then completeness-descending] (stored `words.completeness`, recalculated
 * lazily on the way out — see WordCompleteness): the richest live entry
 * leads, an entry whose every sense is obsolete/archaic sinks below every
 * live one, and the assembly order (linked word first, class priority,
 * base-group frequency) is the tiebreak.
 */
class WordFamily
{
    /**
     * Gloss shapes that merely relay to another word. Anchored at the start so
     * real definitions ("A form of address...") never match. The Russian
     * shapes are a starter list — the ru import is currently empty.
     *
     * @var list<string>
     */
    private const RELAY_GLOSS_PATTERNS = [
        '(?:simple )?past (?:tense|participle)\b.*\bof\b',
        'simple past\b.*\bof\b',
        'present participle\b.*\bof\b',
        'gerund\b.*\bof\b',
        'third-person\b.*\bof\b',
        '(?:plural|singular)\b.*\bof\b',
        '(?:comparative|superlative)\b.*\bof\b',
        'alternative (?:form|spelling|letter-case form) of\b',
        '(?:inflected|conjugated|declined|abbreviated|short|obsolete|archaic|dialectal) form of\b',
        'форма\b.*\bот\b',
        '(?:наст|прош|буд|повел|прич|деепр|мн|ед)\.\s.*\bот\b',
    ];

    private const EAGER_LOAD = [
        'wordClass:id,slug,title',
        'language:id,code',
        // created_at/updated_at ride along: WordCompleteness's staleness
        // threshold reads the children's newest stamps off the loaded
        // relations (no per-child re-query); labels rides along for the
        // demotion test (live-before-demoted ordering, ticket 10).
        'definitions:id,word_id,definition,labels,created_at,updated_at',
        'transcriptions:id,word_id,transcription,transcription_type_id,created_at,updated_at',
        'transcriptions.transcriptionType:id,slug,title',
        'examples:id,word_id,example,created_at,updated_at',
        'etymologies:id,word_id,etymology,created_at,updated_at',
        // Plain relation (no column list): the tagged_words pivot columns must
        // ride along. WordController::show plucks tag names off the bound word
        // for the popup's "Save to Anki" card.
        'tags',
    ];

    /**
     * @param  Collection<int, Word>  $entries  ordered popup entries: own group
     *                                          (linked word first, then class priority) followed by base groups ranked
     *                                          by frequency (lower = more common), class priority as tiebreak
     * @param  list<string>  $formOf  base headword display words, in group order
     */
    private function __construct(
        private readonly Collection $entries,
        private readonly array $formOf,
    ) {}

    public static function resolve(Word $linked, string $surface): self
    {
        $own = self::ownGroup($linked);
        $baseGroups = self::baseGroups($linked, self::lookupKey($surface));

        $relayHiding = $baseGroups->isNotEmpty();

        $entries = $own
            ->when($relayHiding, fn (Collection $group): Collection => $group->reject(self::isRelayEntry(...)))
            ->sortBy(fn (Word $entry): array => [
                $entry->id === $linked->id ? 0 : 1,
                EntityWordLinker::classPriority($entry->wordClass?->slug ?? ''),
                $entry->wordClass?->title ?? '',
            ])
            ->values();

        foreach ($baseGroups as $group) {
            $entries = $entries->merge($group
                ->sortBy(fn (Word $entry): array => [
                    EntityWordLinker::classPriority($entry->wordClass?->slug ?? ''),
                    $entry->wordClass?->title ?? '',
                ])
                ->values());
        }

        // The lazy completeness recalc (both columns persisted when stale,
        // in-memory scores refreshed either way) runs while every entry's
        // definitions relation is still the STORED set: isDemoted and the
        // definition points must judge what is in the database — the same
        // rows words:recalc-completeness's SQL scores, where a relay gloss
        // is an unlabelled, live definition — never the display-filtered
        // set below. contentStamp's staleness window reads the same stored
        // children, matching the sweep's all-definitions threshold.
        WordCompleteness::recalcFamily($entries);

        // The popup ordering: live entries before demoted ones
        // (WordCompleteness::isDemoted — every stored definition labelled
        // obsolete/archaic; shared with the score so they can never
        // disagree), then completeness-descending, over the assembled
        // order — linked word first, class priority, base groups ranked by
        // frequency — which remains the tiebreak for equal scores among
        // equally-live entries. PHP 8 sorts are stable, so uasort preserves
        // the assembled order within a score.
        $entries = $entries->sort(
            fn (Word $a, Word $b): int => ((int) WordCompleteness::isDemoted($a) <=> (int) WordCompleteness::isDemoted($b))
                ?: (($b->completeness ?? 0) <=> ($a->completeness ?? 0)),
        )->values();

        // Only now the display filter: swapping the definitions relation to
        // the relay-filtered set would otherwise leak into the scoring and
        // the demotion test above (a partially-relay entry whose every
        // surviving sense is obsolete/archaic would demote here while the
        // sweep — relay glosses unlabelled, live — keeps it up), and the
        // stored words.completeness would flap depending on which path ran
        // last.
        if ($relayHiding) {
            $entries->each(fn (Word $entry) => $entry->setRelation(
                'definitions',
                $entry->definitions
                    ->reject(fn (Definition $definition): bool => self::isRelayGloss($definition->definition))
                    ->values(),
            ));
        }

        return new self($entries, self::baseGroupHeadwords($baseGroups));
    }

    /**
     * @return Collection<int, Word>
     */
    public function entries(): Collection
    {
        return $this->entries;
    }

    /**
     * @return list<string>
     */
    public function formOf(): array
    {
        return $this->formOf;
    }

    /**
     * The lowercase, combining-mark-stripped lookup key — the same rule the
     * importer applies to words.l_word and forms.l_word (Russian headwords
     * carry stress marks that must not break the join).
     */
    public static function lookupKey(string $surface): string
    {
        return (string) preg_replace('/\p{M}/u', '', mb_strtolower(trim($surface)));
    }

    public static function isRelayEntry(Word $word): bool
    {
        return $word->definitions->isNotEmpty()
            && $word->definitions->every(
                fn (Definition $definition): bool => self::isRelayGloss($definition->definition),
            );
    }

    public static function isRelayGloss(string $definition): bool
    {
        $definition = trim($definition);

        foreach (self::RELAY_GLOSS_PATTERNS as $pattern) {
            $matched = preg_match(
                '/^(?:\([^)]*\)\s*)?'.$pattern.'/iu',
                $definition,
            );

            if ($matched === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Word>
     */
    private static function ownGroup(Word $linked): Collection
    {
        return Word::query()
            ->where('language_id', $linked->language_id)
            ->where('l_word', $linked->l_word)
            ->with(self::EAGER_LOAD)
            ->get();
    }

    /**
     * The linked word's headword groups for every Base word the forms table
     * maps the surface token to, ordered most common headword first. The own
     * headword is excluded — kaikki lists a headword among its own forms, and
     * the own group is already in the popup.
     *
     * Each base group is scoped to the word classes whose entries actually
     * claim the surface as one of their forms: "me" belongs to the pronoun
     * "I", not to the character or numeral entries sharing its spelling
     * (ADR 0047). A claim without a class keeps the whole group.
     *
     * @return Collection<int, Collection<int, Word>>
     */
    private static function baseGroups(Word $linked, string $key): Collection
    {
        if ($key === '') {
            return collect();
        }

        $claims = Form::query()
            ->join('words as base', 'base.id', '=', 'forms.word_id')
            ->where('base.language_id', $linked->language_id)
            ->where('forms.l_word', $key)
            ->where('base.l_word', '!=', $linked->l_word)
            ->whereNotNull('base.l_word')
            ->distinct()
            ->get(['base.l_word', 'base.word_class_id']);

        if ($claims->isEmpty()) {
            return collect();
        }

        $claimingClasses = [];
        foreach ($claims as $claim) {
            if ($claim->word_class_id !== null) {
                $claimingClasses[$claim->l_word][] = (int) $claim->word_class_id;
            }
        }

        $groups = Word::query()
            ->where('language_id', $linked->language_id)
            ->whereIn('l_word', $claims->pluck('l_word')->unique()->values())
            ->with(self::EAGER_LOAD)
            ->get()
            ->groupBy('l_word')
            ->map(fn (Collection $group, int|string $lWord): Collection => $group->when(
                ($classes = $claimingClasses[$lWord] ?? null) !== null,
                fn (Collection $scoped): Collection => $scoped->filter(
                    fn (Word $word): bool => in_array($word->word_class_id, $classes, true),
                )->values(),
            ))
            ->filter(fn (Collection $group): bool => $group->isNotEmpty());

        return $groups
            ->sortBy(fn (Collection $group): array => [
                (float) $group->min('frequency'),
                self::bestClassPriority($group),
                (string) $group->first()->l_word,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Collection<int, Word>>  $baseGroups
     * @return list<string>
     */
    private static function baseGroupHeadwords(Collection $baseGroups): array
    {
        return $baseGroups
            ->map(fn (Collection $group): string => $group
                ->sortBy(fn (Word $word): array => [(float) $word->frequency, $word->id])
                ->first()
                ->word)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Word>  $group
     */
    private static function bestClassPriority(Collection $group): int
    {
        return (int) $group->min(
            fn (Word $word): int => EntityWordLinker::classPriority($word->wordClass?->slug ?? ''),
        );
    }
}
