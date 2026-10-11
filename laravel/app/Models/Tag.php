<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name'];

    public function words(): BelongsToMany
    {
        return $this->belongsToMany(Word::class, 'tagged_words')->withTimestamps();
    }
}
