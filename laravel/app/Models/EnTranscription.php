<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnTranscription extends Model
{
    use HasFactory;

    protected $fillable = ['transcription', 'en_word_id', 'en_transcription_type_id'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(EnWord::class, 'en_word_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EnTranscriptionType::class, 'en_transcription_type_id');
    }
}
