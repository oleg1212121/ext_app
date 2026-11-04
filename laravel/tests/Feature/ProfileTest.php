<?php

use App\Models\Language;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Illuminate\Support\Collection;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile page shares the popups tab and all-visible defaults', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Profile/Edit')
            ->where('popupVisibility.familiarity', true)
            ->where('popupVisibility.progress_actions', true)
            ->where('popupVisibility.form_of', true)
            ->where('popupVisibility.word_family', true)
            ->where('popupVisibility.frequency', true)
            ->where('popupVisibility.transcriptions', true)
            ->where('popupVisibility.definitions', true)
            ->where('popupVisibility.translations', true)
            ->where('popupVisibility.examples', true)
            ->where('popupVisibility.etymologies', true)
            ->where('popupVisibility.explanation', true));
});

test('profile page shares anki settings with every card field on by default', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ankiSettings.url', 'http://127.0.0.1')
            ->where('ankiSettings.port', 8765)
            ->where('ankiSettings.deck', 'ExtApp')
            ->where('ankiSettings.fields.word', true)
            ->where('ankiSettings.fields.transcription', true)
            ->where('ankiSettings.fields.part_of_speech', true)
            ->where('ankiSettings.fields.frequency', true)
            ->where('ankiSettings.fields.definitions', true)
            ->where('ankiSettings.fields.translations', true)
            ->where('ankiSettings.fields.examples', true)
            ->where('ankiSettings.fields.etymology', true)
            ->where('ankiSettings.fields.word_family', true)
            ->where('ankiSettings.fields.tags', true)
            ->where('ankiSettings.fields.familiarity', true)
            ->where('ankiSettings.fields.language', true)
            ->where('ankiSettings.fields.body', true));
});

test('anki settings prop reflects the saved connection and field opt-outs', function () {
    $user = User::factory()->create();
    $user->settings()->updateOrCreate([], [
        'ui_settings' => ['anki' => [
            'url' => 'http://localhost',
            'port' => 8766,
            'fields' => ['etymology' => false],
        ]],
    ]);

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ankiSettings.url', 'http://localhost')
            ->where('ankiSettings.port', 8766)
            // Keys never touched keep their defaults.
            ->where('ankiSettings.deck', 'ExtApp')
            ->where('ankiSettings.fields.etymology', false)
            ->where('ankiSettings.fields.word', true));
});

test('anki settings prop resolves every enabled language over the legacy global base', function () {
    // A user with only legacy saved settings (no per-language entries) must
    // resolve every language — and `default` — to today's global values.
    createLanguages(['en', 'ru']);
    $user = User::factory()->create();
    $user->settings()->updateOrCreate([], [
        'ui_settings' => ['anki' => [
            'deck' => 'My deck',
            'fields' => ['etymology' => false],
        ]],
    ]);

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The legacy base stays at the top level, unchanged from before.
            ->where('ankiSettings.deck', 'My deck')
            ->where('ankiSettings.fields.etymology', false)
            ->where('ankiSettings.languages.en.deck', 'My deck')
            ->where('ankiSettings.languages.en.fields.etymology', false)
            ->where('ankiSettings.languages.en.fields.word', true)
            ->where('ankiSettings.languages.ru.deck', 'My deck')
            ->where('ankiSettings.languages.ru.fields.etymology', false)
            // Words with an unknown or missing language code resolve here.
            ->where('ankiSettings.languages.default.deck', 'My deck')
            ->where('ankiSettings.languages.default.fields.etymology', false));
});

test('anki settings prop prefers saved per-language entries over the legacy base', function () {
    createLanguages(['en', 'ru']);
    $user = User::factory()->create();
    $user->settings()->updateOrCreate([], [
        'ui_settings' => ['anki' => [
            'deck' => 'My deck',
            'fields' => ['etymology' => false],
            'languages' => [
                'en' => ['deck' => 'English deck', 'fields' => ['etymology' => true, 'tags' => false]],
            ],
        ]],
    ]);

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ankiSettings.languages.en.deck', 'English deck')
            // Per-language values win; keys that language never saved walk
            // the chain per-language → legacy base → defaults.
            ->where('ankiSettings.languages.en.fields.etymology', true)
            ->where('ankiSettings.languages.en.fields.tags', false)
            ->where('ankiSettings.languages.en.fields.word', true)
            // Untouched languages and `default` stay on the legacy base.
            ->where('ankiSettings.languages.ru.deck', 'My deck')
            ->where('ankiSettings.languages.ru.fields.etymology', false)
            ->where('ankiSettings.languages.default.deck', 'My deck'));
});

