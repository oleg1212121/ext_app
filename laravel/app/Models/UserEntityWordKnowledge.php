<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEntityWordKnowledge extends Model
{
    protected $table = 'user_entity_word_knowledge';

    protected $fillable = ['user_id', 'entity_id', 'score', 'computed_at'];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
