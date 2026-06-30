<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnRuTranslation extends Model
{
    use HasFactory;

    protected $fillable = ['en_word_id', 'ru_word_id'];

    public function enWord(): BelongsTo
    {
        return $this->belongsTo(EnWord::class, 'en_word_id');
    }

    public function ruWord(): BelongsTo
    {
        return $this->belongsTo(RuWord::class, 'ru_word_id');
    }
}
