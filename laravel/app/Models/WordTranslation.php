<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordTranslation extends Model
{
    protected $fillable = ['word_a_id', 'word_b_id'];

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            [$link->word_a_id, $link->word_b_id] = self::canonicalize(
                (int) $link->word_a_id,
                (int) $link->word_b_id,
            );
        });
    }

    /**
     * Canonical pair order: the lower word id is the a-side.
     *
     * @return array{0: int, 1: int}
     */
    public static function canonicalize(int $firstWordId, int $secondWordId): array
    {
        return $firstWordId <= $secondWordId
            ? [$firstWordId, $secondWordId]
            : [$secondWordId, $firstWordId];
    }

    public static function isLinked(int $firstWordId, int $secondWordId): bool
    {
        return self::query()
            ->where('word_a_id', $firstWordId)
            ->where('word_b_id', $secondWordId)
            ->orWhere(function ($query) use ($firstWordId, $secondWordId): void {
                $query
                    ->where('word_a_id', $secondWordId)
                    ->where('word_b_id', $firstWordId);
            })
            ->exists();
    }

    public static function link(int $firstWordId, int $secondWordId): self
    {
        [$wordAId, $wordBId] = self::canonicalize($firstWordId, $secondWordId);

        return self::query()->firstOrCreate([
            'word_a_id' => $wordAId,
            'word_b_id' => $wordBId,
        ]);
    }

    /**
     * The word on the other side of the pair, relative to the given word id.
     */
    public function otherWord(int $wordId): ?Word
    {
        return $this->word_a_id === $wordId
            ? $this->wordB
            : $this->wordA;
    }

    public function wordA(): BelongsTo
    {
        return $this->belongsTo(Word::class, 'word_a_id');
    }

    public function wordB(): BelongsTo
    {
        return $this->belongsTo(Word::class, 'word_b_id');
    }
}
