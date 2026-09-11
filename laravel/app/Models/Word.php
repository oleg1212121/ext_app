<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Word extends Model
{
    protected $fillable = [
        'language_id',
        'word',
        'l_word',
        'frequency',
        'word_class_id',
        'translations',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => 'decimal:8',
            'translations' => 'array',
        ];
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
        return $this->belongsToMany(Tag::class, 'word_tags');
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
