<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnRuEntityMatch extends Model
{
    protected $fillable = [
        'en_entity_id',
        'ru_entity_id',
        'is_original_en',
        'status',
        'entity_similarity',
        'en_total_sentences',
        'ru_total_sentences',
        'linked_count',
        'chunk_size',
        'max_n',
        'last_en_sentence_offset',
        'last_ru_sentence_offset',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_similarity' => 'decimal:4',
            'is_original_en' => 'boolean',
            'en_total_sentences' => 'integer',
            'ru_total_sentences' => 'integer',
            'linked_count' => 'integer',
            'chunk_size' => 'integer',
            'max_n' => 'integer',
            'last_en_sentence_offset' => 'integer',
            'last_ru_sentence_offset' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function enEntity(): BelongsTo
    {
        return $this->belongsTo(EnEntity::class, 'en_entity_id');
    }

    public function ruEntity(): BelongsTo
    {
        return $this->belongsTo(RuEntity::class, 'ru_entity_id');
    }

    public function meaningMatches(): HasMany
    {
        return $this->hasMany(EnRuMeaningMatch::class, 'en_ru_entity_match_id');
    }

    public function getConfirmedCountAttribute(): int
    {
        return (int) $this->meaningMatches()
            ->where(fn ($query) => $query->whereHas('enSentenceMatches')->orWhereHas('ruSentenceMatches'))
            ->count();
    }
}
