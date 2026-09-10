<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntitySentence extends Model
{
    protected $fillable = ['entity_id', 'sentence_type_id', 'content', 'order'];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    private ?array $meaningMatchIdsBeforeDelete = null;

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
        static::deleting(function (EntitySentence $sentence): void {
            $sentence->meaningMatchIdsBeforeDelete = $sentence->meaningJunctions()
                ->pluck('meaning_match_id')
                ->unique()
                ->all();
        });

        static::deleted(function (EntitySentence $sentence): void {
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
