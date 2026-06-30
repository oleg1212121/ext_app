<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnTranscriptionType extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'title', 'description'];

    public function transcriptions(): HasMany
    {
        return $this->hasMany(EnTranscription::class, 'en_transcription_type_id');
    }
}
