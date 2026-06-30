<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuExample extends Model
{
    use HasFactory;

    protected $fillable = ['example', 'ru_word_id'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(RuWord::class, 'ru_word_id');
    }
}
