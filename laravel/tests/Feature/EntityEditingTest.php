<?php

use App\Classes\EntityAccessService;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\Language;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\SentenceType;
use App\Models\User;

if (! function_exists('approvedUser')) {
    function approvedUser(): User
    {
        return User::factory()->create(['is_approved' => true]);
    }
}

if (! function_exists('adminUser')) {
    function adminUser(): User
    {
        return User::factory()->create(['is_approved' => true, 'role' => 'admin']);
    }
}

if (! function_exists('makeLanguage')) {
    function makeLanguage(string $code, bool $enabled = true): Language
    {
        return Language::create([
            'code' => $code,
            'name' => ucfirst($code),
            'native_name' => $code,
            'is_enabled' => $enabled,
            'sort_order' => $enabled ? 0 : 99,
        ]);
    }
}

if (! function_exists('makeSentenceType')) {
    function makeSentenceType(): SentenceType
    {
        return SentenceType::firstOrCreate(
            ['name' => 'sentence'],
            ['description' => 'A standard sentence'],
        );
    }
}

if (! function_exists('grantAccess')) {
    function grantAccess(User $user, EnEntity|RuEntity $entity): void
    {
        $entity->grantedUsers()->attach($user->id);
    }
}

beforeEach(function () {
    makeLanguage('en');
    makeLanguage('ru');
    makeSentenceType();
});

// ─── canEdit unit test ───────────────────────────────────────────────────────

it('canEdit mirrors canRead for all access combinations', function () {
    $service = new EntityAccessService;

    $admin = adminUser();
    $grantee = approvedUser();
    $outsider = approvedUser();

    $restricted = EnEntity::create(['name' => 'Restricted', 'is_restricted' => true]);
    grantAccess($grantee, $restricted);

    $public = EnEntity::create(['name' => 'Public', 'is_restricted' => false]);

    expect($service->canEdit($admin, $restricted))->toBeTrue();
    expect($service->canEdit($grantee, $restricted))->toBeTrue();
    expect($service->canEdit($outsider, $restricted))->toBeFalse();
    expect($service->canEdit($admin, $public))->toBeTrue();
    expect($service->canEdit($outsider, $public))->toBeTrue();
});

// ─── edit page ───────────────────────────────────────────────────────────────

