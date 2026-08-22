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
