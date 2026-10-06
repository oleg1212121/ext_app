<?php

use App\Classes\Enrichment\Annotation;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\ReadingRowsPresenter;
use App\Http\Requests\UpdateUiSettingsRequest;
use App\Models\EntitySentence;
use Illuminate\Support\Str;

/**
 * The annotation vertical's drift guard (ADR 0067): every registered
 * Annotation is known to every derived site — validation, model, seeders,
 * admin preview, page props, payload. Adding annotation #4 without touching
 * a derived site fails here.
 */
it('derives the whole annotation vertical from the registry', function () {
    // Constructed directly with its registry: resolving through the
    // container would run the authorize/validate hooks, which need a user.
    $rules = (new UpdateUiSettingsRequest(new EnricherRegistry))->rules();
    $fillable = (new EntitySentence)->getFillable();
    $readerStrings = require database_path('seeders/ui-strings/reader.php');
    $bilingualsStrings = require database_path('seeders/ui-strings/bilinguals.php');

    $annotations = (new EnricherRegistry)->annotations();
    expect($annotations)->not->toBeEmpty();

    foreach ($annotations as $annotation) {
        expect($rules)->toHaveKey("reader.{$annotation->settingKey}")
            ->and($rules)->toHaveKey("simulator.{$annotation->settingKey}")
            ->and($fillable)->toContain($annotation->column)
            // Both surfaces label the toggle (the ui-strings seeders).
            ->and($readerStrings)->toHaveKey("reader.{$annotation->settingKey}")
            ->and($bilingualsStrings)->toHaveKey("bilinguals.{$annotation->settingKey}")
            // The admin preview column reads the annotation's column (a
            // jsonb column may use dot notation, e.g. phrasal_verbs.label).
            ->and(str(($annotation->adminPreview)()->getName())->before('.')->toString())->toBe($annotation->column);
    }
});

it('emits each annotation under its payload key when the column holds data', function () {
    foreach ((new EnricherRegistry)->annotations() as $annotation) {
        $entity = createEntity('en');
        $sentence = EntitySentence::create(['entity_id' => $entity->id, 'content' => 'Hello.', 'order' => 1024]);

        // Nothing stored: the payload key is absent (no null-for-absent level).
        $payload = app(ReadingRowsPresenter::class)->forEntitySentences(collect([$sentence]));
        expect($payload[0]['a']['sentences'][0])->not->toHaveKey($annotation->payloadKey);

        // Stored: the payload key ships the stored value under its key.
        $stored = match ($annotation->payloadKey) {
            'stressed' => 'Hĕllo.',
            'phrasal' => [['verb' => 'Hello', 'particles' => [], 'start' => 0, 'end' => 5, 'phrase' => 'hello']],
            default => throw new RuntimeException("Unhandled annotation {$annotation->payloadKey} in the vertical test"),
        };
        $sentence->forceFill([$annotation->column => $stored])->saveQuietly();

        $payload = app(ReadingRowsPresenter::class)->forEntitySentences(collect([$sentence->refresh()]));
        expect($payload[0]['a']['sentences'][0][$annotation->payloadKey])->toEqual($stored);
    }
});

it('seeds one page prop per annotation from the saved reader preferences', function () {
    $user = approvedUser();
    $entity = createEntity('en');

    $props = $this->actingAs($user)->get("/reader/{$entity->id}")->assertOk()->inertiaPage()['props'];

    foreach ((new EnricherRegistry)->annotations() as $annotation) {
        // Default off, camelCased from the setting key (ADR 0067).
        expect($props)->toHaveKey(Str::camel($annotation->settingKey))
            ->and($props[Str::camel($annotation->settingKey)])->toBeFalse();
    }

    $saved = [];
    foreach ((new EnricherRegistry)->annotations() as $annotation) {
        $saved[$annotation->settingKey] = true;
    }
    $user->settings()->updateOrCreate(
        ['user_id' => $user->id],
        ['ui_settings' => ['reader' => $saved]],
    );
    $user->unsetRelation('settings');

    $props = $this->actingAs($user)->get("/reader/{$entity->id}")->assertOk()->inertiaPage()['props'];
    foreach ((new EnricherRegistry)->annotations() as $annotation) {
        expect($props[Str::camel($annotation->settingKey)])->toBeTrue();
    }
});

it('validates annotation preference keys on both surfaces', function () {
    $user = approvedUser();

    // The saved preference round-trips through PATCH /ui-settings for every
    // annotation on both surfaces — a drifted validation site would 422.
    foreach (['reader', 'simulator'] as $group) {
        foreach ((new EnricherRegistry)->annotations() as $annotation) {
            $this->actingAs($user)->patchJson('/ui-settings', [
                $group => [$annotation->settingKey => true],
            ])->assertOk();

            $user->unsetRelation('settings');
            expect($user->settings->ui_settings[$group][$annotation->settingKey])->toBeTrue();
        }
    }
});

it('feeds one annotation from two enrichers without duplication', function () {
    $annotations = collect((new EnricherRegistry)->annotations());
    $stress = $annotations->first(fn (Annotation $a) => $a->payloadKey === 'stressed');

    // Two stress enrichers, one annotation (ADR 0067).
    expect($annotations->count())->toBe(2)
        ->and($stress->column)->toBe('stressed_content')
        ->and($stress->settingKey)->toBe('stress_marks');
});
