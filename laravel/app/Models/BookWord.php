<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookWord extends Model
{
    public $timestamps = false;
    protected $table = 'book_word';
    protected $fillable = ['book_id', 'word_id', 'count', 'is_solved'];
}
