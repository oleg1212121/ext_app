<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookTextFile extends Model
{
    protected $fillable = ['name', 'path', 'book_id', 'lang'];
}
