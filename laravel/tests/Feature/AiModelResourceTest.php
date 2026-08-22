<?php

use App\Filament\Resources\AiModelResource\Pages\ListAiModels;
use App\Jobs\SyncAiModelsJob;
use App\Models\AiModel;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('lists AI models for an authenticated admin', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $model = AiModel::factory()->enabled()->create([
        'provider' => 'openrouter',
        'external_id' => 'openai/gpt-4o-mini',
        'name' => 'OpenAI: GPT-4o-mini',
    ]);

    Livewire::test(ListAiModels::class)
        ->assertCanSeeTableRecords([$model]);
});

it('shows both enabled and disabled models so they can be managed', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $disabled = AiModel::factory()->create([
        'provider' => 'openrouter',
        'external_id' => 'disabled/model',
        'name' => 'Disabled Model',
        'is_enabled' => false,
    ]);

    Livewire::test(ListAiModels::class)
        ->assertCanSeeTableRecords([$disabled]);
});

it('toggles is_enabled immediately without a confirmation modal', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $model = AiModel::factory()->enabled()->create([
        'provider' => 'openrouter',
        'external_id' => 'toggle/model',
        'name' => 'Toggle Model',
    ]);

    Livewire::test(ListAiModels::class)
        ->callTableAction('toggleEnabled', $model);

    expect($model->refresh()->is_enabled)->toBeFalse();

    Livewire::test(ListAiModels::class)
        ->callTableAction('toggleEnabled', $model);

    expect($model->refresh()->is_enabled)->toBeTrue();
});

it('dispatches the sync job and shows a queued notification', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Queue::fake();

    Livewire::test(ListAiModels::class)
        ->callAction('sync')
        ->assertNotified();

    Queue::assertPushed(SyncAiModelsJob::class);
});

it('does not dispatch a second sync while one is already in progress', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Queue::fake();

    // Simulate an already-running sync by holding the concurrency lock.
    Cache::lock(SyncAiModelsJob::LOCK_KEY, SyncAiModelsJob::LOCK_TTL)->get();

    Livewire::test(ListAiModels::class)
        ->callAction('sync')
        ->assertNotified();

    Queue::assertNotPushed(SyncAiModelsJob::class);
});

it('sorts by total pricing (prompt + completion)', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $free = AiModel::factory()->free()->create(['name' => 'Free Model']);
    $cheap = AiModel::factory()->create([
        'name' => 'Cheap',
        'pricing_prompt' => '0.0000001',
        'pricing_completion' => '0.0000001',
    ]);
    $expensive = AiModel::factory()->create([
        'name' => 'Expensive',
        'pricing_prompt' => '0.000001',
        'pricing_completion' => '0.000002',
    ]);

    Livewire::test(ListAiModels::class)
        ->sortTable('pricing', 'asc')
        ->assertCanSeeTableRecords([$free, $cheap, $expensive], inOrder: true)
        ->sortTable('pricing', 'desc')
        ->assertCanSeeTableRecords([$expensive, $cheap, $free], inOrder: true);
});

it('keeps name and is_enabled non-toggleable', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $table = Livewire::test(ListAiModels::class)->instance()->getTable();

    expect($table->getColumn('name')->isToggleable())->toBeFalse();
    expect($table->getColumn('is_enabled')->isToggleable())->toBeFalse();
});

it('makes optional columns toggleable', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $table = Livewire::test(ListAiModels::class)->instance()->getTable();

    foreach (['provider', 'external_id', 'context_length', 'pricing', 'expiration_date', 'api_created_at'] as $name) {
        expect($table->getColumn($name)->isToggleable())->toBeTrue();
    }

    Livewire::test(ListAiModels::class)
        ->assertTableColumnVisible('provider');
});

it('shows n/a for unavailable pricing (negative sentinel)', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $model = AiModel::factory()->create([
        'name' => 'Auto Router (Beta)',
        'pricing_prompt' => '-1',
        'pricing_completion' => '-1',
    ]);

    Livewire::test(ListAiModels::class)
        ->assertTableColumnFormattedStateSet('pricing', 'n/a', $model);
});

it('preserves is_enabled for existing enabled models when syncing', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    AiModel::factory()->enabled()->create([
        'provider' => 'openrouter',
        'external_id' => 'openai/gpt-4o-mini',
        'name' => 'OpenAI: GPT-4o-mini',
        'pricing_prompt' => '0.00000015',
        'pricing_completion' => '0.00000060',
    ]);

    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                [
                    'id' => 'openai/gpt-4o-mini',
                    'name' => 'OpenAI: GPT-4o-mini',
                    'pricing' => ['prompt' => '0.00000015', 'completion' => '0.00000060'],
                ],
                [
                    'id' => 'openai/gpt-4o-new',
                    'name' => 'OpenAI: GPT-4o New',
                    'pricing' => ['prompt' => '0.00000050', 'completion' => '0.00000150'],
                ],
            ],
            'total_count' => 2,
            'links' => ['next' => null],
        ]),
    ]);

    // QUEUE_CONNECTION=sync runs the job inline so its side effects are observable.
    Livewire::test(ListAiModels::class)
        ->callAction('sync')
        ->assertNotified();

    expect(AiModel::where('external_id', 'openai/gpt-4o-mini')->value('is_enabled'))->toBeTrue();
    expect(AiModel::where('external_id', 'openai/gpt-4o-new')->value('is_enabled'))->toBeFalse();
});
