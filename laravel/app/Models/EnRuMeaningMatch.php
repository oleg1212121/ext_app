<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnRuMeaningMatch extends Model
{
    protected $fillable = [
        'en_ru_entity_match_id',
        'order',
        'similarity',
        'alignment_chunk',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'similarity' => 'decimal:4',
            'alignment_chunk' => 'integer',
        ];
    }

    public function entityMatch(): BelongsTo
    {
        return $this->belongsTo(EnRuEntityMatch::class, 'en_ru_entity_match_id');
    }

    public function enSentenceMatches(): HasMany
    {
        return $this->hasMany(EnSentenceMeaningMatch::class, 'en_ru_meaning_match_id');
    }

    public function ruSentenceMatches(): HasMany
    {
        return $this->hasMany(RuSentenceMeaningMatch::class, 'en_ru_meaning_match_id');
    }
}
