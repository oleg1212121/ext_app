<?php

namespace App\Classes;

use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Example;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use App\Support\DefinitionLanguage;
use App\Support\EnrichmentReply;
use App\Support\LemmaForms;
use App\Support\StagedTranslations;
use App\Support\StressMarks;
use App\Support\TranslationTargets;
use App\Support\WordLookupKey;
use Illuminate\Support\Facades\DB;

/**
 * The ask-AI word enrichment's persistence (popup-ai-enrich ticket 04): a
 * parsed reply merges into the word's content in ONE transaction — the
 * word's previous AI rows are deleted first (the re-ask's replace
 * semantics, ADR 0082 amendment; on a first ask there are none), then the
 * fresh rows insert exact-text deduped against what remains, every row
 * carrying `source = 'ai'` (null stays imported, and imported rows are
 * never touched by the delete). Translations stay link-only (ADR 0078):
 * candidates resolve against the native language's EXISTING words through
 * the shared TranslationTargets resolver and link as word_translations
 * pairs; the unmatched stage into the word's translations JSON. ZERO word
 * rows are ever created by the merge — the method returns whether anything
 * new landed at all (a fresh link, a new content row, a newly staged
 * string), which the endpoint no longer branches on: a parsed reply
 * finalizes the ask either way, because the ask itself succeeded. The ONE
 * word-minting method is {@see resolveOrAdopt}, the missing popup's
 * adopt-on-ask resolution (ticket 05), which runs before any enrichment;
 * its read-only half {@see resolveSurface} (ticket 07) serves the popup's
 * on-open re-resolution lookup without ever creating a row.
 */
class WordAiEnrichmentService
{
    /**
     * Parse a raw model reply and merge it. @see applyParsed().
     */
    public function apply(Word $word, string $raw, ?Language $nativeLanguage): bool
    {
        $parsed = EnrichmentReply::parse($raw);

        return $parsed === null ? false : $this->applyParsed($word, $parsed, $nativeLanguage);
    }

    /**
     * Merge a parsed reply (EnrichmentReply::parse output) into the word.
     * AI definitions pass the import's {@see DefinitionLanguage}
     * majority-script guard first (popup-ai-enrich ticket 08): only
     * own-script definitions reach the insert, so a disobedient model's
     * foreign-script definitions drop instead of polluting the dictionary —
     * and an all-dropped reply is NOT an error, the ask itself still
     * finalizes SUCCEEDED (the endpoint finalizes the ledger either way). The
     * guard filters on the entries' TEXT (ticket 09: a definition now
     * arrives as a `['text' => ..., 'labels' => [...]]` pair — a dropped
     * text drops its labels with it). Etymologies stay unguarded, the
     * import's deliberate choice.
     *
     * @param  array<string, list<mixed>>  $parsed
     */
    public function applyParsed(Word $word, array $parsed, ?Language $nativeLanguage): bool
    {
        $usable = false;

        $languageCode = (string) $word->language?->code;
        $definitions = array_values(array_filter(
            $parsed['definitions'],
            fn (array $definition): bool => DefinitionLanguage::keeps($languageCode, $definition['text']),
        ));

        DB::transaction(function () use ($word, $parsed, $definitions, $nativeLanguage, &$usable): void {
            // The re-ask's replace semantics (ADR 0082 amendment): the
            // word's previous AI rows go first, so the fresh answer takes
            // their place instead of piling differently-worded variants on
            // top of them. On a first ask there is nothing to delete; an
            // ask that dies between this delete and the succeeded finalize
            // leaves the word with only its imported rows — the same
            // retryable shape as any failed ask.
            $this->clearAiRows($word);

            $usable = $this->mergeAiRows($word, Definition::class, 'definition', $definitions) || $usable;

            // Transcriptions carry a NOT NULL per-language type FK: the
            // language's IPA row when it has one, else its first type — a
            // language with no types at all gets no AI transcriptions (the
            // taxonomy is seeded, never minted from an AI answer).
            $typeId = $this->transcriptionTypeId($word);

            if ($typeId !== null) {
                $usable = $this->mergeAiRows(
                    $word,
                    Transcription::class,
                    'transcription',
                    $parsed['transcriptions'],
                    ['transcription_type_id' => $typeId],
                ) || $usable;
            }

            // The examples table enforces unique (example, word_id) —
            // firstOrCreate IS the dedup, and source='ai' lands on the
            // created row only.
            foreach ($parsed['examples'] as $text) {
                $created = Example::query()->firstOrCreate(
                    ['word_id' => $word->id, 'example' => $text],
                    ['source' => 'ai'],
                );

                if ($created->wasRecentlyCreated) {
                    $usable = true;
                }
            }

            $usable = $this->mergeAiRows($word, Etymology::class, 'etymology', $parsed['etymologies']) || $usable;

            if ($nativeLanguage !== null && (int) $nativeLanguage->id !== (int) $word->language_id) {
                $usable = $this->linkOrStageTranslations($word, $parsed['translations'], $nativeLanguage) || $usable;
            }
        });

        return $usable;
    }

