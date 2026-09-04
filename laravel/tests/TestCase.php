<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Swap Vite for a no-op so Blade layouts render without a built manifest.
     *
     * CI never runs `npm run build` and `public/build/` is gitignored, so any
     * `@vite` call would throw ViteManifestNotFoundException and turn every
     * page-rendering test into a 500. Use withVite() inside a test that needs
     * real asset resolution (requires a built manifest).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Boot the application and refuse to run against anything but the test database.
     *
     * The check must live here (not setUp): RefreshDatabase wipes the resolved
     * database inside parent::setUp(), so validating later would be too late.
     * createApplication() runs after config resolution — which also catches a
     * stale config cache pointing tests at the real database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'testing' || $database !== 'ext_app_test') {
            throw new RuntimeException(
                "Tests must run against the 'testing' connection (database 'ext_app_test'), ".
                "but resolved connection is '{$connection}' with database '{$database}'. ".
                "Run 'php artisan config:clear' — a cached config may be pointing tests at the real database."
            );
        }

        return $app;
    }
}
