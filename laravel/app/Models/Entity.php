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
        'created_by',
        'name',
        'label',
        'description',
        'signature',
        'file_path',
        'file_hash',
        'text_hash',
        'text_hashed_at',
        'sentences_updated_at',
        'is_restricted',
        'is_approved',
        'words_indexed_at',
        'frequency_counted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_restricted' => 'boolean',
            'is_approved' => 'boolean',
            'text_hashed_at' => 'datetime',
            'sentences_updated_at' => 'datetime',
            'words_indexed_at' => 'datetime',
            'frequency_counted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Entity $entity): void {
            // Approved entities are edit-locked, deletion included. Admins
            // (the only Filament delete surface) still pass; console/queue
            // contexts have no authenticated user and are not blocked.
            $user = auth()->user();

            if ($entity->is_approved && $user !== null && ! $user->isAdmin()) {
                throw new \RuntimeException('Approved entities cannot be deleted.');
            }
        });
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Mark the entity's sentence set as changed, making its text hash stale.
     * Called from every sentence mutation path, including bulk writers that
     * bypass Eloquent model events.
     */
    public function touchSentences(): void
    {
        static::touchSentencesFor($this->getKey());
    }

    public static function touchSentencesFor(int $entityId): void
    {
        static::query()
            ->whereKey($entityId)
            ->update(['sentences_updated_at' => now()]);
    }

    public function sentences(): HasMany
    {
        return $this->hasMany(EntitySentence::class);
    }

    public function entityWords(): HasMany
    {
        return $this->hasMany(EntityWord::class);
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

    /**
     * Display state of the entity's signature: generated, pending (file
     * uploaded but not yet processed), or none.
     */
    public function signatureStatus(): string
    {
        if ($this->signature !== null) {
            return 'generated';
        }

        if ($this->file_path !== null) {
            return 'pending';
        }

        return 'none';
    }
}
