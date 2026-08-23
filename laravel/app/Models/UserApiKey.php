<?php

namespace App\Models;

use Database\Factories\UserApiKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $ai_provider_id
 * @property string $api_key
 * @property-read User $user
 * @property-read AiProvider $aiProvider
 */
class UserApiKey extends Model
{
    /** @use HasFactory<UserApiKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ai_provider_id',
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
        ];
    }

    /**
     * Return a masked preview of the decrypted API key, showing only the
     * first four and last four characters separated by a fixed-length
     * placeholder. Falls back to a fully masked value for short keys.
     */
    public function masked(): string
    {
        $key = $this->api_key;

        if (strlen($key) < 8) {
            return str_repeat('•', 4);
        }

        return substr($key, 0, 4).str_repeat('•', 4).substr($key, -4);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    public function scopeForProviderKey(Builder $query, string $providerKey): Builder
    {
        return $query->whereHas('aiProvider', function (Builder $query) use ($providerKey): void {
            $query->where('key', $providerKey);
        });
    }
}
