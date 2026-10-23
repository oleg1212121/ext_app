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
            // set); null = unlabelled. A kaikki re-import refreshes labels
            // on same-text rows (ADR 0083 amendment, 2026-10-23).
            'labels' => 'array',
        ];
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
