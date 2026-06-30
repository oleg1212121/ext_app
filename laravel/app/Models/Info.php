<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Info extends Model
{
    use HasFactory;

    protected $fillable = ['word', 'info'];

    protected function info(): Attribute
    {
        return Attribute::make(
            get: function (string $value) {
                return json_decode($value, true);
            },
        );
    }

    protected function casts(): array
    {
        return [
            'info' => 'json:unicode',
        ];
    }
}
