<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnDefinition extends Model
{
    use HasFactory;

    protected $fillable = ['definition', 'en_word_id'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(EnWord::class, 'en_word_id');
    }
}
