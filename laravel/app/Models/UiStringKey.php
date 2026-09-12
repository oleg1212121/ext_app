<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UiStringKey extends Model
{
    protected $fillable = [
        'key',
        'group',
    ];

    public function strings(): HasMany
    {
        return $this->hasMany(UiString::class);
    }

    protected static function booted(): void
    {
        // The group is always the key's first segment — never stored out of sync.
        static::saving(function (self $stringKey) {
            $stringKey->group = explode('.', $stringKey->key)[0];
        });
    }
}
