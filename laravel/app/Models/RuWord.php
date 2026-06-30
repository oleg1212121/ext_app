<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuWord extends Model
{
    use HasFactory;

    protected $fillable = ['word', 'l_word', 'frequency', 'ru_word_class_id', 'translations'];

    protected $casts = [
        'frequency' => 'decimal:8',
        'translations' => 'array',
    ];

    public function wordClass(): BelongsTo
    {
        return $this->belongsTo(RuWordClass::class, 'ru_word_class_id');
    }

    public function definitions(): HasMany
    {
        return $this->hasMany(RuDefinition::class, 'ru_word_id');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(RuForm::class, 'ru_word_id');
    }

    public function etymologies(): HasMany
    {
        return $this->hasMany(RuEtymology::class, 'ru_word_id');
    }

    public function transcriptions(): HasMany
    {
        return $this->hasMany(RuTranscription::class, 'ru_word_id');
    }

    public function examples(): HasMany
    {
        return $this->hasMany(RuExample::class, 'ru_word_id');
    }

    public function pronunciations(): HasMany
    {
        return $this->hasMany(RuPronunciation::class, 'ru_word_id');
    }

    public function translations()
    {
        return $this->belongsToMany(EnWord::class, 'ru_en_translations', 'ru_word_id', 'en_word_id');
    }
}
