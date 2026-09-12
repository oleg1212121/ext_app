<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $fillable = [
        'code',
        'name',
        'native_name',
        'is_enabled',
        'sort_order',
        'is_interface_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
            'is_interface_enabled' => 'boolean',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeInterfaceEnabled(Builder $query): Builder
    {
        return $query->where('is_interface_enabled', true);
    }
}
