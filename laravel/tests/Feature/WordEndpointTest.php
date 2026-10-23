<?php

use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Example;
use App\Models\Form;
use App\Models\Language;
use App\Models\Tag;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\User;
use App\Models\Word;
use App\Models\WordAiFetch;
use App\Models\WordClass;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createWordWithDetails(): Word
{
    createWordClasses();
    $word = createWord('ru', 'кот', 'noun');
    $ruLanguageId = Language::query()->where('code', 'ru')->value('id');

    Definition::query()->create(['word_id' => $word->id, 'definition' => 'Домашнее животное семейства кошачьих.']);
    Etymology::query()->create(['word_id' => $word->id, 'etymology' => 'От праслав. *kotь.']);
    $ipa = TranscriptionType::query()->create(['language_id' => $ruLanguageId, 'slug' => 'ipa', 'title' => 'МФА']);
    Transcription::query()->create([
        'word_id' => $word->id,
        'transcription_type_id' => $ipa->id,
        'transcription' => 'kot',
    ]);

    return $word;
}

it('returns dictionary details for a word', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.word', 'кот')
        ->assertJsonPath('data.language_code', 'ru')
        ->assertJsonPath('data.word_class', 'Существительное')
        ->assertJsonPath('data.is_form', false)
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.word_class', 'Существительное')
        ->assertJsonPath('data.entries.0.definitions.0.text', 'Домашнее животное семейства кошачьих.')
        ->assertJsonPath('data.entries.0.transcriptions.0.value', 'kot')
        ->assertJsonPath('data.entries.0.transcriptions.0.type', 'МФА')
        ->assertJsonPath('data.entries.0.etymologies.0', 'От праслав. *kotь.');
});

it('ships definitions as {text, labels} objects — stored labels ride, a null column lands as []', function () {
    $user = User::factory()->create();
    createWordClasses();
    $word = createWord('en', 'knave', 'noun');
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'a dishonest man', 'labels' => ['archaic']]);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'any given card in a deck']);

    // The popup-usage-labels payload: every definition is the object the
    // popup's chips render from — labels verbatim when the column stores
    // them, the empty list when it stores null (the column's "unlabelled"
    // shape), so the client never special-cases null.
    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.entries.0.definitions', [
            ['text' => 'a dishonest man', 'labels' => ['archaic']],
            ['text' => 'any given card in a deck', 'labels' => []],
        ]);
});

it('returns one entry per part of speech of the headword, linked word first', function () {
    $user = User::factory()->create();
    createWordClasses();
    $noun = createWord('en', 'bank', 'noun');
    $verb = createWord('en', 'bank', 'verb');
    Definition::query()->create(['word_id' => $noun->id, 'definition' => 'A financial institution.']);
    Definition::query()->create(['word_id' => $verb->id, 'definition' => 'To rely on.']);

    // The verb is the linked row: it must lead even though the noun outranks it.
    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $verb->id]))
        ->assertOk()
        ->assertJsonPath('data.id', $verb->id)
        ->assertJsonPath('data.word', 'bank')
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $verb->id)
        ->assertJsonPath('data.entries.0.word_class', 'Verb')
        ->assertJsonPath('data.entries.0.definitions.0.text', 'To rely on.')
        ->assertJsonPath('data.entries.1.id', $noun->id)
        ->assertJsonPath('data.entries.1.word_class', 'Noun')
        ->assertJsonPath('data.entries.1.definitions.0.text', 'A financial institution.');
});

it('flags a surface form different from the dictionary lemma', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id, 'surface' => 'коту']))
        ->assertOk()
        ->assertJsonPath('data.is_form', true);
});

it('exposes the headword frequency rank', function () {
    $user = User::factory()->create();
    createWordClasses();
    $word = createWord('en', 'melt', 'verb', ['frequency' => 2143]);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.frequency', 2143);
});

it('hides the frequency rank for unranked words', function () {
    $user = User::factory()->create();
    createWordClasses();
    $neverRanked = createWord('en', 'melt', 'verb');
    $sentinelRanked = createWord('en', 'zarf', 'noun', ['frequency' => Word::FREQUENCY_UNRANKED]);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $neverRanked->id]))
        ->assertOk()
        ->assertJsonPath('data.frequency', null);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $sentinelRanked->id]))
        ->assertOk()
        ->assertJsonPath('data.frequency', null);
});

