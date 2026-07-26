<?php

namespace App\Console\Commands;

use App\Classes\WikiBundle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Regenerates the machine-owned concepts in wiki/reference/ (routes, models,
 * artisan commands) from live code. Hand-authored concepts are never touched.
 */
class WikiSync extends Command
{
    protected $signature = 'wiki:sync {--path= : Override the wiki bundle path}';

    protected $description = 'Regenerate auto-generated OKF wiki concepts (reference/) from live code';

    public function handle(): int
    {
        $root = WikiBundle::resolvePath($this->option('path'));

        if ($root === null) {
            $this->error('Wiki bundle not found. Expected repo-root wiki/ or /var/repo/wiki.');

            return self::FAILURE;
        }

        $this->info("Syncing wiki reference docs in {$root}");

        $written = [
            $this->syncRoutes($root),
            $this->syncModels($root),
            $this->syncCommands($root),
        ];

        foreach ($written as $file) {
            $this->line("  wrote {$file}");
        }

        return self::SUCCESS;
    }

    private function syncRoutes(string $root): string
    {
        $public = [];
        $authenticated = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            $isAppAction = str_starts_with($action, 'App\\') && ! str_starts_with($action, 'App\\Filament\\');

            if (! $isAppAction && ! in_array($route->uri(), ['/', 'dashboard'], true)) {
                continue; // vendor-registered (Filament, Livewire, Boost, Debugbar) or framework closures
            }

            $middleware = $route->gatherMiddleware();
            $methods = implode('|', array_diff($route->methods(), ['HEAD']));

            $row = [
                'methods' => $methods,
                'uri' => '/'.ltrim($route->uri(), '/'),
                'name' => $route->getName() ?? '—',
                'action' => $isAppAction ? str_replace('App\\Http\\Controllers\\', '', $action) : 'Closure',
                'middleware' => implode(', ', array_intersect($middleware, ['auth', 'verified', 'guest'])) ?: '—',
            ];

            if (in_array('auth', $middleware, true)) {
                $authenticated[] = $row;
            } else {
                $public[] = $row;
            }
        }

        $sort = fn (array $a, array $b) => $a['uri'] <=> $b['uri'];
        usort($public, $sort);
        usort($authenticated, $sort);

        $body = "# Web Routes\n\n"
            .'HTTP routes registered by `routes/web.php` + `routes/auth.php`. '
            ."Vendor routes (Filament `/admin`, Livewire, Boost MCP) are excluded.\n\n"
            ."## Public\n\n"
            .$this->routesTable($public)."\n\n"
            ."## Authenticated (`auth` middleware)\n\n"
            .$this->routesTable($authenticated)."\n";

        return $this->writeConcept($root, 'web-routes.md', 'Web Routes', 'All application HTTP routes with names, middleware, and controllers.', ['reference', 'routes', 'auto-generated'], $body);
    }

    /**
     * @param  array<int, array{methods: string, uri: string, name: string, action: string, middleware: string}>  $rows
     */
    private function routesTable(array $rows): string
    {
        $table = "| Method | URI | Name | Action | Middleware |\n"
            ."|--------|-----|------|--------|------------|\n";

        foreach ($rows as $row) {
            $table .= "| {$row['methods']} | `{$row['uri']}` | `{$row['name']}` | {$row['action']} | {$row['middleware']} |\n";
        }

        return $table;
    }

    private function syncModels(string $root): string
    {
        $rows = [];

        foreach (File::glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

            if (! class_exists($class)) {
                continue;
            }

            try {
                $model = new $class;
                $casts = array_filter(
                    $model->getCasts(),
                    fn (string $type, string $attribute) => $attribute !== 'id',
                    ARRAY_FILTER_USE_BOTH
                );

                $rows[] = [
                    'model' => $class,
                    'table' => $model->getTable(),
                    'fillable' => $model->getFillable() ? implode(', ', array_map(fn (string $f) => "`{$f}`", $model->getFillable())) : '—',
                    'casts' => $casts ? implode(', ', array_map(fn (string $t, string $a) => "`{$a}`: {$t}", $casts, array_keys($casts))) : '—',
                ];
            } catch (\Throwable $e) {
                $rows[] = ['model' => $class, 'table' => '⚠ '.$e->getMessage(), 'fillable' => '—', 'casts' => '—'];
            }
        }

        usort($rows, fn (array $a, array $b) => $a['table'] <=> $b['table']);

        $body = "# Models\n\n"
            .'All Eloquent models in `app/Models/`, sorted by table. Table domains are explained in '
            ."[Schema Overview](../database/schema-overview.md).\n\n"
            ."| Table | Model | Fillable | Casts |\n"
            ."|-------|-------|----------|-------|\n";

        foreach ($rows as $row) {
            $body .= "| `{$row['table']}` | {$row['model']} | {$row['fillable']} | {$row['casts']} |\n";
        }

        return $this->writeConcept($root, 'models.md', 'Models', 'Eloquent model to table mapping with fillable attributes and casts.', ['reference', 'models', 'auto-generated'], $body);
    }

    private function syncCommands(string $root): string
    {
        $rows = [];

        foreach (Artisan::all() as $command) {
            if (! str_starts_with(get_class($command), 'App\\Console\\Commands')) {
                continue;
            }

            $rows[] = [
                'name' => $command->getName(),
                'synopsis' => $command->getSynopsis(),
                'description' => $command->getDescription() ?: '—',
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['name'] <=> $b['name']);

        $body = "# Artisan Commands\n\n"
            .'Application commands from `app/Console/Commands/` (auto-registered). '
            ."Scheduling lives in `routes/console.php` (`entity-orders:rebalance` runs daily).\n\n";

        foreach ($rows as $row) {
            $body .= "## `{$row['name']}`\n\n{$row['description']}\n\n```\n{$row['synopsis']}\n```\n\n";
        }

        return $this->writeConcept($root, 'commands.md', 'Artisan Commands', 'Application console commands with signatures.', ['reference', 'commands', 'auto-generated'], $body);
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function writeConcept(string $root, string $filename, string $title, string $description, array $tags, string $body): string
    {
        $generatedAt = now()->format('c');
        $tagsInline = implode(', ', $tags);

        $content = <<<MD
        ---
        type: Reference
        title: {$title}
        description: {$description}
        tags: [{$tagsInline}]
        status: stable
        generated: { by: process:wiki-sync, at: {$generatedAt} }
        ---

        > Machine-owned file, regenerated by `php artisan wiki:sync`. Do not hand-edit.

        {$body}
        MD;

        $path = $root.'/reference/'.$filename;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        return 'reference/'.$filename;
    }
}
