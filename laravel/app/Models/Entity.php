<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    protected $fillable = [
        'work_id',
        'language_id',
        'name',
        'label',
        'description',
        'signature',
        'file_path',
        'is_restricted',
    ];

    protected function casts(): array
    {
        return [
            'is_restricted' => 'boolean',
        ];
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function sentences(): HasMany
    {
        return $this->hasMany(EntitySentence::class);
    }

    public function matchesAsA(): HasMany
    {
        return $this->hasMany(EntityMatch::class, 'a_entity_id');
    }

    public function matchesAsB(): HasMany
    {
        return $this->hasMany(EntityMatch::class, 'b_entity_id');
    }

    /**
     * Every entity match this entity takes part in, on either side.
     */
    public function entityMatches(): Builder
    {
        return EntityMatch::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('a_entity_id', $this->id)
                ->orWhere('b_entity_id', $this->id));
    }

    public function grantedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'entity_user')
            ->withPivot('similarity')
            ->withTimestamps();
    }
}
