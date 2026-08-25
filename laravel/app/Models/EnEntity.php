<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnEntity extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'signature', 'file_path', 'is_restricted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_restricted' => 'boolean',
        ];
    }

    public function sentences(): HasMany
    {
        return $this->hasMany(EnEntitySentence::class, 'en_entity_id');
    }

    public function entityMatches(): HasMany
    {
        return $this->hasMany(EnRuEntityMatch::class, 'en_entity_id');
    }

    public function grantedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'en_entity_user')
            ->withPivot('similarity')
            ->withTimestamps();
    }
}
