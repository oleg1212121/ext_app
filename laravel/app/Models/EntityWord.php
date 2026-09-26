<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityWord extends Model
{
    protected $fillable = ['entity_id', 'word_id', 'l_word', 'token', 'count', 'unmatchable_at'];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'unmatchable_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
