<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWord extends Model
{
    protected $table = 'user_word';

    public const FAMILIARITY_MAX = 100;

    public const FAMILIARITY_MIN = 0;

    public const READ_STEP = 1;

    public const LOOKUP_PENALTY = 2;

    public const CROSSWORD_BONUS = 5;

    public const KIND_READ = 'read';

    public const KIND_LOOKUP = 'lookup';

    protected $fillable = ['user_id', 'word_id', 'familiarity'];

    protected function casts(): array
    {
        return [
            'familiarity' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
