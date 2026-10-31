<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntitySentenceWord extends Model
{
    public $timestamps = false;

    protected $fillable = ['entity_id', 'entity_sentence_id', 'position', 'start', 'end', 'l_word', 'token'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'start' => 'integer',
            'end' => 'integer',
        ];
    }
}
