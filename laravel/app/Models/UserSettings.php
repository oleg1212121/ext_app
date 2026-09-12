<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSettings extends Model
{
    protected $fillable = [
        'user_id',
        'native_language_id',
        'interface_language_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nativeLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'native_language_id');
    }

    public function interfaceLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'interface_language_id');
    }
}
