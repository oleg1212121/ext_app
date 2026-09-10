<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcription extends Model
{
    protected $fillable = ['word_id', 'transcription_type_id', 'transcription'];

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }

    public function transcriptionType(): BelongsTo
    {
        return $this->belongsTo(TranscriptionType::class);
    }
}