it('merges base-word entries of the surface form and hides the relay entry', function () {
    $user = User::factory()->create();
    createWordClasses();

    $meltVerb = createWord('en', 'melt', 'verb', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $meltVerb->id, 'definition' => 'To change from solid to liquid state.']);
    // A base-headword sibling that does not claim the surface form: it must
    // not ride along with the claiming class.
    $meltNoun = createWord('en', 'melt', 'noun', ['frequency' => 5000]);
    Definition::query()->create(['word_id' => $meltNoun->id, 'definition' => 'Molten material.']);
    Form::query()->create(['word_id' => $meltVerb->id, 'form' => 'melted', 'l_word' => 'melted']);

    $meltedVerb = createWord('en', 'melted', 'verb', ['frequency' => 8000]);
    Definition::query()->create(['word_id' => $meltedVerb->id, 'definition' => 'simple past and past participle of melt']);
    $meltedAdj = createWord('en', 'melted', 'unknown', ['frequency' => 8100]);
    Definition::query()->create(['word_id' => $meltedAdj->id, 'definition' => 'Being in a liquid state as a result of melting.']);

    $response = $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $meltedVerb->id, 'surface' => 'melted']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['melt'])
        ->assertJsonCount(2, 'data.entries')
        // The relay entry is hidden; the surviving own-group entry (unknown
        // class: definition 1 + rank 1 = 2) and the base entry (verb:
        // definition 2 + rank 1 = 3) sort by completeness.
        ->assertJsonPath('data.entries.0.id', $meltVerb->id)
        ->assertJsonPath('data.entries.0.word', 'melt')
        // The base group is scoped to the claiming class (melt/verb).
        ->assertJsonPath('data.entries.1.id', $meltedAdj->id)
        ->assertJsonPath('data.entries.1.word', 'melted')
        ->assertJsonPath('data.entries.1.definitions.0.text', 'Being in a liquid state as a result of melting.');

    expect(collect($response->json('data.entries'))->pluck('id'))->not->toContain($meltNoun->id);
});

it('scopes base groups to the claiming classes so unrelated same-spelling entries stay hidden', function () {
    $user = User::factory()->create();
    createWordClasses();
    $languageId = Language::query()->where('code', 'en')->value('id');
    foreach (['pronoun' => 'Pronoun', 'character' => 'Character', 'numeral' => 'Numeral'] as $slug => $title) {
        WordClass::query()->create(['language_id' => $languageId, 'slug' => $slug, 'title' => $title]);
    }

    $iClasses = ['pronoun' => 12, 'character' => 13, 'numeral' => 14];
    $iWords = [];
    foreach ($iClasses as $slug => $frequency) {
        $iWords[$slug] = Word::query()->create([
            'word' => 'I',
            'l_word' => 'i',
            'language_id' => $languageId,
            'word_class_id' => WordClass::query()->where('language_id', $languageId)->where('slug', $slug)->value('id'),
            'frequency' => $frequency,
        ]);
    }
    Definition::query()->create(['word_id' => $iWords['pronoun']->id, 'definition' => 'The speaker or writer, referred to as the subject.']);
    Definition::query()->create(['word_id' => $iWords['character']->id, 'definition' => 'The ninth letter of the Latin alphabet.']);
    Definition::query()->create(['word_id' => $iWords['numeral']->id, 'definition' => 'The Roman numeral for one.']);
    // Only the pronoun lists "me" among its forms.
    Form::query()->create(['word_id' => $iWords['pronoun']->id, 'form' => 'me', 'l_word' => 'me']);

    $me = Word::query()->create([
        'word' => 'me',
        'l_word' => 'me',
        'language_id' => $languageId,
        'word_class_id' => $iWords['pronoun']->word_class_id,
        'frequency' => 40,
    ]);
    Definition::query()->create(['word_id' => $me->id, 'definition' => 'The speaker or writer as the object of a verb.']);

    $response = $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $me->id, 'surface' => 'me']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['I'])
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $me->id)
        ->assertJsonPath('data.entries.1.id', $iWords['pronoun']->id)
        ->assertJsonPath('data.entries.1.word', 'I');

    $entryIds = collect($response->json('data.entries'))->pluck('id');
    expect($entryIds)->not->toContain($iWords['character']->id)
        ->and($entryIds)->not->toContain($iWords['numeral']->id);
});

