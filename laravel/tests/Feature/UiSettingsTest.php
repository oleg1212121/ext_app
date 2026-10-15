<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;
use App\Models\UserSettings;
use App\Support\PromptTemplates;
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

    $this->actingAs($user)
        ->patch('/ui-settings', ['simulator' => ['question' => str_repeat('a', 4001)]])
        ->assertInvalid('simulator.question');
});

test('authenticated user can save popup visibility settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', [
            'popup' => [
                'familiarity' => false,
                'explanation' => false,
                'frequency' => false,
            ],
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $popup = $user->settings()->first()->ui_settings['popup'];
    expect($popup['familiarity'])->toBeFalse()
        ->and($popup['explanation'])->toBeFalse()
        ->and($popup['frequency'])->toBeFalse();
});

test('saving the popup section keeps the other sections intact', function () {
    $user = User::factory()->create();
    withSavedUiSettings($user, [
        'simulator' => ['font_size' => 30],
        'reader' => ['highlight' => true],
    ]);

    $this->actingAs($user)
        ->patch('/ui-settings', ['popup' => ['definitions' => false]])
        ->assertOk();

    $ui = $user->settings()->first()->ui_settings;
    expect($ui['simulator']['font_size'])->toBe(30)
        ->and($ui['reader']['highlight'])->toBeTrue()
        ->and($ui['popup']['definitions'])->toBeFalse();
});

test('popup junk values are rejected and unknown keys never persist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', ['popup' => ['definitions' => 'banana']])
        ->assertInvalid('popup.definitions');

    // Unknown keys pass validation but validated() strips them, exactly like
    // the simulator/reader sections.
    $this->actingAs($user)
        ->patch('/ui-settings', ['popup' => ['synonyms' => false, 'definitions' => false]])
        ->assertOk();

    $popup = $user->settings()->first()->ui_settings['popup'];
    expect($popup)->toBe(['definitions' => false]);
});

test('authenticated user can save anki connection settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', [
            'anki' => [
                'url' => 'http://localhost',
                'port' => 8766,
                'deck' => 'My deck',
            ],
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $anki = $user->settings()->first()->ui_settings['anki'];
    expect($anki['url'])->toBe('http://localhost')
        ->and($anki['port'])->toBe(8766)
        ->and($anki['deck'])->toBe('My deck');
});

test('saving the anki section keeps the other sections intact', function () {
    $user = User::factory()->create();
    withSavedUiSettings($user, [
        'simulator' => ['font_size' => 30],
        'reader' => ['highlight' => true],
        'popup' => ['definitions' => false],
    ]);

    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['port' => 8765]])
        ->assertOk();

    $ui = $user->settings()->first()->ui_settings;
    expect($ui['simulator']['font_size'])->toBe(30)
        ->and($ui['reader']['highlight'])->toBeTrue()
        ->and($ui['popup']['definitions'])->toBeFalse()
        ->and($ui['anki']['port'])->toBe(8765);
});

test('anki junk values are rejected and unknown keys never persist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['port' => 0]])
        ->assertInvalid('anki.port');

    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['port' => 70000]])
        ->assertInvalid('anki.port');

    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['port' => 'banana']])
        ->assertInvalid('anki.port');

    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['url' => str_repeat('a', 256)]])
        ->assertInvalid('anki.url');

    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['deck' => str_repeat('a', 101)]])
        ->assertInvalid('anki.deck');

    // Unknown keys pass validation but validated() strips them, exactly like
    // the other sections — the connection config never grows junk fields.
    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['apiKey' => 'secret', 'url' => 'http://127.0.0.1']])
        ->assertOk();

    $anki = $user->settings()->first()->ui_settings['anki'];
    expect($anki)->toBe(['url' => 'http://127.0.0.1']);
});

