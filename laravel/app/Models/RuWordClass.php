<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuWordClass extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'title', 'description'];

    public function words(): HasMany
    {
        return $this->hasMany(RuWord::class, 'ru_word_class_id');
    }
}
