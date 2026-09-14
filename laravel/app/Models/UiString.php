<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UiString extends Model
{
    protected $fillable = [
        'ui_string_key_id',
        'language_id',
        'text',
    ];

    public function key(): BelongsTo
    {
        return $this->belongsTo(UiStringKey::class, 'ui_string_key_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
