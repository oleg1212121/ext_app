<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuPronunciation extends Model
{
    use HasFactory;

    protected $fillable = ['path', 'ru_word_id', 'ru_transcription_type_id'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(RuWord::class, 'ru_word_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(RuTranscriptionType::class, 'ru_transcription_type_id');
    }
}
