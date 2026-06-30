<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuSentenceMeaningMatch extends Model
{
    protected $fillable = [
        'ru_entity_sentence_id',
        'en_ru_meaning_match_id',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    public function ruEntitySentence(): BelongsTo
    {
        return $this->belongsTo(RuEntitySentence::class, 'ru_entity_sentence_id');
    }

    public function meaningMatch(): BelongsTo
    {
        return $this->belongsTo(EnRuMeaningMatch::class, 'en_ru_meaning_match_id');
    }
}
