<?php

use App\Classes\AIModelResolver;
use App\Exceptions\AiProviderException;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\PromptTemplate;
use App\Models\SentenceMeaningMatch;
use App\Models\User;
use App\Support\PromptTemplates;
use Mockery\MockInterface;

const AI_EXPLANATION_MODEL_MOCK = ['id' => 7, 'key' => 'openrouter:google/gemini-3-flash-preview', 'label' => 'Gemini Flash'];

/**
 * One EN/RU entity match whose first meaning match rows the classic
 * homonym trap: S1 mentions "bank" (river), S2 uses it (money), S3 follows.
 * The acting user's native language is Russian.
 */
function createExplainFixture(): array
{
    $languages = createLanguages();
    $work = createWork();
    $en = createEntity('en', $work);
    $ru = createEntity('ru', $work);
    $entityMatch = createEntityMatch($en, $ru);

    $s1 = EntitySentence::query()->create(['entity_id' => $en->id, 'content' => 'The bank of the river was steep.', 'order' => 1024]);
    $s2 = EntitySentence::query()->create(['entity_id' => $en->id, 'content' => 'He dropped the coin in the bank.', 'order' => 2048]);
    $s3 = EntitySentence::query()->create(['entity_id' => $en->id, 'content' => 'Water carried it away.', 'order' => 3072]);
    $ruSentence = EntitySentence::query()->create(['entity_id' => $ru->id, 'content' => 'Он уронил монету в банк.', 'order' => 1024]);

    $meaningMatch = MeaningMatch::query()->create(['entity_match_id' => $entityMatch->id, 'order' => 1024, 'similarity' => 1.0]);
    SentenceMeaningMatch::query()->create(['entity_sentence_id' => $s2->id, 'meaning_match_id' => $meaningMatch->id, 'side' => 'a']);
    SentenceMeaningMatch::query()->create(['entity_sentence_id' => $ruSentence->id, 'meaning_match_id' => $meaningMatch->id, 'side' => 'b']);

    $user = User::factory()->create();
    // The factory already attaches a settings row — update it in place.
    $user->settings()->update(['native_language_id' => $languages['ru']->id]);

    $word = createWord('en', 'bank');

    return compact('languages', 'en', 'ru', 'entityMatch', 's1', 's2', 's3', 'ruSentence', 'meaningMatch', 'user', 'word');
}

function explainPayload(array $fixture, array $overrides = []): array
{
    return [
        'meaning_match_id' => $fixture['meaningMatch']->id,
        'side' => 'a',
        'sentence_index' => 0,
        'word_id' => $fixture['word']->id,
        'surface' => 'bank',
        ...$overrides,
    ];
}

function mockResolverForExplain(?array &$captured = null): MockInterface
{
    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')
        ->andReturn(AI_EXPLANATION_MODEL_MOCK);
    $mock->shouldReceive('ask')->andReturnUsing(function ($model, $instruction, $question) use (&$captured) {
        $captured = ['model' => $model, 'instruction' => $instruction, 'question' => $question];

        return 'Тестовый ответ';
    });
    app()->instance(AIModelResolver::class, $mock);

    return $mock;
}

it('explains a word with the sentence before and after in the same entity', function () {
    $fixture = createExplainFixture();
    $captured = [];
    mockResolverForExplain($captured);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture));

    $response->assertOk()
        ->assertJsonPath('data.code', 200)
        ->assertJsonPath('data.answer', 'Тестовый ответ');

    // Reply language comes from the user's Native language setting.
    expect($captured['instruction'])->toContain('Russian');
    expect($captured['instruction'])->toContain('bank');

    // The clicked sentence is marked; neighbours come from the same entity
    // in document order (the RU junction of the row is ignored).
    expect($captured['question'])->toContain('The bank of the river was steep.');
    expect($captured['question'])->toContain('in the **bank**.');
    expect($captured['question'])->toContain('Water carried it away.');
    expect($captured['question'])->not->toContain('Он уронил монету в банк.');

    expect($captured['model'])->toBe('openrouter:google/gemini-3-flash-preview');
});

it('uses the explanation model the resolver resolved, ignoring any client-sent model', function () {
    $fixture = createExplainFixture();
    $captured = [];
    mockResolverForExplain($captured);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture, ['model' => 'openrouter:some/other-model']))
        ->assertOk();

    expect($captured['model'])->toBe('openrouter:google/gemini-3-flash-preview');
});

