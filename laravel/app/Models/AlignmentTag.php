<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AlignmentTag extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name'];

    public function alignments(): BelongsToMany
    {
        return $this->belongsToMany(EntityMatch::class, 'tagged_alignments')->withTimestamps();
    }
}
