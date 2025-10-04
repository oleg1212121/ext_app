<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Book extends Model
{
    public $fillable = ['name', 'description'];

    public function words() : BelongsToMany
    {
        return $this->belongsToMany(Word::class)->withPivot(['count']);
    }
}
