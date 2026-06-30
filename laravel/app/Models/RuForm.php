<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuForm extends Model
{
    use HasFactory;

    protected $fillable = ['form', 'l_word', 'ru_word_id'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(RuWord::class, 'ru_word_id');
    }
}
