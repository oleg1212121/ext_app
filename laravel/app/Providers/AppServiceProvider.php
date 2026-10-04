<?php

namespace App\Providers;

use App\Classes\AIModelResolver;
use App\Classes\PythonClient;
use App\Models\UiString;
use App\Models\UiStringKey;
use App\Models\User;
use App\Support\UiStrings;
use App\Translation\UiStringLoader;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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

        // The python transport seam (ADR 0061): config-resolved client, so
        // container-resolved jobs and services share one construction path.
        $this->app->bind(PythonClient::class, fn () => PythonClient::create());

        // Serve DB-backed UI strings to the translator in addition to lang files.
        $this->app->extend('translation.loader', fn ($loader, $app) => new UiStringLoader($app['files'], $app['path.lang']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin edits take effect on the next request: any UI string write
        // drops the per-locale cache maps the loader and Inertia share read.
        UiString::saved(fn () => UiStrings::flush());
        UiString::deleted(fn () => UiStrings::flush());
        UiStringKey::saved(fn () => UiStrings::flush());
        UiStringKey::deleted(fn () => UiStrings::flush());
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

        // Daemon queue workers only sweep cyclic garbage when the GC root
        // buffer overflows; each enrichment job leaves thousands of Eloquent
        // cycles, so an unswept worker climbs past the CLI memory_limit (128M)
        // and dies silently mid-job. Sweeping before every pop keeps the
        // daemon's memory flat for the daemon's whole life.
        Queue::looping(fn () => gc_collect_cycles());
    }
}
