<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedPhrase extends Model
{
    use HasFactory;

    public $fillable = ['phrase'];
}
