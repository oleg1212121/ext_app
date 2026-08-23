<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class User extends Authenticatable implements FilamentUser
{
    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_approved',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_approved' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return Gate::forUser($this)->allows('accessAdminPanel');
    }

    /**
     * The user's stored API keys, one per provider.
     */
    public function userApiKeys(): HasMany
    {
        return $this->hasMany(UserApiKey::class);
    }

    /**
     * The stored key for a provider, addressed by its provider slug (e.g.
     * 'openrouter'), or null when the user has not added one.
     */
    public function apiKeyForProvider(string $providerKey): ?UserApiKey
    {
        return $this->userApiKeys()
            ->forProviderKey($providerKey)
            ->first();
    }

    public function hasApiKeyForProvider(string $providerKey): bool
    {
        return $this->apiKeyForProvider($providerKey) !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * An admin may never weaken their own access, and at least one approved
     * admin must always remain. These invariants are enforced at the model
     * level so they hold regardless of the entry point (Filament form, table
     * action, bulk action, or any future non-Filament path).
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if (! $user->exists) {
                return;
            }

            $wasApprovedAdmin = (string) $user->getOriginal('role') === self::ROLE_ADMIN
                && (bool) $user->getOriginal('is_approved') === true;

            if (! $wasApprovedAdmin) {
                return;
            }

            if ($user->isAdmin() && $user->is_approved) {
                return;
            }

            if ($user->id === auth()->id()) {
                static::failSelfDemotion();
            }

            if (! static::hasOtherApprovedAdmins($user)) {
                static::failLastApprovedAdmin();
            }
        });

        static::deleting(function (User $user) {
            if (! $user->isAdmin() || ! $user->is_approved) {
                return;
            }

            if ($user->id === auth()->id()) {
                static::failSelfDemotion();
            }

            if (! static::hasOtherApprovedAdmins($user)) {
                static::failLastApprovedAdmin();
            }
        });
    }

    protected static function hasOtherApprovedAdmins(User $user): bool
    {
        return static::query()
            ->whereKeyNot($user->getKey())
            ->where('role', self::ROLE_ADMIN)
            ->where('is_approved', true)
            ->exists();
    }

    public static function isSoleApprovedAdmin(User $user): bool
    {
        if (! $user->isAdmin() || ! $user->is_approved) {
            return false;
        }

        return ! static::hasOtherApprovedAdmins($user);
    }

    protected static function failSelfDemotion(): void
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('role', 'You cannot remove your own admin access.');

        throw new ValidationException($validator);
    }

    protected static function failLastApprovedAdmin(): void
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('role', 'At least one approved admin must always exist.');

        throw new ValidationException($validator);
    }
}
