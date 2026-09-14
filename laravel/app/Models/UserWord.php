<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWord extends Model
{
    protected $table = 'user_word';

    public const STATUS_LEARNING = 'learning';
    public const STATUS_SOLVED = 'solved';
    public const STATUS_KNOWN = 'known';

    protected $fillable = ['user_id', 'word_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => 'string',
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
