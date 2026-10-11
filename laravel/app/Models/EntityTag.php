<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EntityTag extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name'];

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'tagged_entities')->withTimestamps();
    }
}
