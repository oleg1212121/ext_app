<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SentenceType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description'];

    public function entitySentences(): HasMany
    {
        return $this->hasMany(EntitySentence::class, 'sentence_type_id');
    }
}
