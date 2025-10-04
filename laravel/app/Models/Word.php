<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Word extends Model
{
    public $table = 'words';

    public $fillable = [
        'word', 'knowledge', 'for_crossword'
    ];

    public function definitions(): HasMany
    {
        return $this->HasMany(Definition::class, 'word', 'word');
    }


    public function forms(): HasMany
    {
        return $this->HasMany(Form::class);
    }

    public function modernDefinitions(): HasMany
    {
        return $this->definitions()->where('is_obsolete', false);
    }

    public function crosswordDefinitions(): HasMany
    {
        return $this->modernDefinitions()->where('words.knowledge', '<', 60);
    }

    public function translations(): HasMany
    {
        return $this->HasMany(Translation::class, 'word', 'word');
    }

    public function books() : BelongsToMany
    {
        return $this->belongsToMany(Book::class);
    }
}
