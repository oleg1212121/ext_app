<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SentenceMeaningMatch extends Model
{
    public const SIDE_A = 'a';

    public const SIDE_B = 'b';

    protected $fillable = [
        'entity_match_id',
        'entity_sentence_id',
        'meaning_match_id',
        'side',
    ];

    protected static function booted(): void
    {
        // The denormalized entity_match_id backs the strict
        // unique(entity_match_id, entity_sentence_id) junction-uniqueness
        // index (ADR 0048) — auto-fill it from the parent meaning match so no
        // Eloquent writer can silently leave it null and escape enforcement.
        static::creating(function (self $junction): void {
            if ($junction->entity_match_id === null) {
                $junction->entity_match_id = MeaningMatch::query()
                    ->whereKey($junction->meaning_match_id)
                    ->value('entity_match_id');
            }
        });
    }

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

    public function entityMatch(): BelongsTo
    {
        return $this->belongsTo(EntityMatch::class);
    }
}
