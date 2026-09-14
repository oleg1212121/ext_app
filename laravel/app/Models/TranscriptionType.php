<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionType extends Model
{
    protected $fillable = ['language_id', 'slug', 'title', 'description'];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
