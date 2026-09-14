<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
