<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Definition extends Model
{
    protected $fillable = ['word_id', 'definition', 'source', 'labels'];

    public function casts(): array
    {
        return [
            // The usage/register labels (App\Support\UsageLabels closed
            // set); null = unlabelled — pre-2026-10 rows were never
            // backfilled.
            'labels' => 'array',
        ];
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
