<?php

namespace App\Models;

use App\Enums\Side;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntityMatch extends Model
{
    protected $fillable = [
        'a_entity_id',
        'b_entity_id',
        'created_by',
        'status',
        'entity_similarity',
        'a_total_sentences',
        'b_total_sentences',
        'linked_count',
        'chunk_size',
        'max_n',
        'a_last_sentence_offset',
        'b_last_sentence_offset',
        'error_message',
        'started_at',
        'completed_at',
        'refined_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_similarity' => 'decimal:4',
            'a_total_sentences' => 'integer',
            'b_total_sentences' => 'integer',
            'linked_count' => 'integer',
            'chunk_size' => 'integer',
            'max_n' => 'integer',
            'a_last_sentence_offset' => 'integer',
            'b_last_sentence_offset' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'refined_at' => 'datetime',
        ];
    }

    public function aEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'a_entity_id');
    }

    public function bEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'b_entity_id');
    }

    /**
     * The user who created this alignment — whose processing slot it
     * consumes while pending/aligning (ADR 0044). Distinct from the side
     * entities' uploaders, who can differ.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function meaningMatches(): HasMany
    {
        return $this->hasMany(MeaningMatch::class);
    }

    /**
     * The side's entity on this match.
     */
    public function entityFor(Side $side): ?Entity
    {
        return $side === Side::A ? $this->aEntity : $this->bEntity;
    }

    /**
     * The side's entity id on this match.
     */
    public function entityIdFor(Side $side): int
    {
        return (int) ($side === Side::A ? $this->a_entity_id : $this->b_entity_id);
    }

    /**
     * Monotonic per-run alignment chunk id (MAX+1, 0 for a fresh match).
     * Human-edited rows use the MeaningMatch::HUMAN_CHUNK sentinel, so
     * machine ids can never collide with it.
     */
    public function nextAlignmentChunk(): int
    {
        $max = $this->meaningMatches()->max('alignment_chunk');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * Recount the meaning matches and persist the result. Every writer of
     * linked_count goes through this — the count is the progress bar shown
     * on the alignment surfaces, and a site that forgets to resync
     * desyncs it.
     */
    public function syncLinkedCount(): int
    {
        $this->update(['linked_count' => $this->meaningMatches()->count()]);

        return (int) $this->linked_count;
    }

    /**
     * The image-less sentence counts per side — the aligner's cursor space
     * (ADR 0050): illustrations never enter a chunk window, a count, or a
     * cursor.
     *
     * @return array{a: int, b: int}
     */
    public function recountTotals(): array
    {
        return [
            'a' => self::alignableCountForEntity((int) $this->a_entity_id),
            'b' => self::alignableCountForEntity((int) $this->b_entity_id),
        ];
    }

    /**
     * Recount and persist both sides' totals (see recountTotals).
     */
    public function syncTotals(): void
    {
        $totals = $this->recountTotals();

        $this->update([
            'a_total_sentences' => $totals['a'],
            'b_total_sentences' => $totals['b'],
        ]);
    }

    /**
     * Resync the totals of every match involving one entity after its
     * sentence set changed outside the alignment editor (the entities
     * frontend, the Filament relation manager). The stale flag those edits
     * raise alongside it (ADR 0055, via EntitySentenceStore — ADR 0065)
     * says "re-align"; the resynced totals keep the editor header truthful
     * meanwhile.
     */
    public static function syncTotalsForEntity(int $entityId): void
    {
        $count = self::alignableCountForEntity($entityId);

        self::query()->where('a_entity_id', $entityId)->update(['a_total_sentences' => $count]);
        self::query()->where('b_entity_id', $entityId)->update(['b_total_sentences' => $count]);
    }

    /**
     * The image-less sentence count of one entity — the unit every total is
     * built from.
     */
    public static function alignableCountForEntity(int $entityId): int
    {
        return (int) EntitySentence::query()
            ->where('entity_id', $entityId)
            ->withoutImage()
            ->count();
    }

    public function getConfirmedCountAttribute(): int
    {
        return (int) $this->meaningMatches()
            ->whereHas('sentenceMeaningMatches')
            ->count();
    }

    /**
     * Which side holds the work's original text: 'a', 'b', or null when
     * neither side is the original language (both are translations).
     */
    public function originalSide(): ?string
    {
        $work = $this->aEntity?->work ?: $this->bEntity?->work;

        if ($work === null) {
            return null;
        }

        if ($this->aEntity?->language_id === $work->original_language_id) {
            return 'a';
        }

        if ($this->bEntity?->language_id === $work->original_language_id) {
            return 'b';
        }

        return null;
    }

    /**
     * Which side a reading surface opens as the learning text for a user:
     * the side in the user's native language becomes the translation, so the
     * other side is read. When neither side (or both) is native, the work's
     * original side is read; with no original side either, the canonical
     * A-side. The same rule drives the library's Read button.
     */
    public function readingSideFor(?int $nativeLanguageId): string
    {
        $aIsNative = $nativeLanguageId !== null && $this->aEntity?->language_id === $nativeLanguageId;
        $bIsNative = $nativeLanguageId !== null && $this->bEntity?->language_id === $nativeLanguageId;

        if ($aIsNative !== $bIsNative) {
            return $bIsNative ? 'a' : 'b';
        }

        return $this->originalSide() ?? 'a';
    }

    /**
     * Map a language code to this match's side ('a' or 'b').
     */
    public function sideForLanguage(string $languageCode): ?string
    {
        if (($this->aEntity?->language?->code ?? null) === $languageCode) {
            return 'a';
        }

        if (($this->bEntity?->language?->code ?? null) === $languageCode) {
            return 'b';
        }

        return null;
    }

    /**
     * The entities keyed by side.
     *
     * @return array<string, Entity>
     */
    public function entitiesBySide(): array
    {
        return array_filter([
            'a' => $this->aEntity,
            'b' => $this->bEntity,
        ]);
    }
}