    /**
     * Delete the word's AI-attributed content rows — the re-ask's clear
     * before the fresh merge (ADR 0082 amendment). Scoped strictly to
     * `source = 'ai'`: imported rows (null source) are never touched, and
     * the AI-linked translations stay as they are (they are link/stage
     * records with no per-row AI attribution to select on). Runs inside
     * {@see applyParsed}'s transaction, so a failed merge rolls the delete
     * back.
     */
    private function clearAiRows(Word $word): void
    {
        Definition::query()->where('word_id', $word->id)->where('source', 'ai')->delete();
        Transcription::query()->where('word_id', $word->id)->where('source', 'ai')->delete();
        Example::query()->where('word_id', $word->id)->where('source', 'ai')->delete();
        Etymology::query()->where('word_id', $word->id)->where('source', 'ai')->delete();
    }

    /**
     * The exists→create merge the string-list sections share (etymologies,
     * and transcriptions with their type scoped in through $scope):
     * exact-text deduped, every created row carries `source = 'ai'`, true
     * when anything new landed. $scope is the section's remaining identity —
     * probed and created alike — which is how the transcription's NOT NULL
     * type joins the shared shape without a branch of its own. The
     * definitions section (ticket 09) passes `['text' => ..., 'labels' =>
     * [...]]` entries instead of bare strings: the text probes and stores
     * like every other section, and a non-empty labels list rides onto the
     * created row (an empty one stores as null, the column's unlabelled
     * shape). (The examples section stays explicit above: its dedup is the
     * DB's unique constraint via firstOrCreate, a different mechanism.)
     *
     * @param  class-string  $model
     * @param  list<string>|list<array{text: string, labels: list<string>}>  $entries
     * @param  array<string, int>  $scope
     */
    private function mergeAiRows(Word $word, string $model, string $textColumn, array $entries, array $scope = []): bool
    {
        $usable = false;

        foreach ($entries as $entry) {
            $text = is_array($entry) ? $entry['text'] : $entry;
            $labels = is_array($entry) ? $entry['labels'] : null;

            $exists = $model::query()
                ->where('word_id', $word->id)
                ->where($textColumn, $text)
                ->where($scope)
                ->exists();

            if (! $exists) {
                $attributes = ['word_id' => $word->id, $textColumn => $text, 'source' => 'ai'] + $scope;

                if ($labels !== null && $labels !== []) {
                    $attributes['labels'] = $labels;
                }

                $model::query()->create($attributes);
                $usable = true;
            }
        }

        return $usable;
    }

    /**
     * The missing popup's adopt-on-ask resolution (popup-ai-enrich ticket
     * 05): the RAW clicked surface resolves against the language's
     * dictionary rows through the shared {@see WordLookupKey} key — the
     * client's title is never trusted. A hit adopts onto the EXISTING row
     * (case/ё/stress variants never duplicate; a properly classed row wins
     * over the unknown placeholder, `EntityWordAdoption`'s rule) — and a
     * surface surviving only as an inflected form adopts onto its BASE
     * lemma (resolveSurface's forms pass), so the ask enriches "smile",
     * never a placeholder twin of a relay-demoted surface (ADR 0079). A
     * miss mints exactly one word with the dictionary-import creator
     * hygiene: a
     * plain display word (the combining stress marks leave the raw surface
     * for the StressMarks languages — ADR 0081 rule 1 keeps ё and case in
     * display), the folded l_word, the language's unknown class — never a
     * class invented from the reply's translations (ADR 0059 spirit;
     * applyParsed writes no class either) — carrying itself as its lemma
     * form through the shared {@see LemmaForms} rows (the ё е-alias
     * included). The mint's firstOrCreate on the unique
     * (l_word, language, class) triple is the race net: a concurrent ask
     * that lost the insert adopts the winner's row instead of failing.
     */
    public function resolveOrAdopt(string $surface, Language $language): Word
    {
        $existing = $this->resolveSurface($surface, $language);

        if ($existing !== null) {
            return $existing;
        }

        $key = WordLookupKey::normalize($surface);
        $unknownClassId = $this->ensureUnknownClass((int) $language->id);

        $stripsMarks = StressMarks::stripsFor($language->code);
        $display = $stripsMarks ? StressMarks::strip($surface) : $surface;

        $word = Word::query()->firstOrCreate(
            ['l_word' => $key, 'language_id' => $language->id, 'word_class_id' => $unknownClassId],
            ['word' => mb_substr($display, 0, 256), 'source' => Word::SOURCE_AI],
        );

        if ($word->wasRecentlyCreated) {
            Form::upsert(
                LemmaForms::rows($word->word, [], $word->id, $stripsMarks),
                ['form', 'word_id'],
            );
        } elseif ($word->source === null) {
            // The fill-if-null backdoor: the mint found a row that raced in
            // from the concurrent double-ask — claim it for the ask unless
            // another creator already owns it (ADR 0084). A resolve-surface
            // hit above is a read: it is never claimed here.
            $word->forceFill(['source' => Word::SOURCE_AI])->save();
        }

        return $word;
    }

