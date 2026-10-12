<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WorkTag extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name'];

    public function works(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'tagged_works')->withTimestamps();
    }
}
