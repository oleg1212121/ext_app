<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordTranslation extends Model
{
    protected $fillable = ['from_word_id', 'to_word_id'];

    public function fromWord(): BelongsTo
    {
        return $this->belongsTo(Word::class, 'from_word_id');
    }

    public function toWord(): BelongsTo
    {
        return $this->belongsTo(Word::class, 'to_word_id');
    }
}
