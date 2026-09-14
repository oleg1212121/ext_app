<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntityMatch extends Model
{
    protected $fillable = [
        'a_entity_id',
        'b_entity_id',
        'status',
        'entity_similarity',
        'a_total_sentences',
        'b_total_sentences',
        'linked_count',
        'chunk_size',
        'max_n',
        'a_last_sentence_offset',
        'b_last_sentence_offset',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_similarity' => 'decimal:4',
            'a_total_sentences' => 'integer',
            'b_total_sentences' => 'integer',
            'linked_count' => 'integer',
            'chunk_size' => 'integer',
            'max_n' => 'integer',
            'a_last_sentence_offset' => 'integer',
            'b_last_sentence_offset' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function aEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'a_entity_id');
    }

    public function bEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'b_entity_id');
    }

    public function meaningMatches(): HasMany
    {
        return $this->hasMany(MeaningMatch::class);
    }

    public function getConfirmedCountAttribute(): int
    {
        return (int) $this->meaningMatches()
            ->whereHas('sentenceMeaningMatches')
            ->count();
    }

    /**
     * Which side holds the work's original text: 'a', 'b', or null when
     * neither side is the original language (both are translations).
     */
    public function originalSide(): ?string
    {
        $work = $this->aEntity?->work ?: $this->bEntity?->work;

        if ($work === null) {
            return null;
        }

        if ($this->aEntity?->language_id === $work->original_language_id) {
            return 'a';
        }

        if ($this->bEntity?->language_id === $work->original_language_id) {
            return 'b';
        }

        return null;
    }

    /**
     * Map a language code to this match's side ('a' or 'b').
     */
    public function sideForLanguage(string $languageCode): ?string
    {
        if (($this->aEntity?->language?->code ?? null) === $languageCode) {
            return 'a';
        }

        if (($this->bEntity?->language?->code ?? null) === $languageCode) {
            return 'b';
        }

        return null;
    }

    /**
     * The entities keyed by side.
     *
     * @return array<string, Entity>
     */
    public function entitiesBySide(): array
    {
        return array_filter([
            'a' => $this->aEntity,
            'b' => $this->bEntity,
        ]);
    }
}
