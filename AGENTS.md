# AGENTS.md - Agent Coding Guidelines

This document provides guidelines for agents operating in this Laravel 12 bilingual language learning application.

## Docker Environment

This project runs in Docker. All Laravel/PHP/Composer/NPM commands must be executed inside the `ext_app_laravel` container.

### Running Commands in Docker
```bash
# Execute any command in the Laravel container
docker exec ext_app_laravel [command]

# Examples:
docker exec ext_app_laravel php artisan migrate
docker exec ext_app_laravel composer install
docker exec ext_app_laravel npm run build
```

## Commands

### Development
```bash
# Start all services (server, queue, logs, vite) - run in container
docker exec ext_app_laravel composer run dev

# Build frontend assets
docker exec ext_app_laravel npm run build

# Watch frontend assets
docker exec ext_app_laravel npm run dev
```

### Code Formatting (Required before committing)
```bash
docker exec ext_app_laravel vendor/bin/pint --dirty
```

### Testing
```bash
# Run all tests
docker exec ext_app_laravel composer run test
# OR
docker exec ext_app_laravel php artisan test

# Run specific test file
docker exec ext_app_laravel php artisan test tests/Feature/ExampleTest.php

# Filter by test name (recommended after changes)
docker exec ext_app_laravel php artisan test --filter=testName

# Run unit tests only
docker exec ext_app_laravel php artisan test --testsuite=Unit

# Run feature tests only
docker exec ext_app_laravel php artisan test --testsuite=Feature
```

### Database
```bash
# Run migrations
docker exec ext_app_laravel php artisan migrate

# Fresh migration with seeding
docker exec ext_app_laravel php artisan migrate:fresh --seed
```

### Laravel Artisan
```bash
# List all available commands
docker exec ext_app_laravel php artisan list

# Get help on specific command
docker exec ext_app_laravel php artisan command-name --help
```

---

## Code Style Guidelines

### General
- Follow existing code conventions - check sibling files before writing new code
- Use descriptive names for variables and methods (e.g., `isRegisteredForDiscounts`, not `discount()`)
- Use PHP 8.4 with constructor property promotion
- Always use curly braces for control structures, even single-line ones

### Type Declarations
- Always use explicit return type declarations for methods and functions
- Use appropriate PHP type hints for method parameters
- Prefer nullable types with `?Type` syntax

```php
// Good
protected function isAccessible(User $user, ?string $path = null): bool
{
    return true;
}
```

### Imports
- Use explicit imports for classes used more than once
- Group imports: core Laravel, third-party, local Application
- Use `use function` for helper functions

### Naming Conventions
- Classes: PascalCase (e.g., `BilingualsController`)
- Methods: camelCase
- Variables: camelCase
- Database columns: snake_case
- Enum keys: TitleCase (e.g., `FavoritePerson`)

### Controllers & Validation
- Always create Form Request classes in `app/Http/Requests/` for validation
- Include both validation rules and custom error messages
- Use named routes with `route()` helper

### Models & Database
- Use Eloquent relationships with return type hints
- Prefer `Model::query()` over raw `DB::` queries
- Use eager loading to prevent N+1 queries
- Casts should be in a `casts()` method on models

### Laravel 12 Specific
- No middleware directory - register in `bootstrap/app.php`
- No Console Kernel - commands auto-register in `app/Console/Commands/`
- Configuration via `config()` - never use `env()` directly outside config files

### Testing (Pest)
- All tests use Pest framework in `tests/Feature` and `tests/Unit`
- Use specific assertion methods (`assertForbidden`, `assertNotFound`) instead of `assertStatus(403)`
- Use datasets for tests with duplicated data
- Mock with `Pest\Laravel\mock` using `use function Pest\Laravel\mock;`

### Livewire
- Components use `App\Livewire` namespace
- Use `$this->dispatch()` for events (not `emit`)
- Single root element required
- Use `wire:loading` and `wire:dirty` for loading states

### Filament
- Resources in `app/Filament/Resources/`
- Pages auto-generated in resource's `Pages/` directory
- Use `relationship()` for select options

### Frontend
- Tailwind CSS 4 for styling
- Alpine.js 3 for interactivity
- Use `wire:model.live` for real-time updates
- Use gap utilities for spacing, not margins

---

## Architecture Notes

### AI Provider System
Located in `app/Classes/`: `Gemini`, `HuggingFace`, `OpenRouter`, `Groq`, `Cohere`, `Perplexity`
- Each extends `AiProvider` base class
- API keys via `.env` configuration

### Key Features
- Bilinguals Simulator: `/bilinguals/{lang1}/{lang2}/simulator`
- Crossword Generator: `/crossword`
- Reader: `/reader`

### Text Files
Reading materials stored in `public/texts/simulator/` organized by language/level.

---

## Important Notes

- Run `docker exec ext_app_laravel vendor/bin/pint --dirty` before committing
- Every change must be tested - write or update tests
- All Laravel/PHP/Composer/NPM commands must run inside Docker container `ext_app_laravel`
- Use `search-docs` tool for Laravel ecosystem documentation
- Use `tinker` tool for debugging PHP code
- Use `database-query` tool for reading database directly
