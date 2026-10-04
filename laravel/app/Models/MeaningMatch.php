<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeaningMatch extends Model
{
    /**
     * The alignment_chunk sentinel marking a human-made row: the Re-align
     * pipeline pins it against deletion and re-alignment, and MAX(chunk)+1
     * machine chunk ids can never collide with it.
     */
    public const HUMAN_CHUNK = -1;

    /**
     * Similarity at or above which an auto-aligned row is a landmark: pinned
     * against re-alignment, never deleted by the pipeline (ADR 0051).
     */
    public const LANDMARK_THRESHOLD = 0.90;

    protected $fillable = [
        'entity_match_id',
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
        return $this->belongsTo(EntityMatch::class);
    }

    public function sentenceMeaningMatches(): HasMany
    {
        return $this->hasMany(SentenceMeaningMatch::class);
    }

    public function sideSentenceMeaningMatches(string $side): HasMany
    {
        return $this->sentenceMeaningMatches()->where('side', $side);
    }
}
