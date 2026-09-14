<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pronunciation extends Model
{
    protected $fillable = ['word_id', 'path'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
