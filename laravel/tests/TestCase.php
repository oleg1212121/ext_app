<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