test('anki settings prop carries one entry per enabled language plus default', function () {
    // Disabled languages get no entry at all; `default` always exists for
    // words with an unknown or missing language code.
    createLanguages(['en']);
    Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => false, 'sort_order' => 9]);
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ankiSettings.languages', function (Collection $languages) {
                // The fluent where() hands the prop over as a Collection;
                // the entries themselves stay plain arrays.
                $languages = $languages->all();

                expect(array_keys($languages))->toBe(['en', 'default'])
                    ->and(array_keys($languages['en']))->toBe(['deck', 'fields'])
                    ->and(array_keys($languages['default']))->toBe(['deck', 'fields']);

                return true;
            }));
});

test('profile page shares language codes with the languages prop', function () {
    // The Anki tab's language selector keys off the code (the per-language
    // settings map and the per-language note type are code-keyed), so the
    // shared prop carries it beside the id.
    createLanguages(['en', 'ru']);
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('languages.0.code', 'en')
            ->where('languages.1.code', 'ru'));
});

test('popup visibility prop reflects the saved opt-outs', function () {
    $user = User::factory()->create();
    $user->settings()->updateOrCreate([], [
        'ui_settings' => ['popup' => ['frequency' => false, 'examples' => false]],
    ]);

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('popupVisibility.frequency', false)
            ->where('popupVisibility.examples', false)
            ->where('popupVisibility.definitions', true));
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user settings can update the native language', function () {
    $user = User::factory()->create();
    $russian = Language::create(['code' => 'ru', 'name' => 'Russian', 'is_enabled' => true, 'sort_order' => 1]);

    // The endpoint replaces the whole settings form, so the payload carries
    // every field the PreferencesForm submits — including the required
    // learning language.
    $response = $this
        ->actingAs($user)
        ->patch('/profile/settings', [
            'native_language_id' => $russian->id,
            'learning_language_id' => $russian->id,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile?tab=preferences');

    expect($user->settings->refresh()->native_language_id)->toBe($russian->id);
});

test('user settings rejects a disabled native language', function () {
    $user = User::factory()->create();
    $disabled = Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => false, 'sort_order' => 2]);

    $originalNativeLanguageId = $user->settings->native_language_id;

    $response = $this
        ->actingAs($user)
        ->patch('/profile/settings', [
            'native_language_id' => $disabled->id,
        ]);

    $response->assertSessionHasErrors('native_language_id');
    expect($user->settings->refresh()->native_language_id)->toBe($originalNativeLanguageId);
});

test('user settings can set and clear the interface language', function () {
    $user = User::factory()->create();
    $interface = Language::create([
        'code' => 'de',
        'name' => 'German',
        'is_enabled' => true,
        'is_interface_enabled' => true,
        'sort_order' => 2,
    ]);
    $english = Language::query()->where('code', 'en')->first();

    $this
        ->actingAs($user)
        ->patch('/profile/settings', [
            'interface_language_id' => $interface->id,
            'learning_language_id' => $english->id,
        ])
        ->assertSessionHasNoErrors();

    expect($user->settings->refresh()->interface_language_id)->toBe($interface->id);

    $this
        ->actingAs($user)
        ->patch('/profile/settings', [
            'interface_language_id' => null,
            'learning_language_id' => $english->id,
        ])
        ->assertSessionHasNoErrors();

    expect($user->settings->refresh()->interface_language_id)->toBeNull();
});

test('user settings accepts belarusian as the interface language', function () {
    // The seeded registry makes be an interface language; the validation rule
    // reads the registry, so the same PATCH must succeed for the be row.
    app(LanguageSeeder::class)->run();
    $be = Language::query()->where('code', 'be')->first();
    $english = Language::query()->where('code', 'en')->first();
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch('/profile/settings', [
            'interface_language_id' => $be->id,
            'learning_language_id' => $english->id,
        ])
        ->assertSessionHasNoErrors();

    expect($user->settings->refresh()->interface_language_id)->toBe($be->id);
});