it('filters relay glosses out of partially-relay entries', function () {
    $user = User::factory()->create();
    createWordClasses();

    // Kaikki merges form-of lines into the real entry: real saw senses
    // alongside "simple past of see".
    $sawVerb = createWord('en', 'saw', 'verb');
    Definition::query()->create(['word_id' => $sawVerb->id, 'definition' => 'To cut (something) with a saw.']);
    Definition::query()->create(['word_id' => $sawVerb->id, 'definition' => 'simple past of see']);
    Definition::query()->create(['word_id' => $sawVerb->id, 'definition' => '(colloquial, nonstandard) past participle of see']);
    Form::query()->create(['word_id' => $sawVerb->id, 'form' => 'sawn', 'l_word' => 'sawn']);

    $sawn = createWord('en', 'sawn', 'unknown');

    $response = $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $sawn->id, 'surface' => 'sawn']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['saw']);

    $sawEntry = collect($response->json('data.entries'))->firstWhere('id', $sawVerb->id);
    expect($sawEntry)->not->toBeNull()
        ->and($sawEntry['definitions'])->toBe([['text' => 'To cut (something) with a saw.', 'labels' => []]]);
});

it('scores a partially-relay entry on the stored definitions — the display filter never demotes', function () {
    $user = User::factory()->create();
    createWordClasses();

    // Every surviving (non-relay) sense is obsolete, but the entry also
    // carries relay glosses. Completeness must judge the STORED set — where
    // the unlabelled relay gloss is a live row, exactly as the
    // words:recalc-completeness sweep scores it — never the
    // display-filtered set the popup hides, or words.completeness would
    // flap between a popup open (lazy recalc) and a sweep.
    $sawVerb = createWord('en', 'saw', 'verb', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $sawVerb->id, 'definition' => 'simple past of see']);
    Definition::query()->create(['word_id' => $sawVerb->id, 'definition' => 'No longer used.', 'labels' => ['obsolete']]);
    Form::query()->create(['word_id' => $sawVerb->id, 'form' => 'sawn', 'l_word' => 'sawn']);

    $sawn = createWord('en', 'sawn', 'unknown');

    $response = $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $sawn->id, 'surface' => 'sawn']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['saw']);

    // Stored-set scoring: live verb definitions 2 + rank 1 = 3 — judged on
    // the filtered set this entry would demote to 0 + rank 1 = 1. The
    // sweep's SQL pins the same rows to the same score in
    // WordsRecalcCompletenessCommandTest.
    expect((int) $sawVerb->fresh()->completeness)->toBe(3);

    // The payload still renders the display-filtered definitions — the
    // relay gloss stays hidden even though it scored.
    $sawEntry = collect($response->json('data.entries'))->firstWhere('id', $sawVerb->id);
    expect($sawEntry)->not->toBeNull()
        // The stored labels ride the object beside the text.
        ->and($sawEntry['definitions'])->toBe([['text' => 'No longer used.', 'labels' => ['obsolete']]]);
});

it('keeps a relay-only entry when the forms table offers no base word', function () {
    $user = User::factory()->create();
    createWordClasses();

    $meltedVerb = createWord('en', 'melted', 'verb');
    Definition::query()->create(['word_id' => $meltedVerb->id, 'definition' => 'simple past and past participle of melt']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $meltedVerb->id, 'surface' => 'melted']))
        ->assertOk()
        ->assertJsonPath('data.form_of', [])
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.definitions.0.text', 'simple past and past participle of melt');
});

it('does not duplicate the own headword when the forms table lists it as a form', function () {
    $user = User::factory()->create();
    createWordClasses();

    $meltVerb = createWord('en', 'melt', 'verb');
    Definition::query()->create(['word_id' => $meltVerb->id, 'definition' => 'To change from solid to liquid state.']);
    Form::query()->create(['word_id' => $meltVerb->id, 'form' => 'melts', 'l_word' => 'melts']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $meltVerb->id, 'surface' => 'melts']))
        ->assertOk()
        ->assertJsonPath('data.form_of', [])
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.is_form', true);
});

