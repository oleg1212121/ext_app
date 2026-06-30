<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnWord extends Model
{
    use HasFactory;

    protected $fillable = ['word', 'l_word', 'frequency', 'en_word_class_id', 'translations'];

    protected $casts = [
        'frequency' => 'decimal:8',
        'translations' => 'array',
    ];

    public function wordClass(): BelongsTo
    {
        return $this->belongsTo(EnWordClass::class, 'en_word_class_id');
    }

    public function definitions(): HasMany
    {
        return $this->hasMany(EnDefinition::class, 'en_word_id');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(EnForm::class, 'en_word_id');
    }

    public function etymologies(): HasMany
    {
        return $this->hasMany(EnEtymology::class, 'en_word_id');
    }

    public function transcriptions(): HasMany
    {
        return $this->hasMany(EnTranscription::class, 'en_word_id');
    }

    public function examples(): HasMany
    {
        return $this->hasMany(EnExample::class, 'en_word_id');
    }

    public function pronunciations(): HasMany
    {
        return $this->hasMany(EnPronunciation::class, 'en_word_id');
    }

    public function translations()
    {
        return $this->belongsToMany(RuWord::class, 'en_ru_translations', 'en_word_id', 'ru_word_id');
    }
}
