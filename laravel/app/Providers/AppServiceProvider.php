<?php

namespace App\Providers;

use App\Classes\AIModelResolver;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the FormRequest validation closure and the
        // constructor-injected controller share one resolver instance —
        // getProvider() caches the provider and loadModels() runs once.
        $this->app->singleton(AIModelResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Before every gate/policy check, an approved admin passes automatically.
        // Returning null (non-admin or unapproved) lets the gate body or policy
        // decide, so abilities stay explicit for everyone else.
        Gate::before(function (?User $user) {
            if ($user === null) {
                return null;
            }

            if ($user->isAdmin() && $user->is_approved) {
                return true;
            }

            return null;
        });

        Gate::define('accessAdminPanel', function (?User $user) {
            return $user !== null
                && $user->isAdmin()
                && $user->is_approved;
        });
    }
}
