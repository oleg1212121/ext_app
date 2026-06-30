<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuEntitySentence extends Model
{
    use HasFactory;

    protected $fillable = ['ru_entity_id', 'sentence_type_id', 'content', 'order'];

    protected $casts = [
        'order' => 'integer',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(RuEntity::class, 'ru_entity_id');
    }

    public function sentenceType(): BelongsTo
    {
        return $this->belongsTo(SentenceType::class, 'sentence_type_id');
    }

    public function ruMeaningMatches(): HasMany
    {
        return $this->hasMany(RuSentenceMeaningMatch::class, 'ru_entity_sentence_id');
    }
}
