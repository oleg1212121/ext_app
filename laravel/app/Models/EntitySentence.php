<?php

namespace App\Models;

use App\Classes\IllustrationStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntitySentence extends Model
{
    protected $fillable = ['entity_id', 'sentence_type_id', 'content', 'order', 'image_path', 'image_hash', 'image_width', 'image_height', 'image_mime', 'stressed_content', 'phrasal_verbs'];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'phrasal_verbs' => 'array',
        ];
    }

    private ?array $meaningMatchIdsBeforeDelete = null;

    /**
     * An illustration is a sentence carrying an uploaded image (ADR 0050);
     * its text content is the optional caption. The image_path non-null
     * marker — not the sentence type — decides, so the aligner and the
     * readers can filter with a plain column check.
     */
    public function isIllustration(): bool
    {
        return $this->image_path !== null;
    }

    public function scopeWithImage(Builder $query): Builder
    {
        return $query->whereNotNull('image_path');
    }

    public function scopeWithoutImage(Builder $query): Builder
    {
        return $query->whereNull('image_path');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function sentenceType(): BelongsTo
    {
        return $this->belongsTo(SentenceType::class, 'sentence_type_id');
    }

    public function meaningJunctions(): HasMany
    {
        return $this->hasMany(SentenceMeaningMatch::class, 'entity_sentence_id');
    }

    protected static function booted(): void
    {
        $touchParent = function (EntitySentence $sentence): void {
            if ($sentence->entity_id !== null) {
                Entity::touchSentencesFor($sentence->entity_id);
            }
        };

        static::created($touchParent);
        static::updated($touchParent);
        static::deleted($touchParent);

        static::deleting(function (EntitySentence $sentence): void {
            $sentence->meaningMatchIdsBeforeDelete = $sentence->meaningJunctions()
                ->pluck('meaning_match_id')
                ->unique()
                ->all();
        });

        static::deleted(function (EntitySentence $sentence): void {
            // Illustration files are content-hash named, so identical uploads
            // share one file — removal is reference-counted, not per-upload.
            if ($sentence->image_path !== null) {
                app(IllustrationStorage::class)->releaseIfOrphaned($sentence->image_path);
            }

            foreach ($sentence->meaningMatchIdsBeforeDelete ?? [] as $meaningMatchId) {
                $meaningMatch = MeaningMatch::find($meaningMatchId);

                if (! $meaningMatch) {
                    continue;
                }

                if ($meaningMatch->sentenceMeaningMatches()->count() === 0) {
                    $entityMatch = $meaningMatch->entityMatch;
                    $meaningMatch->delete();

                    if ($entityMatch) {
                        $entityMatch->update(['linked_count' => $entityMatch->meaningMatches()->count()]);
                    }
                }
            }
        });
    }
}