    /**
     * The read-only half of {@see resolveOrAdopt} (popup-ai-enrich ticket
     * 07): the RAW surface resolves through the shared {@see WordLookupKey}
     * key against the language's rows — exact headwords first, then the
     * forms table (EntityWordLinker::link's order), so a surface surviving
     * only as an inflected form of its lemma — a relay demoted at import,
     * ADR 0079, "smiled" → "smile" — still resolves to its base word; a
     * properly classed row beats the unknown placeholder (the lowest id
     * takes it otherwise, `EntityWordAdoption::preferClassed`'s rule) —
     * and NULL when nothing carries the key. The 'unknown' class id
     * resolves READ-ONLY here: a language whose placeholder row was never
     * minted simply has no placeholder to lose against (the first
     * candidate wins), because a lookup must never create anything — the
     * popup's on-open re-resolution runs on every missing-card open, and
     * a silent word-class write would ride on a read. Only
     * {@see resolveOrAdopt}, the ask path, mints.
     */
    public function resolveSurface(string $surface, Language $language): ?Word
    {
        $key = WordLookupKey::normalize($surface);

        $candidates = Word::query()
            ->where('language_id', $language->id)
            ->where('l_word', $key)
            ->orderBy('id')
            ->get();

        // The forms pass sees only the surfaces the exact pass missed — an
        // exact headword always wins (EntityWordLinker::link's rule).
        if ($candidates->isEmpty()) {
            $candidates = Word::query()
                ->where('language_id', $language->id)
                ->whereIn('id', Form::query()->select('word_id')->where('l_word', $key))
                ->orderBy('id')
                ->get();
        }

        // A properly classed row beats the unknown placeholder; the lowest
        // id takes it otherwise (EntityWordAdoption::preferClassed's rule).
        // With no unknown row at all (null id) every candidate counts as
        // classed — the first candidate wins, the fallback the contract
        // names.
        $unknownClassId = WordClass::query()
            ->where('language_id', $language->id)
            ->where('slug', 'unknown')
            ->value('id');

        return $candidates->first(fn (Word $word): bool => (int) $word->word_class_id !== $unknownClassId)
            ?? $candidates->first();
    }

    /**
     * The 'unknown' part of speech every language carries — the same row
     * WiktionaryParser::ensureWordClass('unknown') and
     * EntityWordAdoption create.
     */
    private function ensureUnknownClass(int $languageId): int
    {
        return (int) WordClass::query()
            ->firstOrCreate(
                ['language_id' => $languageId, 'slug' => 'unknown'],
                ['title' => 'unknown'],
            )->id;
    }

    /**
     * The word's language transcription type for AI rows: IPA first, else the
     * language's first type, else null (skip transcriptions entirely).
     */
    private function transcriptionTypeId(Word $word): ?int
    {
        return TranscriptionType::query()
            ->where('language_id', $word->language_id)
            ->orderByRaw('CASE WHEN slug = ? THEN 0 ELSE 1 END', ['ipa'])
            ->orderBy('id')
            ->value('id');
    }

    /**
     * The link-only translation pass, mirroring FetchWordTranslations:
     * candidates normalize (trim, stress marks stripped for the target
     * language's rule), the over-long and sentence-punctuated drop, then the
     * shared resolver matches them against the native language's EXISTING
     * rows — matches link, the rest stages. True only when something NEW
     * landed (a fresh link or a staged string the JSON did not carry yet).
     *
     * @param  list<string>  $candidates
     */
    private function linkOrStageTranslations(Word $word, array $candidates, Language $nativeLanguage): bool
    {
        $strip = StressMarks::stripsFor($nativeLanguage->code);
        $usableCandidates = [];

        foreach ($candidates as $candidate) {
            $text = trim($strip ? StressMarks::strip($candidate) : $candidate);

            if ($text === ''
                || mb_strlen($text) > 256
                || preg_match('/[.,;:]/', $text) === 1) {
                continue;
            }

            $usableCandidates[] = $text;
        }

        if ($usableCandidates === []) {
            return false;
        }

        $sourceSlug = $word->word_class_id !== null
            ? WordClass::query()->whereKey($word->word_class_id)->value('slug')
            : null;

        $resolved = TranslationTargets::forLanguage(
            $nativeLanguage->id,
            TranslationTargets::wantedClasses(
                $usableCandidates,
                WordClass::query()
                    ->where('language_id', $nativeLanguage->id)
                    ->orderBy('id')
                    ->pluck('id', 'slug')
                    ->all(),
                $sourceSlug !== null ? (string) $sourceSlug : null,
            ),
        );

        $usable = false;

        foreach ($resolved as $targetWordId) {
            if (WordTranslation::link((int) $word->id, $targetWordId)->wasRecentlyCreated) {
                $usable = true;
            }
        }

        $staged = [];
        foreach ($usableCandidates as $text) {
            $normal = WordLookupKey::normalize($text);

            if (! isset($resolved[$normal]) && ! isset($staged[$normal])) {
                $staged[$normal] = $text;
            }
        }

        if ($staged !== []) {
            $merged = StagedTranslations::merge($word->translations, array_values($staged));

            if ($merged !== $word->translations) {
                $word->forceFill(['translations' => $merged])->save();
                $usable = true;
            }
        }

        return $usable;
    }
}
