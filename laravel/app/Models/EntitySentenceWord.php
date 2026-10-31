<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntitySentenceWord extends Model
{
    public $timestamps = false;

    protected $fillable = ['entity_id', 'entity_sentence_id', 'position', 'start', 'end', 'l_word', 'token'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'start' => 'integer',
            'end' => 'integer',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function sentence(): BelongsTo
    {
        return $this->belongsTo(EntitySentence::class, 'entity_sentence_id');
    }
}