it('granted user can open the edit page for a restricted entity', function () {
    $entity = EnEntity::create(['name' => 'Mine', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);

    $this->actingAs($user)
        ->get("/entities/en/{$entity->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Entities/Edit')
            ->where('entity.name', 'Mine')
            ->has('sentenceTypes'));
});

it('non-granted user is forbidden from the edit page for a restricted entity', function () {
    $entity = EnEntity::create(['name' => 'Secret', 'is_restricted' => true]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}/edit")
        ->assertForbidden();
});

it('any approved user can open the edit page for a public entity', function () {
    $entity = EnEntity::create(['name' => 'Open', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}/edit")
        ->assertOk();
});

it('admin can open the edit page for any restricted entity', function () {
    $entity = EnEntity::create(['name' => 'Admin only', 'is_restricted' => true]);

    $this->actingAs(adminUser())
        ->get("/entities/en/{$entity->id}/edit")
        ->assertOk();
});

it('guest is redirected from the edit page', function () {
    $entity = EnEntity::create(['name' => 'X']);

    $this->get("/entities/en/{$entity->id}/edit")->assertRedirect();
});

it('edit page 404s for an unknown entity', function () {
    $this->actingAs(approvedUser())
        ->get('/entities/en/999999/edit')
        ->assertNotFound();
});

// ─── update metadata ─────────────────────────────────────────────────────────

it('granted user can update entity name and description', function () {
    $entity = EnEntity::create(['name' => 'Old', 'description' => 'Old desc', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);

    $this->actingAs($user)
        ->patch("/entities/en/{$entity->id}", ['name' => 'New Name', 'description' => 'New desc'])
        ->assertRedirect("/entities/en/{$entity->id}");

    expect($entity->refresh()->name)->toBe('New Name')
        ->and($entity->description)->toBe('New desc');
});

it('non-granted user cannot update a restricted entity', function () {
    $entity = EnEntity::create(['name' => 'Locked', 'is_restricted' => true]);

    $this->actingAs(approvedUser())
        ->patch("/entities/en/{$entity->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

it('update validates the name is required', function () {
    $entity = EnEntity::create(['name' => 'Keep', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->patch("/entities/en/{$entity->id}", ['name' => ''])
        ->assertSessionHasErrors('name');
});

it('any approved user can update a public entity', function () {
    $entity = EnEntity::create(['name' => 'Public', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->patch("/entities/en/{$entity->id}", ['name' => 'Renamed'])
        ->assertRedirect();

    expect($entity->refresh()->name)->toBe('Renamed');
});

// ─── sentences list ──────────────────────────────────────────────────────────

it('granted user can fetch the sentence list as JSON', function () {
    $entity = EnEntity::create(['name' => 'With sentences', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);

    EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'First', 'order' => 0, 'sentence_type_id' => SentenceType::where('name', 'sentence')->value('id')]);
    EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Second', 'order' => 1024, 'sentence_type_id' => SentenceType::where('name', 'sentence')->value('id')]);

    $this->actingAs($user)
        ->getJson("/entities/en/{$entity->id}/sentences")
        ->assertOk()
        ->assertJsonCount(2, 'sentences')
        ->assertJsonPath('sentences.0.content', 'First')
        ->assertJsonPath('sentences.1.content', 'Second');
});

it('non-granted user cannot fetch sentences for a restricted entity', function () {
    $entity = EnEntity::create(['name' => 'Hidden', 'is_restricted' => true]);

    $this->actingAs(approvedUser())
        ->getJson("/entities/en/{$entity->id}/sentences")
        ->assertForbidden();
});

// ─── insert sentence ─────────────────────────────────────────────────────────

it('granted user can insert a sentence at the end', function () {
    $entity = EnEntity::create(['name' => 'Insert', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $response = $this->actingAs($user)
        ->postJson("/entities/en/{$entity->id}/sentences", [
            'content' => 'New sentence',
            'sentence_type_id' => $typeId,
            'after_sentence_id' => null,
        ]);

    $response->assertOk()
        ->assertJsonPath('sentence.content', 'New sentence');

    $sentence = EnEntitySentence::query()
        ->where('en_entity_id', $entity->id)
        ->where('content', 'New sentence')
        ->first();

    expect($sentence)->not->toBeNull()
        ->and($sentence->sentence_type_id)->toBe($typeId);
});

it('granted user can insert a sentence at the beginning', function () {
    $entity = EnEntity::create(['name' => 'Insert beginning', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $existing = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Existing', 'order' => 1024, 'sentence_type_id' => $typeId]);

    $this->actingAs($user)
        ->postJson("/entities/en/{$entity->id}/sentences", [
            'content' => 'Before all',
            'sentence_type_id' => $typeId,
            'after_sentence_id' => 0,
        ]);

    $newSentence = EnEntitySentence::query()
        ->where('en_entity_id', $entity->id)
        ->where('content', 'Before all')
        ->first();

    expect($newSentence->order)->toBeLessThan($existing->order);
});

it('insert sentence flips the match status to pending', function () {
    $entity = EnEntity::create(['name' => 'Aligned', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $match = EnRuEntityMatch::create([
        'en_entity_id' => $entity->id,
        'ru_entity_id' => RuEntity::create(['name' => 'Pair'])->id,
        'status' => 'completed',
        'linked_count' => 0,
    ]);

    $this->actingAs($user)
        ->postJson("/entities/en/{$entity->id}/sentences", [
            'content' => 'Added',
            'sentence_type_id' => $typeId,
            'after_sentence_id' => null,
        ]);

    expect($match->refresh()->status)->toBe('pending');
});

it('non-granted user cannot insert a sentence', function () {
    $entity = EnEntity::create(['name' => 'No edit', 'is_restricted' => true]);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $this->actingAs(approvedUser())
        ->postJson("/entities/en/{$entity->id}/sentences", [
            'content' => 'Attempt',
            'sentence_type_id' => $typeId,
        ])
        ->assertForbidden();
});

it('insert validates content is required', function () {
    $entity = EnEntity::create(['name' => 'Validate', 'is_restricted' => false]);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $this->actingAs(approvedUser())
        ->postJson("/entities/en/{$entity->id}/sentences", [
            'content' => '',
            'sentence_type_id' => $typeId,
        ])
        ->assertJsonValidationErrorFor('content');
});

// ─── update sentence ─────────────────────────────────────────────────────────

it('granted user can update sentence content and type', function () {
    $entity = EnEntity::create(['name' => 'Edit sentence', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $sentence = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Original', 'order' => 0, 'sentence_type_id' => $typeId]);

    $this->actingAs($user)
        ->patchJson("/entities/en/{$entity->id}/sentences/{$sentence->id}", [
            'content' => 'Updated text',
            'sentence_type_id' => $typeId,
        ])
        ->assertOk()
        ->assertJsonPath('sentence.content', 'Updated text');

    expect($sentence->refresh()->content)->toBe('Updated text');
});

it('update sentence flips the match status to pending', function () {
    $entity = EnEntity::create(['name' => 'Edit aligned', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $sentence = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'X', 'order' => 0, 'sentence_type_id' => $typeId]);

    $match = EnRuEntityMatch::create([
        'en_entity_id' => $entity->id,
        'ru_entity_id' => RuEntity::create(['name' => 'Pair'])->id,
        'status' => 'completed',
        'linked_count' => 0,
    ]);

    $this->actingAs($user)
        ->patchJson("/entities/en/{$entity->id}/sentences/{$sentence->id}", [
            'content' => 'Changed',
            'sentence_type_id' => $typeId,
        ]);

    expect($match->refresh()->status)->toBe('pending');
});

it('non-granted user cannot update a sentence', function () {
    $entity = EnEntity::create(['name' => 'Locked', 'is_restricted' => true]);
    $typeId = SentenceType::where('name', 'sentence')->value('id');
    $sentence = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'X', 'order' => 0, 'sentence_type_id' => $typeId]);

    $this->actingAs(approvedUser())
        ->patchJson("/entities/en/{$entity->id}/sentences/{$sentence->id}", [
            'content' => 'Hacked',
            'sentence_type_id' => $typeId,
        ])
        ->assertForbidden();
});

// ─── destroy sentence ────────────────────────────────────────────────────────

it('granted user can delete an unlinked sentence', function () {
    $entity = EnEntity::create(['name' => 'Delete', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $sentence = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Gone', 'order' => 0, 'sentence_type_id' => $typeId]);

    $this->actingAs($user)
        ->deleteJson("/entities/en/{$entity->id}/sentences/{$sentence->id}")
        ->assertOk();

    expect(EnEntitySentence::find($sentence->id))->toBeNull();
});

it('deleting a junctioned sentence cascades to meaning matches and updates linked_count', function () {
    $entity = EnEntity::create(['name' => 'Cascade', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $sentence = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Linked', 'order' => 0, 'sentence_type_id' => $typeId]);

    $match = EnRuEntityMatch::create([
        'en_entity_id' => $entity->id,
        'ru_entity_id' => RuEntity::create(['name' => 'Pair'])->id,
        'status' => 'completed',
        'linked_count' => 1,
    ]);

    $meaningMatch = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $match->id,
        'order' => 0,
        'similarity' => 0.5,
    ]);

    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $sentence->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
    ]);

    $this->actingAs($user)
        ->deleteJson("/entities/en/{$entity->id}/sentences/{$sentence->id}")
        ->assertOk();

    expect(EnEntitySentence::find($sentence->id))->toBeNull()
        ->and(EnRuMeaningMatch::find($meaningMatch->id))->toBeNull()
        ->and($match->refresh()->linked_count)->toBe(0)
        ->and($match->refresh()->status)->toBe('pending');
});

it('non-granted user cannot delete a sentence', function () {
    $entity = EnEntity::create(['name' => 'No delete', 'is_restricted' => true]);
    $typeId = SentenceType::where('name', 'sentence')->value('id');
    $sentence = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'X', 'order' => 0, 'sentence_type_id' => $typeId]);

    $this->actingAs(approvedUser())
        ->deleteJson("/entities/en/{$entity->id}/sentences/{$sentence->id}")
        ->assertForbidden();
});

// ─── reorder sentences ───────────────────────────────────────────────────────

it('granted user can reorder a sentence to the beginning', function () {
    $entity = EnEntity::create(['name' => 'Reorder', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $first = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'First', 'order' => 0, 'sentence_type_id' => $typeId]);
    $second = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Second', 'order' => 1024, 'sentence_type_id' => $typeId]);
    $third = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Third', 'order' => 2048, 'sentence_type_id' => $typeId]);

    $this->actingAs($user)
        ->postJson("/entities/en/{$entity->id}/sentences/reorder", [
            'sentence_id' => $third->id,
            'after_sentence_id' => 0,
        ])
        ->assertOk();

    expect($third->refresh()->order)->toBeLessThan($first->refresh()->order);
});

it('reorder flips the match status to pending', function () {
    $entity = EnEntity::create(['name' => 'Reorder aligned', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $first = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'A', 'order' => 0, 'sentence_type_id' => $typeId]);
    $second = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'B', 'order' => 1024, 'sentence_type_id' => $typeId]);

    $match = EnRuEntityMatch::create([
        'en_entity_id' => $entity->id,
        'ru_entity_id' => RuEntity::create(['name' => 'Pair'])->id,
        'status' => 'completed',
        'linked_count' => 0,
    ]);

    $this->actingAs($user)
        ->postJson("/entities/en/{$entity->id}/sentences/reorder", [
            'sentence_id' => $second->id,
            'after_sentence_id' => 0,
        ]);

    expect($match->refresh()->status)->toBe('pending');
});

it('non-granted user cannot reorder sentences', function () {
    $entity = EnEntity::create(['name' => 'No reorder', 'is_restricted' => true]);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $first = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'A', 'order' => 0, 'sentence_type_id' => $typeId]);

    $this->actingAs(approvedUser())
        ->postJson("/entities/en/{$entity->id}/sentences/reorder", [
            'sentence_id' => $first->id,
            'after_sentence_id' => 0,
        ])
        ->assertForbidden();
});

// ─── Russian entity parity ───────────────────────────────────────────────────

it('granted user can edit a Russian entity and its sentences', function () {
    $entity = RuEntity::create(['name' => 'Русский', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $this->actingAs($user)
        ->get("/entities/ru/{$entity->id}/edit")
        ->assertOk();

    $this->actingAs($user)
        ->patch("/entities/ru/{$entity->id}", ['name' => 'Обновлено'])
        ->assertRedirect();

    expect($entity->refresh()->name)->toBe('Обновлено');

    $response = $this->actingAs($user)
        ->postJson("/entities/ru/{$entity->id}/sentences", [
            'content' => 'Новое предложение',
            'sentence_type_id' => $typeId,
            'after_sentence_id' => null,
        ])
        ->assertOk();

    expect(RuEntitySentence::query()->where('ru_entity_id', $entity->id)->where('content', 'Новое предложение')->exists())->toBeTrue();
});

// ─── paginated sentence list ──────────────────────────────────────────────

it('granted user can fetch a paginated sentence list with meta and before_first_id', function () {
    $entity = EnEntity::create(['name' => 'Paginated', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $created = [];
    for ($i = 0; $i < 12; $i++) {
        $created[] = EnEntitySentence::create([
            'en_entity_id' => $entity->id,
            'content' => "S{$i}",
            'order' => $i * 1024,
            'sentence_type_id' => $typeId,
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson("/entities/en/{$entity->id}/sentences?page=2&per_page=10")
        ->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonCount(2, 'sentences')
        ->assertJsonPath('before_first_id', $created[9]->id); // last of page 1

    // page 1 exposes no before_first_id
    $this->actingAs($user)
        ->getJson("/entities/en/{$entity->id}/sentences?page=1&per_page=10")
        ->assertJsonPath('before_first_id', null);
});

it('paginated fetch clamps an out-of-range page to the last page', function () {
    $entity = EnEntity::create(['name' => 'Clamp', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    for ($i = 0; $i < 3; $i++) {
        EnEntitySentence::create([
            'en_entity_id' => $entity->id,
            'content' => "S{$i}",
            'order' => $i * 1024,
            'sentence_type_id' => $typeId,
        ]);
    }

    $this->actingAs($user)
        ->getJson("/entities/en/{$entity->id}/sentences?page=99&per_page=10")
        ->assertOk()
        ->assertJsonPath('meta.current_page', 1);
});

it('per_page is clamped to a sane maximum', function () {
    $entity = EnEntity::create(['name' => 'ClampPer', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);

    $this->actingAs($user)
        ->getJson("/entities/en/{$entity->id}/sentences?page=1&per_page=9999")
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

// ─── store returns the page containing the new sentence ───────────────────

it('insert returns the page containing the newly added sentence', function () {
    $entity = EnEntity::create(['name' => 'Store page', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    for ($i = 0; $i < 12; $i++) {
        EnEntitySentence::create([
            'en_entity_id' => $entity->id,
            'content' => "S{$i}",
            'order' => $i * 1024,
            'sentence_type_id' => $typeId,
        ]);
    }

    $this->actingAs($user)
        ->postJson("/entities/en/{$entity->id}/sentences?page=1&per_page=10", [
            'content' => 'New at end',
            'sentence_type_id' => $typeId,
            'after_sentence_id' => null,
        ])
        ->assertOk()
        ->assertJsonPath('meta.current_page', 2) // 13th sentence lands on page 2
        ->assertJsonPath('sentence.content', 'New at end')
        ->assertJsonFragment(['content' => 'New at end']);
});

// ─── non-negative order guard (mirrors alignment editor) ──────────────────

it('reordering to the beginning keeps every order non-negative', function () {
    $entity = EnEntity::create(['name' => 'NonNeg', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $first = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'First', 'order' => 0, 'sentence_type_id' => $typeId]);
    $second = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Second', 'order' => 1024, 'sentence_type_id' => $typeId]);
    $third = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Third', 'order' => 2048, 'sentence_type_id' => $typeId]);

    $this->actingAs($user)
        ->postJson("/entities/en/{$entity->id}/sentences/reorder?page=1&per_page=25", [
            'sentence_id' => $third->id,
            'after_sentence_id' => 0,
        ])
        ->assertOk();

    $minOrder = EnEntitySentence::query()->where('en_entity_id', $entity->id)->min('order');

    expect($minOrder)->toBeGreaterThanOrEqual(0);
    expect($third->refresh()->order)->toBeLessThan($first->refresh()->order);
});

it('inserting at the beginning keeps every order non-negative', function () {
    $entity = EnEntity::create(['name' => 'NonNegInsert', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Existing', 'order' => 0, 'sentence_type_id' => $typeId]);
    EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Existing2', 'order' => 1024, 'sentence_type_id' => $typeId]);

    $this->actingAs($user)
        ->postJson("/entities/en/{$entity->id}/sentences?page=1&per_page=25", [
            'content' => 'Before all',
            'sentence_type_id' => $typeId,
            'after_sentence_id' => 0,
        ])
        ->assertOk();

    $minOrder = EnEntitySentence::query()->where('en_entity_id', $entity->id)->min('order');

    expect($minOrder)->toBeGreaterThanOrEqual(0);
});

// ─── destroy returns the clamped current page ─────────────────────────────

it('deleting on the last page returns a clamped page when it becomes empty', function () {
    $entity = EnEntity::create(['name' => 'Destroy page', 'is_restricted' => true]);
    $user = approvedUser();
    grantAccess($user, $entity);
    $typeId = SentenceType::where('name', 'sentence')->value('id');

    $only = EnEntitySentence::create(['en_entity_id' => $entity->id, 'content' => 'Only', 'order' => 0, 'sentence_type_id' => $typeId]);

    $this->actingAs($user)
        ->deleteJson("/entities/en/{$entity->id}/sentences/{$only->id}?page=1&per_page=10")
        ->assertOk()
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonCount(0, 'sentences');
});
