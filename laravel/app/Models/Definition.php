<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Definition extends Model
{
    protected $fillable = ['word_id', 'definition'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
