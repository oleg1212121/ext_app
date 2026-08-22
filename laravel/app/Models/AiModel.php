<?php

namespace App\Models;

use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $provider
 * @property string $external_id
 * @property string|null $canonical_slug
 * @property string $name
 * @property string|null $description
 * @property int|null $context_length
 * @property string|null $pricing_prompt
 * @property string|null $pricing_completion
 * @property array|null $reasoning
 * @property Carbon|null $expiration_date
 * @property Carbon|null $api_created_at
 * @property bool $is_enabled
 */
class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'external_id',
        'canonical_slug',
        'name',
        'description',
        'context_length',
        'pricing_prompt',
        'pricing_completion',
        'reasoning',
        'expiration_date',
        'api_created_at',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'reasoning' => 'array',
            'context_length' => 'integer',
            'expiration_date' => 'date',
            'api_created_at' => 'datetime',
            'is_enabled' => 'boolean',
        ];
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('expiration_date')
                ->orWhere('expiration_date', '>', now());
        });
    }

    /**
     * The label shown in the simulator's model picker, reconstructing the
     * legacy "Name ($X.XX/$Y.YY)" format. Pricing is per-million-tokens.
     */
    public function displayLabel(): string
    {
        $prompt = $this->pricing_prompt;
        $completion = $this->pricing_completion;

        if ($prompt === null || $completion === null || $prompt < 0 || $completion < 0) {
            return "{$this->name} (n/a)";
        }

        if ($prompt == 0 && $completion == 0) {
            return $this->name.' (free)';
        }

        $promptStr = number_format((float) $prompt * 1_000_000, 2);
        $completionStr = number_format((float) $completion * 1_000_000, 2);

        return "{$this->name} (\${$promptStr}/\${$completionStr})";
    }
}