it('sorts the family by completeness, richest entry first', function () {
    $user = User::factory()->create();
    createWordClasses();

    // The linked row knows nothing; the base word carries the content.
    $melted = createWord('en', 'melted', 'unknown');
    $meltVerb = createWord('en', 'melt', 'verb', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $meltVerb->id, 'definition' => 'To change from solid to liquid.']);
    Form::query()->create(['word_id' => $meltVerb->id, 'form' => 'melted', 'l_word' => 'melted']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $melted->id, 'surface' => 'melted']))
        ->assertOk()
        // The popup header still binds the linked word even though its entry
        // sinks below the richer base entry.
        ->assertJsonPath('data.id', $melted->id)
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $meltVerb->id)
        ->assertJsonPath('data.entries.1.id', $melted->id);
});

it('keeps the linked entry first when completeness ties', function () {
    $user = User::factory()->create();
    createWordClasses();

    $melted = createWord('en', 'melted', 'unknown');
    Definition::query()->create(['word_id' => $melted->id, 'definition' => 'Converted to a liquid state.']);
    // One definition each, unknown class throughout, neither ranked: both
    // score 1 — today's order (own group, linked word first) stands as the
    // tiebreak.
    $meltVerb = createWord('en', 'melt', 'unknown');
    Definition::query()->create(['word_id' => $meltVerb->id, 'definition' => 'To change from solid to liquid.']);
    Form::query()->create(['word_id' => $meltVerb->id, 'form' => 'melted', 'l_word' => 'melted']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $melted->id, 'surface' => 'melted']))
        ->assertOk()
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $melted->id)
        ->assertJsonPath('data.entries.1.id', $meltVerb->id);
});

it('orders base-word groups by frequency without a cap', function () {
    $user = User::factory()->create();
    createWordClasses();

    $leftAdj = createWord('en', 'left', 'unknown', ['frequency' => 400]);
    Definition::query()->create(['word_id' => $leftAdj->id, 'definition' => 'Positioned on the left side.']);

    $leaveVerb = createWord('en', 'leave', 'verb', ['frequency' => 100]);
    Definition::query()->create(['word_id' => $leaveVerb->id, 'definition' => 'To depart from.']);
    $leaveNoun = createWord('en', 'leave', 'noun', ['frequency' => 9000]);
    Definition::query()->create(['word_id' => $leaveNoun->id, 'definition' => 'Permission to be absent.']);
    $liftVerb = createWord('en', 'lift', 'verb', ['frequency' => 3000]);
    Definition::query()->create(['word_id' => $liftVerb->id, 'definition' => 'To raise.']);

    Form::query()->create(['word_id' => $leaveVerb->id, 'form' => 'left', 'l_word' => 'left']);
    Form::query()->create(['word_id' => $leaveNoun->id, 'form' => 'left', 'l_word' => 'left']);
    Form::query()->create(['word_id' => $liftVerb->id, 'form' => 'left', 'l_word' => 'left']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $leftAdj->id, 'surface' => 'left']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['leave', 'lift'])
        ->assertJsonCount(4, 'data.entries')
        // Major POS outranks the minor entry through the score (verb/noun
        // definitions 2 + rank 1 = 3 vs the unknown-class 1 + rank 1 = 2),
        // so the assembly's leading own-group entry sinks last.
        ->assertJsonPath('data.entries.0.id', $leaveNoun->id)
        ->assertJsonPath('data.entries.1.id', $leaveVerb->id)
        ->assertJsonPath('data.entries.2.id', $liftVerb->id)
        ->assertJsonPath('data.entries.3.id', $leftAdj->id);
});

