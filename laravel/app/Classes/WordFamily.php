<?php

namespace App\Classes;

use App\Models\Definition;
use App\Models\Form;
use App\Models\Word;
use Illuminate\Support\Collection;

/**
 * The Word popup's entry set: the surface token's own headword group plus the
 * headword groups of every Base word the forms table maps it to (ADR 0045).
 *
 * Kaikki's "form-of" entries are imported as standalone words whose only
 * definitions relay to the base word ("simple past and past participle of
 * melt"). Once the base groups are present, those relay entries are hidden
 * (the popup shows a "form of" pointer line instead) and stray relay glosses
 * are filtered out of partially-relay entries — kaikki merges form-of lines
 * into real entries, so e.g. "saw/verb" carries real saw senses AND
 * "simple past of see".
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
        'definitions:id,word_id,definition',
        'transcriptions:id,word_id,transcription,transcription_type_id',
        'transcriptions.transcriptionType:id,slug,title',
        'examples:id,word_id,example',
        'etymologies:id,word_id,etymology',
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
     * @return Collection<int, Collection<int, Word>>
     */
    private static function baseGroups(Word $linked, string $key): Collection
    {
        if ($key === '') {
            return collect();
        }

        $baseLWords = Form::query()
            ->join('words as base', 'base.id', '=', 'forms.word_id')
            ->where('base.language_id', $linked->language_id)
            ->where('forms.l_word', $key)
            ->where('base.l_word', '!=', $linked->l_word)
            ->whereNotNull('base.l_word')
            ->distinct()
            ->pluck('base.l_word');

        if ($baseLWords->isEmpty()) {
            return collect();
        }

        return Word::query()
            ->where('language_id', $linked->language_id)
            ->whereIn('l_word', $baseLWords)
            ->with(self::EAGER_LOAD)
            ->get()
            ->groupBy('l_word')
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
