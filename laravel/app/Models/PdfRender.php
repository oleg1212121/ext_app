<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cached server-rendered book PDF per subject (an entity or an entity
 * match), keyed by a partial unique index per FK and
 * updated in place: the row's content_hash digests every render input, the
 * file lives at a fixed `local`-disk path shared by nothing else.
 */
class PdfRender extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_id',
        'entity_match_id',
        'content_hash',
        'path',
        'size_bytes',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'entity_match_id' => 'integer',
            'size_bytes' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function entityMatch(): BelongsTo
    {
        return $this->belongsTo(EntityMatch::class);
    }
}
