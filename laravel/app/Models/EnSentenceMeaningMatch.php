<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnSentenceMeaningMatch extends Model
{
    protected $fillable = [
        'en_entity_sentence_id',
        'en_ru_meaning_match_id',
    ];

    public function enEntitySentence(): BelongsTo
    {
        return $this->belongsTo(EnEntitySentence::class, 'en_entity_sentence_id');
    }

    public function meaningMatch(): BelongsTo
    {
        return $this->belongsTo(EnRuMeaningMatch::class, 'en_ru_meaning_match_id');
    }
}
