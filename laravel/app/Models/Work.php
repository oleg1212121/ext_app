<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Work extends Model
{
    protected $fillable = ['title', 'author', 'description', 'original_language_id'];

    protected function casts(): array
    {
        return [
            'original_language_id' => 'integer',
        ];
    }

    public function originalLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'original_language_id');
    }

    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }

    /**
     * The work's entity matches, reached through the A-side entity: the
     * canonical side rule (lower entity id is the A-side) keeps the A-side
     * of every same-work pair inside this work.
     */
    public function alignments(): HasManyThrough
    {
        return $this->hasManyThrough(EntityMatch::class, Entity::class, 'work_id', 'a_entity_id');
    }
}
