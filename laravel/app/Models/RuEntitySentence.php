<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuEntitySentence extends Model
{
    use HasFactory;

    protected $fillable = ['ru_entity_id', 'sentence_type_id', 'content', 'order'];

    protected $casts = [
        'order' => 'integer',
    ];

    private ?array $meaningMatchIdsBeforeDelete = null;

    public function entity(): BelongsTo
    {
        return $this->belongsTo(RuEntity::class, 'ru_entity_id');
    }

    public function sentenceType(): BelongsTo
    {
        return $this->belongsTo(SentenceType::class, 'sentence_type_id');
    }

    public function ruMeaningMatches(): HasMany
    {
        return $this->hasMany(RuSentenceMeaningMatch::class, 'ru_entity_sentence_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (RuEntitySentence $sentence): void {
            $sentence->meaningMatchIdsBeforeDelete = $sentence->ruMeaningMatches()
                ->pluck('en_ru_meaning_match_id')
                ->unique()
                ->all();
        });

        static::deleted(function (RuEntitySentence $sentence): void {
            foreach ($sentence->meaningMatchIdsBeforeDelete ?? [] as $meaningMatchId) {
                $meaningMatch = EnRuMeaningMatch::find($meaningMatchId);

                if (! $meaningMatch) {
                    continue;
                }

                if ($meaningMatch->enSentenceMatches()->count() === 0 || $meaningMatch->ruSentenceMatches()->count() === 0) {
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