it('ranks a live entry ahead of a demoted one regardless of completeness', function () {
    $user = User::factory()->create();
    createWordClasses();

    // The linked entry is demoted (every definition obsolete) yet rich:
    // demoted (0 definition points) + transcription + example + rank = 3.
    $meltedVerb = createWord('en', 'melted', 'verb', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $meltedVerb->id, 'definition' => 'No longer in use.', 'labels' => ['obsolete']]);
    Transcription::query()->create([
        'word_id' => $meltedVerb->id,
        'transcription_type_id' => TranscriptionType::query()->create([
            'language_id' => Language::query()->where('code', 'en')->value('id'),
            'slug' => 'ipa',
            'title' => 'IPA',
        ])->id,
        'transcription' => 'mɛltɪd',
    ]);
    Example::query()->create(['word_id' => $meltedVerb->id, 'example' => 'It was melted down.']);

    // The base entry is live and sparser: one definition on a verb entry,
    // score 2 — but live always sorts first.
    $meltVerb = createWord('en', 'melt', 'verb');
    Definition::query()->create(['word_id' => $meltVerb->id, 'definition' => 'To change from solid to liquid.']);
    Form::query()->create(['word_id' => $meltVerb->id, 'form' => 'melted', 'l_word' => 'melted']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $meltedVerb->id, 'surface' => 'melted']))
        ->assertOk()
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $meltVerb->id)
        ->assertJsonPath('data.entries.0.word', 'melt')
        ->assertJsonPath('data.entries.1.id', $meltedVerb->id)
        ->assertJsonPath('data.entries.1.word', 'melted');
});

it('finds base words through a surface carrying combining marks', function () {
    $user = User::factory()->create();
    createWordClasses();

    $kot = createWord('ru', 'кот', 'noun');
    Definition::query()->create(['word_id' => $kot->id, 'definition' => 'Домашнее животное семейства кошачьих.']);
    Form::query()->create(['word_id' => $kot->id, 'form' => 'кота', 'l_word' => 'кота']);

    $kota = createWord('ru', 'кота', 'unknown');
    Definition::query()->create(['word_id' => $kota->id, 'definition' => 'родительный падеж от кот']);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $kota->id, 'surface' => 'ко́та']))
        ->assertOk()
        ->assertJsonPath('data.form_of', ['кот']);
});

it('exposes the word tags for the Anki card', function () {
    $user = User::factory()->create();
    createWordClasses();
    $word = createWord('en', 'melt', 'verb');
    $a2 = Tag::factory()->create(['name' => 'A2', 'slug' => 'a2']);
    $top = Tag::factory()->create(['name' => 'top-3000', 'slug' => 'top-3000']);
    $word->tags()->sync([$a2->id, $top->id]);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.tags', ['A2', 'top-3000']);
});

it('returns an empty tag list for untagged words', function () {
    $user = User::factory()->create();
    createWordClasses();
    $word = createWord('en', 'melt', 'verb');

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.tags', []);
});

it('flags ai_asked only when a succeeded AI enrichment ledger row exists', function () {
    $user = User::factory()->create();
    createWordClasses();
    $asked = createWord('en', 'melt', 'verb');
    $failed = createWord('en', 'boil', 'verb');

    WordAiFetch::query()->create([
        'word_id' => $asked->id,
        'status' => WordAiFetch::STATUS_SUCCEEDED,
        'attempts' => 1,
        'asked_at' => now(),
        'succeeded_at' => now(),
    ]);
    WordAiFetch::query()->create([
        'word_id' => $failed->id,
        'status' => WordAiFetch::STATUS_FAILED,
        'attempts' => 2,
    ]);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $asked->id]))
        ->assertOk()
        ->assertJsonPath('data.ai_asked', true);

    // A failed first ask never succeeded — ai_asked stays false (the
    // toolbar shows the first-time ask icon, retryable).
    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $failed->id]))
        ->assertOk()
        ->assertJsonPath('data.ai_asked', false);

    $never = createWord('en', 'freeze', 'verb');
    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $never->id]))
        ->assertOk()
        ->assertJsonPath('data.ai_asked', false);
});

it('sends the word\'s import source at the payload top level', function () {
    $user = User::factory()->create();
    createWordClasses();
    $sourced = createWord('en', 'melt', 'verb', ['source' => Word::SOURCE_KAIKKI]);
    $unsourced = createWord('en', 'boil', 'verb');

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $sourced->id]))
        ->assertOk()
        ->assertJsonPath('data.source', Word::SOURCE_KAIKKI);

    // A pre-provenance row (never stamped by any importer) stays honest:
    // source rides null and the header badge's other half — ai_asked —
    // decides whether an "ai"-only badge renders at all.
    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $unsourced->id]))
        ->assertOk()
        ->assertJsonPath('data.source', null);
});

