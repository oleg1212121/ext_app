<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The ask-AI word-enrichment ledger (popup-ai-enrich ticket 04): one row per
 * word. A `succeeded` row is the lock — the toolbar's ask icon greys out and
 * the endpoint rejects further asks; `failed` (and `pending`) attempts stay
 * retryable, each retry bumping `attempts`.
 */
class WordAiFetch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'word_id',
        'provider',
        'model',
        'status',
        'attempts',
        'error',
        'asked_at',
        'succeeded_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'asked_at' => 'datetime',
            'succeeded_at' => 'datetime',
        ];
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }

    /**
     * Whether the word has been enriched by AI already — the word-show
     * payload's `ai_asked` and the popup's grey-icon flag.
     */
    public static function succeededFor(int $wordId): bool
    {
        return self::query()
            ->where('word_id', $wordId)
            ->where('status', self::STATUS_SUCCEEDED)
            ->exists();
    }
}
