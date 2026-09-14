<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SentenceMeaningMatch extends Model
{
    public const SIDE_A = 'a';

    public const SIDE_B = 'b';

    protected $fillable = [
        'entity_sentence_id',
        'meaning_match_id',
        'side',
    ];

    protected function casts(): array
    {
        return [
            'side' => 'string',
        ];
    }

    public function entitySentence(): BelongsTo
    {
        return $this->belongsTo(EntitySentence::class);
    }

    public function meaningMatch(): BelongsTo
    {
        return $this->belongsTo(MeaningMatch::class);
    }
}
