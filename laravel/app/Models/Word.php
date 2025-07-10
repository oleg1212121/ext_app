<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
 

class Word extends Model
{
    
    public $fillable = ['word'];

    public function definitions(): HasMany
    {
        return $this->HasMany(Definition::class, 'word', 'word');    
    }

    public function translations(): HasMany
    {
        return $this->HasMany(Translation::class, 'word', 'word');    
    }

    // public function infos()
    // {
    //     return $this->hasMany(Info::class, 'word', 'word');
    // }
}