test('user settings rejects a language that is not interface-enabled', function () {
    $user = User::factory()->create();
    $contentOnly = Language::create([
        'code' => 'fr',
        'name' => 'French',
        'is_enabled' => true,
        'is_interface_enabled' => false,
        'sort_order' => 2,
    ]);

    $this
        ->actingAs($user)
        ->patch('/profile/settings', ['interface_language_id' => $contentOnly->id])
        ->assertSessionHasErrors('interface_language_id');

    expect($user->settings->refresh()->interface_language_id)->toBeNull();
});

test('user settings can update the learning language', function () {
    $user = User::factory()->create();
    $russian = Language::create(['code' => 'ru', 'name' => 'Russian', 'is_enabled' => true, 'sort_order' => 1]);
    $english = Language::query()->where('code', 'en')->first();

    // The form the Preferences tab submits carries the whole settings state.
    $this
        ->actingAs($user)
        ->patch('/profile/settings', [
            'native_language_id' => $english->id,
            'learning_language_id' => $russian->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile?tab=preferences');

    expect($user->settings->refresh()->learning_language_id)->toBe($russian->id);

    // The preferences form opens on the stored choice on the next visit.
    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('learningLanguageId', $russian->id)
            ->where('nativeLanguageId', $english->id));
});

test('every page shares the resolved learning-language code beside the native one', function () {
    // The Anki tab's default (anki-tab-polish ticket 03) is the first
    // consumer, but the code is shared from the middleware — on every page,
    // like the native-language code it sits beside.
    createLanguages(['en', 'ru']);
    $user = User::factory()->create();
    $languages = collect(['en', 'ru'])->mapWithKeys(
        fn (string $code): array => [$code => Language::query()->where('code', $code)->first()],
    );
    $user->settings()->updateOrCreate([], [
        'native_language_id' => $languages['en']->id,
        'learning_language_id' => $languages['ru']->id,
    ]);

    foreach (['/profile', '/'] as $url) {
        $this
            ->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.native_language', 'en')
                ->where('auth.user.learning_language', 'ru'));
    }
});

test('the learning language resolves to English for a user with no settings row', function () {
    // The factory gives every user a settings row; deleting it reproduces the
    // real no-settings case the column's nullability exists for. The stored
    // value is absent, so the app-side resolution serves English (enabled).
    createLanguages(['en', 'ru']);
    $user = User::factory()->create();
    $user->settings()->delete();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.learning_language', 'en'));
});

test('the learning language falls back to the first enabled language when English is disabled', function () {
    createLanguages(['en', 'ru']);
    Language::query()->where('code', 'en')->update(['is_enabled' => false]);
    $user = User::factory()->create();
    $user->settings()->delete();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.learning_language', 'ru'));
});

test('the learning-language code is null when no language is enabled', function () {
    // The fresh-install terminal case: migrate before any language exists.
    createLanguages(['en']);
    $user = User::factory()->create();
    $user->settings()->delete();
    Language::query()->delete();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.learning_language', null));
});

test('the preferences form opens on the resolved learning language before any save', function () {
    // A user whose settings row predates the preference (stored value null —
    // the factory mirrors that shape) gets the resolved English id, so the
    // select opens on a real language instead of an empty option.
    createLanguages(['en', 'ru']);
    $user = User::factory()->create();
    $english = Language::query()->where('code', 'en')->first();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('learningLanguageId', $english->id));
});

test('user settings require a learning language', function () {
    // The learning language is required, unlike the nullable native/interface
    // settings: an empty value is a field error, never a silent clear.
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile/settings', [
            'learning_language_id' => '',
        ]);

    $response->assertSessionHasErrors('learning_language_id');
    expect($user->settings->refresh()->learning_language_id)->toBeNull();
});

test('user settings reject a disabled learning language', function () {
    $user = User::factory()->create();
    $disabled = Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => false, 'sort_order' => 2]);

    $this
        ->actingAs($user)
        ->patch('/profile/settings', ['learning_language_id' => $disabled->id])
        ->assertSessionHasErrors('learning_language_id');

    expect($user->settings->refresh()->learning_language_id)->toBeNull();
});

test('user settings reject a learning language id that does not exist', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch('/profile/settings', ['learning_language_id' => 999999])
        ->assertSessionHasErrors('learning_language_id');

    expect($user->settings->refresh()->learning_language_id)->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});
