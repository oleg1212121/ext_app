<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
     * The user's settings row (one per user), holding their preferences.
     */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    /**
     * The language the user is a native speaker of, or null if unset.
     */
    public function nativeLanguage(): ?Language
    {
        return $this->settings?->nativeLanguage;
    }

    /**
     * The language the web UI renders in for this user: their interface
     * language, falling back to their native language, then to English.
     * Only interface-enabled languages qualify.
     */
    public function resolvedInterfaceLocale(): string
    {
        $settings = $this->settings()->with(['interfaceLanguage', 'nativeLanguage'])->first();

        foreach ([$settings?->interfaceLanguage, $settings?->nativeLanguage] as $language) {
            if ($language?->is_interface_enabled) {
                return $language->code;
            }
        }

        return 'en';
    }

    /**
     * Restricted entities this user may read via an access grant.
     */
    public function grantedEntities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'entity_user')
            ->withPivot('similarity')
            ->withTimestamps();
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

    /**
     * Whether the user can actually use AI: they have stored a User key for at
     * least one provider that is admin-enabled AND has at least one enabled,
     * unexpired model. This mirrors the predicate AIModelResolver::
     * getGroupedModels() applies, so it agrees with whether the simulator's
     * model picker would be non-empty.
     */
    public function canUseAi(): bool
    {
        return $this->userApiKeys()
            ->whereHas('aiProvider', fn (Builder $query): Builder => $query->enabled())
            ->whereHas('aiProvider.aiModels', fn (Builder $query): Builder => $query->enabled()->unexpired())
            ->exists();
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
