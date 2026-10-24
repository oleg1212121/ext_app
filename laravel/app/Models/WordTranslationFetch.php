<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fetch-ledger row per (word, target language). status=empty is the
 * exclusion list: every provider answered "no translation", so the word is
 * never looked up again for that language. The link-only fetch records its
 * outcome on the row: linked_count candidates became word_translations
 * pairs, staged_count were staged into the source word's translations JSON
 * for the later link pass.
 */
class WordTranslationFetch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_FAILED = 'failed';

    /** Failed lookups stop being re-dispatched at this attempt count. */
    public const MAX_ATTEMPTS = 6;

    /** A failed lookup waits this long before a new dispatch is allowed. */
    public const RETRY_COOLDOWN_HOURS = 24;

    protected $fillable = [
        'word_id',
        'target_language_id',
        'provider',
        'status',
        'linked_count',
        'staged_count',
        'attempts',
        'last_attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_count' => 'integer',
            'staged_count' => 'integer',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
        ];
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }

    public function targetLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'target_language_id');
    }
}