it('resolves the clicked sentence by index when one row holds several sentences', function () {
    $fixture = createExplainFixture();
    $s4 = EntitySentence::query()->create(['entity_id' => $fixture['en']->id, 'content' => 'The current was strong.', 'order' => 4096]);

    // Second sentence of the row side: clicked = S3, previous = S2 (same
    // row), next = S4 (next entity sentence).
    $junction = SentenceMeaningMatch::query()->create([
        'entity_sentence_id' => $fixture['s3']->id,
        'meaning_match_id' => $fixture['meaningMatch']->id,
        'side' => 'a',
    ]);
    expect($junction)->not->toBeNull();

    $captured = [];
    mockResolverForExplain($captured);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture, ['sentence_index' => 1, 'surface' => 'water', 'word_id' => createWord('en', 'water')->id]))
        ->assertOk();

    expect($captured['question'])->toContain('He dropped the coin in the bank.');
    expect($captured['question'])->toContain('**Water** carried it away.');
    expect($captured['question'])->toContain('The current was strong.');
});

it('explains a word addressed by entity sentence id directly (single-language reader rows)', function () {
    $fixture = createExplainFixture();
    $captured = [];
    mockResolverForExplain($captured);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', [
            'entity_sentence_id' => $fixture['s2']->id,
            'word_id' => $fixture['word']->id,
            'surface' => 'bank',
        ])
        ->assertOk()
        ->assertJsonPath('data.answer', 'Тестовый ответ');

    // Same sentence context as the meaning-match row: neighbours from the
    // same entity in document order.
    expect($captured['question'])->toContain('The bank of the river was steep.');
    expect($captured['question'])->toContain('in the **bank**.');
    expect($captured['question'])->toContain('Water carried it away.');
});

it('refuses an entity-sentence explanation for a restricted entity the user cannot read', function () {
    $fixture = createExplainFixture();
    $fixture['en']->update(['is_restricted' => true]);

    mockResolverForExplain();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', [
            'entity_sentence_id' => $fixture['s2']->id,
            'word_id' => $fixture['word']->id,
            'surface' => 'bank',
        ])
        ->assertStatus(403)
        ->assertJsonPath('data.data.error', 'You do not have access to this text.');
});

it('refuses the explanation when the user has not chosen an explanation model', function () {
    $fixture = createExplainFixture();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')
        ->once()
        ->andReturnNull();
    $mock->shouldReceive('ask')->never();
    app()->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture))
        ->assertStatus(400)
        ->assertJsonPath('data.data.error', 'Choose an AI model in your profile settings.');
});

it('refuses a restricted entity the user cannot read', function () {
    $fixture = createExplainFixture();
    $fixture['en']->update(['is_restricted' => true]);

    mockResolverForExplain();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture))
        ->assertStatus(403)
        ->assertJsonPath('data.data.error', 'You do not have access to this text.');
});

it('validates that the meaning match exists', function () {
    $fixture = createExplainFixture();
    mockResolverForExplain();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture, ['meaning_match_id' => 999999]))
        ->assertStatus(422);
});

it('validates that the entity sentence exists', function () {
    $fixture = createExplainFixture();
    mockResolverForExplain();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', [
            'entity_sentence_id' => 999999,
            'word_id' => $fixture['word']->id,
            'surface' => 'bank',
        ])
        ->assertStatus(422);
});

it('returns 404 when the sentence index is out of range', function () {
    $fixture = createExplainFixture();
    mockResolverForExplain();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture, ['sentence_index' => 5]))
        ->assertStatus(404)
        ->assertJsonPath('data.data.error', 'Sentence not found.');
});

it('surfaces a provider error as a friendly message without leaking internals', function () {
    $fixture = createExplainFixture();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_EXPLANATION_MODEL_MOCK);
    $mock->shouldReceive('ask')
        ->once()
        ->andThrow(new AiProviderException('The AI service is busy. Please try again in a moment.', 429));
    $this->app->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture))
        ->assertStatus(429)
        ->assertJsonPath('data.code', 429)
        ->assertJsonPath('data.data.error', 'The AI service is busy. Please try again in a moment.');
});

it('rate-limits the word explain endpoint after 20 requests per minute', function () {
    $fixture = createExplainFixture();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_EXPLANATION_MODEL_MOCK);
    // Allow unlimited calls — the first 20 run the controller; the 21st is
    // blocked by the limiter before reaching the resolver.
    $mock->shouldReceive('ask')->andReturn('Test answer');
    $this->app->instance(AIModelResolver::class, $mock);

    for ($i = 1; $i <= 20; $i++) {
        $this->actingAs($fixture['user'])
            ->postJson('/ai/word-explain', explainPayload($fixture))
            ->assertOk();
    }

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture))
        ->assertStatus(429);
});

it('assembles the instruction from the admin-edited word-explanation template', function () {
    $fixture = createExplainFixture();
    PromptTemplate::query()->updateOrCreate(
        ['key' => PromptTemplates::EXPLANATION_KEY],
        ['text' => 'Explain :word for a :native speaker.'],
    );

    $captured = [];
    mockResolverForExplain($captured);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-explain', explainPayload($fixture))
        ->assertOk();

    expect($captured['instruction'])->toBe('Explain bank for a Russian speaker.');
});