test('authenticated user can save anki card field toggles', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', [
            'anki' => [
                'url' => 'http://localhost',
                'port' => 8765,
                'deck' => 'ExtApp',
                'fields' => [
                    'word' => true,
                    'transcription' => true,
                    'definitions' => false,
                    'etymology' => false,
                    'body' => false,
                ],
            ],
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $anki = $user->settings()->first()->ui_settings['anki'];
    expect($anki['url'])->toBe('http://localhost')
        ->and($anki['fields']['definitions'])->toBeFalse()
        ->and($anki['fields']['etymology'])->toBeFalse()
        ->and($anki['fields']['body'])->toBeFalse()
        ->and($anki['fields']['word'])->toBeTrue();
});

test('anki field junk is rejected and unknown field keys never persist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['fields' => ['definitions' => 'banana']]])
        ->assertInvalid('anki.fields.definitions');

    // Unknown field keys pass validation but validated() strips them, exactly
    // like the section's connection keys — the toggle map never grows junk.
    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['fields' => ['audio' => true, 'word' => false]]])
        ->assertOk();

    $anki = $user->settings()->first()->ui_settings['anki'];
    expect($anki['fields'])->toBe(['word' => false]);
});

test('anki fields save keeps the other sections intact', function () {
    $user = User::factory()->create();
    withSavedUiSettings($user, [
        'simulator' => ['font_size' => 30],
        'reader' => ['highlight' => true],
        'popup' => ['definitions' => false],
    ]);

    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['fields' => ['tags' => false]]])
        ->assertOk();

    $ui = $user->settings()->first()->ui_settings;
    expect($ui['simulator']['font_size'])->toBe(30)
        ->and($ui['reader']['highlight'])->toBeTrue()
        ->and($ui['popup']['definitions'])->toBeFalse()
        ->and($ui['anki']['fields']['tags'])->toBeFalse();
});

test('an unapproved user can save anki settings', function () {
    // Same auth-only rule as the Popups tab: the Anki tab lives on the
    // profile, whose autosave endpoint is reachable before approval.
    $user = User::factory()->unapproved()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', ['anki' => ['deck' => 'ExtApp']])
        ->assertOk()
        ->assertJson(['saved' => true]);

    expect($user->settings()->first()->ui_settings['anki']['deck'])->toBe('ExtApp');
});

test('an unapproved user can update ui settings', function () {
    // The Popups tab lives on the auth-only profile; its autosave endpoint
    // moved out of the approved-only group with it.
    $user = User::factory()->unapproved()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', ['popup' => ['examples' => false]])
        ->assertOk()
        ->assertJson(['saved' => true]);

    expect($user->settings()->first()->ui_settings['popup']['examples'])->toBeFalse();
});

test('authenticated user can save the phrasal-verbs toggles', function () {
    // ADR 0057: reader + simulator each carry a phrasal_verbs preference
    // beside stress_marks.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/ui-settings', [
            'reader' => ['phrasal_verbs' => true],
            'simulator' => ['phrasal_verbs' => true],
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    $ui = $user->settings()->first()->ui_settings;
    expect($ui['reader']['phrasal_verbs'])->toBeTrue()
        ->and($ui['simulator']['phrasal_verbs'])->toBeTrue();

    // Non-boolean junk is rejected in both sections.
    $this->actingAs($user)
        ->patch('/ui-settings', ['reader' => ['phrasal_verbs' => 'banana']])
        ->assertInvalid('reader.phrasal_verbs');
    $this->actingAs($user)
        ->patch('/ui-settings', ['simulator' => ['phrasal_verbs' => 'banana']])
        ->assertInvalid('simulator.phrasal_verbs');
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
            ->where('currentTasks', 'My saved prompt.'));
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
            // Nothing saved: currentTasks is null and the client shows the
            // default task list under the live format template.
            ->where('currentTasks', null)
            ->where('questionTemplates.format', PromptTemplates::FORMAT_FALLBACK)
            ->where('questionTemplates.tasks', PromptTemplates::TASKS_FALLBACK));
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
