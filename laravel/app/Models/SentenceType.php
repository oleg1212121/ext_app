<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SentenceType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description'];

    public function enEntitySentences(): HasMany
    {
        return $this->hasMany(EnEntitySentence::class, 'sentence_type_id');
    }

    public function ruEntitySentences(): HasMany
    {
        return $this->hasMany(RuEntitySentence::class, 'sentence_type_id');
    }
}
