<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Word extends Model
{
    /**
     * The frequency rank standing for "unranked": no frequency list carries
     * the word. Above every CrosswordLevel cutoff, so unranked words are
     * crossword-ineligible until entity corrections pull the rank in.
     */
    public const FREQUENCY_UNRANKED = 1100000;

    /**
     * The word-row creators — the closed words.source set (ADR 0084). Every
     * writer that can CREATE a word row stamps its value at INSERT and never
     * overwrites another one (first importer wins); null stays on pre-column
     * rows and hand-created admin words until a creating writer touches the
     * row again (the fill-if-null backdoor below).
     */
    public const SOURCE_KAIKKI = 'kaikki';

    public const SOURCE_GOLDDICT = 'golddict';

    public const SOURCE_CMUDICT = 'cmudict';

    public const SOURCE_ENTITY = 'entity';

    public const SOURCE_AI = 'ai';

    public const SOURCES = [
        self::SOURCE_KAIKKI,
        self::SOURCE_GOLDDICT,
        self::SOURCE_CMUDICT,
        self::SOURCE_ENTITY,
        self::SOURCE_AI,
    ];

    protected $fillable = [
        'language_id',
        'word',
        'l_word',
        'frequency',
        'word_class_id',
        'translations',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => 'decimal:2',
            'translations' => 'array',
            'completeness_at' => 'datetime',
        ];
    }

    /**
     * The fill-if-null provenance backdoor (ADR 0084): claim every
     * still-null row of the batch's (l_word, word_class_id) pairs in the
     * language for $source — one UPDATE per import batch, run AFTER the
     * batch's upsert. A re-running import badges the pre-column rows it
     * touches; a row another creator already claimed keeps its value (the
     * upsert's update list never carries source for the same reason — first
     * importer wins). Rows nothing touches again stay null.
     *
     * @param  array<int, array{l_word: string, word_class_id: int}>  $wordUpserts  the batch's upsert rows
     */
    public static function fillSourceIfNull(string $source, int $languageId, array $wordUpserts): void
    {
        if ($wordUpserts === []) {
            return;
        }

        $pairs = collect($wordUpserts)
            ->unique(fn (array $upsert) => $upsert['l_word'].'|'.$upsert['word_class_id']);
        $placeholders = $pairs->map(fn () => '(?, ?)')->implode(', ');
        $bindings = $pairs
            ->flatMap(fn (array $upsert) => [$upsert['l_word'], $upsert['word_class_id']])
            ->all();

        static::query()
            ->where('language_id', $languageId)
            ->whereRaw("(l_word, word_class_id) in ({$placeholders})", $bindings)
            ->whereNull('source')
            ->update(['source' => $source]);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function wordClass(): BelongsTo
    {
        return $this->belongsTo(WordClass::class);
    }

    public function definitions(): HasMany
    {
        return $this->hasMany(Definition::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function etymologies(): HasMany
    {
        return $this->hasMany(Etymology::class);
    }

    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }

    public function examples(): HasMany
    {
        return $this->hasMany(Example::class);
    }

    public function pronunciations(): HasMany
    {
        return $this->hasMany(Pronunciation::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tagged_words')->withTimestamps();
    }

    /**
     * Pivot rows of this word's translation links where the word sits on the a-side.
     */
    public function translationLinks(): HasMany
    {
        return $this->hasMany(WordTranslation::class, 'word_a_id');
    }

    /**
     * Pivot rows of this word's translation links where the word sits on the b-side.
     */
    public function translationLinksAsB(): HasMany
    {
        return $this->hasMany(WordTranslation::class, 'word_b_id');
    }

    /**
     * Words linked to this word as translations — a link works from either side.
     *
     * @return Collection<int, Word>
     */
    public function translationWords(): Collection
    {
        $otherIds = WordTranslation::query()
            ->where('word_a_id', $this->getKey())
            ->pluck('word_b_id')
            ->merge(
                WordTranslation::query()
                    ->where('word_b_id', $this->getKey())
                    ->pluck('word_a_id'),
            )
            ->unique()
            ->values();

        return Word::query()->whereIn('id', $otherIds)->get();
    }

    /**
     * Display label used by admin pickers: "word — Language (Word class)".
     */
    public function dictionaryLabel(): string
    {
        $label = $this->word.' — '.($this->language?->name ?? '?');

        if ($this->wordClass?->title) {
            $label .= " ({$this->wordClass->title})";
        }

        return $label;
    }
}