it('sorts translations native language first', function () {
    createWordClasses();
    $user = User::factory()->create();
    $user->settings()->updateOrCreate([], [
        'native_language_id' => Language::query()->where('code', 'ru')->value('id'),
    ]);

    $enWord = createWord('en', 'cat', 'noun');
    $ruWord = createWord('ru', 'кот', 'noun');
    $deWord = createWord('en', 'tomcat', 'noun');
    WordTranslation::link($enWord->id, $ruWord->id);
    WordTranslation::link($enWord->id, $deWord->id);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $enWord->id]))
        ->assertOk()
        ->assertJsonPath('data.entries.0.translations.0.word', 'кот')
        ->assertJsonPath('data.entries.0.translations.0.language_code', 'ru');
});

it('exposes the staged raw translations deduped against the linked display words', function () {
    createWordClasses();
    $user = User::factory()->create();
    $enWord = createWord('en', 'cat', 'noun');
    $ruWord = createWord('ru', 'кот', 'noun');
    WordTranslation::link($enWord->id, $ruWord->id);

    // Staged raw candidates (popup-provenance ticket 02, ADR 0085): «Кот»
    // only restates the linked display word (case-insensitive) — dropped;
    // «dog» survives; the «DOG» case twin of an earlier staged string does
    // not repeat it (first occurrence stands).
    $enWord->forceFill(['translations' => ['Кот', 'dog', 'DOG']])->save();

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $enWord->id]))
        ->assertOk()
        ->assertJsonPath('data.entries.0.translations.0.word', 'кот')
        ->assertJsonPath('data.entries.0.staged_translations', ['dog']);
});

it('always carries staged_translations — an empty list when nothing is staged', function () {
    createWordClasses();
    $user = User::factory()->create();
    $word = createWord('en', 'cat', 'noun');

    // The key rides every entry even when the word stages nothing (the raw
    // line hides on an empty list; the frontend reads it unconditionally).
    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.entries.0.staged_translations', []);
});

it('caps the staged raw translations at the payload translation limit', function () {
    createWordClasses();
    $user = User::factory()->create();
    $word = createWord('en', 'cat', 'noun');
    $word->forceFill(['translations' => array_map(
        fn (int $i): string => "raw candidate {$i}",
        range(1, 101),
    )])->save();

    $staged = $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk()
        ->json('data.entries.0.staged_translations');

    expect($staged)->toHaveCount(100);
});

it('sets a word known (familiarity 100) for the current user', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 100])
        ->assertOk()
        ->assertJsonPath('data.familiarity', 100);

    $this->assertDatabaseHas('user_word', [
        'user_id' => $user->id,
        'word_id' => $word->id,
        'familiarity' => 100,
    ]);
});

it('overwrites an existing progress mark when setting familiarity', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();
    $user->userWords()->create(['word_id' => $word->id, 'familiarity' => 7]);
    expect($user->userWords()->count())->toBe(1);
    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 100])
        ->assertOk();

    $this->assertDatabaseHas('user_word', [
        'user_id' => $user->id,
        'word_id' => $word->id,
        'familiarity' => 100,
    ]);
});

it('rejects an out-of-range familiarity value', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 101])
        ->assertJsonValidationErrors(['familiarity']);

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => -1])
        ->assertJsonValidationErrors(['familiarity']);

    $this->actingAs($user)
        ->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 'banana'])
        ->assertJsonValidationErrors(['familiarity']);

    $this->assertDatabaseMissing('user_word', ['user_id' => $user->id]);
});

it('removes the progress mark', function () {
    $user = User::factory()->create();
    $word = createWordWithDetails();
    $user->userWords()->create(['word_id' => $word->id, 'familiarity' => 100]);

    $this->actingAs($user)
        ->deleteJson(route('words.progress.reset', ['word' => $word->id]))
        ->assertOk()
        ->assertJsonPath('data.familiarity', null);

    $this->assertDatabaseMissing('user_word', [
        'user_id' => $user->id,
        'word_id' => $word->id,
    ]);
});

it('requires authentication', function () {
    $word = createWordWithDetails();

    $this->getJson(route('words.show', ['word' => $word->id]))->assertUnauthorized();
    $this->patchJson(route('words.progress.update', ['word' => $word->id]), ['familiarity' => 100])->assertUnauthorized();
    $this->deleteJson(route('words.progress.reset', ['word' => $word->id]))->assertUnauthorized();
    $this->postJson(route('word.events.store'), ['events' => []])->assertUnauthorized();
});
