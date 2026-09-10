<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Example extends Model
{
    protected $fillable = ['word_id', 'example'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
