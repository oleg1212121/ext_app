<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Word extends Model
{
    protected $fillable = [
        'language_id',
        'word',
        'l_word',
        'frequency',
        'word_class_id',
        'translations',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => 'decimal:8',
            'translations' => 'array',
        ];
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function wordClass(): BelongsTo
    {
        return $this->belongsTo(WordClass::class);
    }

    public function definitions(): HasMany
    {
        return $this->hasMany(Definition::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function etymologies(): HasMany
    {
        return $this->hasMany(Etymology::class);
    }

    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }

    public function examples(): HasMany
    {
        return $this->hasMany(Example::class);
    }

    public function pronunciations(): HasMany
    {
        return $this->hasMany(Pronunciation::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'word_tags');
    }

    /**
     * Words this word translates to (this word is the source).
     */
    public function translations(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'word_translations', 'from_word_id', 'to_word_id')
            ->withTimestamps();
    }

    /**
     * Words that translate to this word (this word is the target).
     */
    public function reverseTranslations(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'word_translations', 'to_word_id', 'from_word_id')
            ->withTimestamps();
    }
}
