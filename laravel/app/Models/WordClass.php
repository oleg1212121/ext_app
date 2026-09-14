<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WordClass extends Model
{
    protected $fillable = ['language_id', 'slug', 'title', 'description'];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function words(): HasMany
    {
        return $this->hasMany(Word::class);
    }
}
