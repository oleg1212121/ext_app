<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Definition extends Model
{
    use HasFactory;

    public $table = 'definitions';

    public $fillable = ['pos', 'word', 'definition'];
}
