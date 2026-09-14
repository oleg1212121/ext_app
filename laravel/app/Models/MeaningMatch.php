<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeaningMatch extends Model
{
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
