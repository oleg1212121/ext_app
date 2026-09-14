<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Etymology extends Model
{
    protected $fillable = ['word_id', 'etymology'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
