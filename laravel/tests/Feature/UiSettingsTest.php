<?php

use App\Http\Controllers\Bilinguals\SimulatorController;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;
use App\Models\UserSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function withSavedUiSettings(User $user, array $ui): UserSettings
{
    return UserSettings::query()->updateOrCreate(
        ['user_id' => $user->id],
        ['ui_settings' => $ui],
    );
}

test('guests cannot update ui settings', function () {
    $this->patch('/ui-settings', ['simulator' => ['font_size' => 30]])
        ->assertRedirect(route('login'));
});

test('authenticated user can save simulator settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', [
            'simulator' => [
                'font_size' => 30,
                'show_text' => true,
                'show_workplace' => false,
                'show_question' => true,
                'show_ai' => true,
                'model' => 'openrouter:cheap',
                'question' => 'My custom prompt.',
                'ai_panel_width' => 640,
                'workplace_height' => 200,
            ],
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $settings = $user->settings()->first();
    expect($settings)->not->toBeNull()
        ->and($settings->ui_settings['simulator']['font_size'])->toBe(30)
        ->and($settings->ui_settings['simulator']['question'])->toBe('My custom prompt.');
});

test('saving one section keeps the other section intact', function () {
    $user = User::factory()->create();
    withSavedUiSettings($user, [
        'simulator' => ['font_size' => 30],
    ]);

    $this->actingAs($user)
        ->patch('/ui-settings', ['reader' => ['font_size' => 24]])
        ->assertOk();

    $ui = $user->settings()->first()->ui_settings;
    expect($ui['simulator']['font_size'])->toBe(30)
        ->and($ui['reader']['font_size'])->toBe(24);
});

test('invalid values are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', ['simulator' => ['font_size' => 999]])
        ->assertInvalid('simulator.font_size');

    $this->actingAs($user)
        ->patch('/ui-settings', ['reader' => ['font_size' => 5]])
        ->assertInvalid('reader.font_size');
});

test('simulator page seeds props from saved ui settings', function () {
    $user = User::factory()->create();
    $match = createSimulatorMatch();
    withSavedUiSettings($user, [
        'simulator' => [
            'font_size' => 34,
            'show_text' => false,
            'show_workplace' => true,
            'show_question' => true,
            'show_ai' => false,
            'question' => 'My saved prompt.',
        ],
    ]);

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('fontSize', 34)
            ->where('showText', false)
            ->where('showQuestion', true)
            ->where('currentQuestion', 'My saved prompt.'));
});

test('simulator page falls back to defaults when nothing is saved', function () {
    $user = User::factory()->create();
    $match = createSimulatorMatch();

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('fontSize', 26)
            ->where('showText', true)
            ->where('showQuestion', false)
            // Nothing saved: currentQuestion is null and the client renders
            // the :base template for the currently-toggled sides.
            ->where('currentQuestion', null)
            ->where('questionTemplate', SimulatorController::DEFAULT_QUESTION));
});

test('simulator model choice no longer lives in ui settings', function () {
    $user = User::factory()->create();
    $match = createSimulatorMatch();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'name' => 'Cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    // Legacy leftover: the simulator picks its model from the user's
    // ai_model_id preference now, never from ui_settings.simulator.model.
    withSavedUiSettings($user, [
        'simulator' => ['model' => 'openrouter:cheap'],
    ]);

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('answerModel', null));
});

test('reader page seeds font size from saved ui settings', function () {
    $user = User::factory()->create();
    withSavedUiSettings($user, [
        'reader' => ['font_size' => 22],
    ]);
    $entity = createEntity('en', null, ['name' => 'Reader EN Entity']);

    $this->actingAs($user)
        ->get(route('reader.show', ['entityId' => $entity->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('fontSize', 22));
});

test('reader page clamps an out of range saved font size', function () {
    $user = User::factory()->create();
    withSavedUiSettings($user, [
        'reader' => ['font_size' => 99],
    ]);
    $entity = createEntity('en', null, ['name' => 'Reader EN Entity']);

    $this->actingAs($user)
        ->get(route('reader.show', ['entityId' => $entity->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('fontSize', 38));
});
