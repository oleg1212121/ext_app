<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Form extends Model
{
    protected $fillable = ['word_id', 'form', 'l_word'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
