<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SentenceType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description'];

    /**
     * The seeded sentence type an illustration sentence carries (ADR 0050).
     * Null before the seeder runs — callers treat that as "no illustration
     * type available".
     */
    public static function illustrationId(): ?int
    {
        $id = static::query()->where('name', 'illustration')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function entitySentences(): HasMany
    {
        return $this->hasMany(EntitySentence::class, 'sentence_type_id');
    }
}
