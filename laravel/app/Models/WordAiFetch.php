<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The ask-AI word-enrichment ledger (popup-ai-enrich ticket 04): one row per
 * word. Statuses track the LAST ask only — `pending` while one is in flight
 * (past the grace window it is takeover bait, not a lock), `failed` when the
 * ask died (retryable), `succeeded` when a reply was parsed and merged. A
 * succeeded ask is not a lock (ADR 0082 amendment): the toolbar turns the
 * ask icon into the re-ask variant and a re-ask replaces the previous AI
 * rows.
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
     * Whether the word has ever been enriched by AI — the word-show
     * payload's `ai_asked` and the popup's re-ask-icon flag. Judged on
     * `succeeded_at`, not the current status: a later FAILED re-ask keeps
     * its first success's timestamp, so the icon stays the re-ask variant
     * (the ask is not "first time") after a failed retry.
     */
    public static function succeededFor(int $wordId): bool
    {
        return self::query()
            ->where('word_id', $wordId)
            ->whereNotNull('succeeded_at')
            ->exists();
    }
}
