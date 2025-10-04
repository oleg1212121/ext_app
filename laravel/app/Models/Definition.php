<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Definition extends Model
{

    public $table = 'definitions';
    public $fillable = ['pos', 'word', 'definition'];
}
