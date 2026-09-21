<?php

use App\Models\Entity;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

function approvalApprovedUser(): User
{
    return User::factory()->create(['is_approved' => true]);
}

function approvalAdmin(): User
{
    return User::factory()->create(['is_approved' => true, 'role' => 'admin']);
}

/**
 * A public entity owned by $creator with one sentence and a pending match
 * against a second public entity.
 */
function approvalFixture(?User &$creator = null): array
{
    createLanguages();
    $type = SentenceType::firstOrCreate(['name' => 'sentence']);
    $creator ??= approvalApprovedUser();

    $work = createWork();

    $entity = createEntity('en', $work, [
        'name' => 'Owned entity',
        'is_restricted' => false,
        'created_by' => $creator->id,
    ]);
    $entity->sentences()->create([
        'sentence_type_id' => $type->id,
        'content' => 'A sentence.',
        'order' => 1024,
    ]);
    $entity->grantedUsers()->attach($creator->id);

    $other = createEntity('ru', $work, [
        'name' => 'Other entity',
        'is_restricted' => false,
    ]);
    $other->sentences()->create([
        'sentence_type_id' => $type->id,
        'content' => 'Предложение.',
        'order' => 1024,
    ]);

    $match = createEntityMatch($entity, $other, ['status' => 'completed', 'completed_at' => now()]);

    return [$entity, $other, $match];
}

test('the uploader and admins may flip approval, others may not', function () {
    [$entity] = approvalFixture($creator);

    $this->actingAs(approvalApprovedUser())
        ->patch("/entities/en/{$entity->id}/approved", ['is_approved' => true])
        ->assertForbidden();

    expect($entity->refresh()->is_approved)->toBeFalse();

    $this->actingAs($creator)
        ->patch("/entities/en/{$entity->id}/approved", ['is_approved' => true])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($entity->refresh()->is_approved)->toBeTrue();

    // And back, by admin.
    $this->actingAs(approvalAdmin())
        ->patch("/entities/en/{$entity->id}/approved", ['is_approved' => false])
        ->assertRedirect();

    expect($entity->refresh()->is_approved)->toBeFalse();
});

test('an approved entity blocks metadata and sentence edits for everyone but admins', function () {
    [$entity] = approvalFixture($creator);
    $entity->update(['is_approved' => true]);

    $sentence = $entity->sentences()->first();

    foreach ([
        ['patch', "/entities/en/{$entity->id}", ['name' => 'Renamed', 'description' => null]],
        ['post', "/entities/en/{$entity->id}/sentences", [
            'content' => 'New.', 'sentence_type_id' => $sentence->sentence_type_id, 'after_sentence_id' => null,
        ]],
        ['patch', "/entities/en/{$entity->id}/sentences/{$sentence->id}", [
            'content' => 'Changed.', 'sentence_type_id' => $sentence->sentence_type_id,
        ]],
        ['delete', "/entities/en/{$entity->id}/sentences/{$sentence->id}", []],
    ] as [$verb, $url, $payload]) {
        // Even the uploader is locked out while approved.
        $this->actingAs($creator)->{$verb}($url, $payload)->assertForbidden();
        // So is everyone else.
        $this->actingAs(approvalApprovedUser())->{$verb}($url, $payload)->assertForbidden();
    }

    // Admins bypass the lock.
    $this->actingAs(approvalAdmin())
        ->patch("/entities/en/{$entity->id}", ['name' => 'Renamed', 'description' => null])
        ->assertRedirect();

    expect($entity->refresh()->name)->toBe('Renamed');
});

test('an approved entity blocks alignment-editor mutations on both sides', function () {
    [$entity, , $match] = approvalFixture();
    $entity->update(['is_approved' => true]);

    $user = approvalApprovedUser();

    // Mutations are locked…
    $this->actingAs($user)
        ->post("/alignments/{$match->id}/rows", ['after_row_id' => null])
        ->assertForbidden();

    // …but reads stay open (both entities are public).
    $this->actingAs($user)
        ->get("/alignments/{$match->id}/rows", ['page' => 1, 'per_page' => 25])
        ->assertOk();

    // Un-approve and the mutation goes through.
    $entity->update(['is_approved' => false]);

    $this->actingAs($user)
        ->post("/alignments/{$match->id}/rows", ['after_row_id' => null])
        ->assertOk();
});

test('approved entities cannot be deleted by non-admins', function () {
    [$entity] = approvalFixture();
    $entity->update(['is_approved' => true]);

    $this->actingAs(approvalApprovedUser());

    expect(fn () => $entity->delete())->toThrow(RuntimeException::class);

    // Admins may still delete (Gate::before bypass); console context too.
    $this->actingAs(approvalAdmin());
    $entity->delete();

    expect(Entity::find($entity->id))->toBeNull();
});

test('alignments resume skips matches involving an approved entity', function () {
    [$entity, $other, $approvedSideMatch] = approvalFixture();
    $entity->update(['is_approved' => true]);

    [$freeA, $freeB] = (function () {
        $work = createWork(['title' => 'Free work']);
        $type = SentenceType::firstWhere('name', 'sentence');

        $a = createEntity('en', $work, ['name' => 'Free A', 'is_restricted' => false]);
        $a->sentences()->create(['sentence_type_id' => $type->id, 'content' => 'A.', 'order' => 1024]);

        $b = createEntity('ru', $work, ['name' => 'Free B', 'is_restricted' => false]);
        $b->sentences()->create(['sentence_type_id' => $type->id, 'content' => 'Б.', 'order' => 1024]);

        return [$a, $b];
    })();

    $approvedSideMatch->update(['status' => 'pending']);
    $freeMatch = createEntityMatch($freeA, $freeB, ['status' => 'pending']);

    // Verify gate needs signatures on the free pair.
    $freeA->update(['signature' => json_encode([1.0, 0.0])]);
    $freeB->update(['signature' => json_encode([1.0, 0.0])]);

    Bus::fake();

    $this->artisan('alignments:resume')->run();

    // Only the free pair was picked up; the approved-side match stays pending.
    expect($approvedSideMatch->refresh()->status)->toBe('pending');
    expect($freeMatch->refresh()->status)->not->toBe('pending');

    unset($other);
});

test('the entity show page exposes approval state and toggle permission', function () {
    [$entity] = approvalFixture($creator);

    $response = $this->actingAs($creator)->get("/entities/en/{$entity->id}");
    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('entity.is_approved', false)
        ->where('can_change_approval', true));

    $this->actingAs(approvalApprovedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_change_approval', false));

    $entity->update(['is_approved' => true]);

    $this->actingAs($creator)
        ->get("/entities/en/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entity.is_approved', true)
            ->where('can_edit', false));
});

test('alignments resume command still picks matches after approval is lifted', function () {
    [$entity, $other, $match] = approvalFixture();
    $entity->update(['is_approved' => true, 'signature' => json_encode([1.0, 0.0])]);
    $other->update(['signature' => json_encode([1.0, 0.0])]);
    $match->update(['status' => 'pending']);

    Bus::fake();
    $this->artisan('alignments:resume')->assertSuccessful();
    expect($match->refresh()->status)->toBe('pending');

    $entity->update(['is_approved' => false]);
    $this->artisan('alignments:resume')->assertSuccessful();

    expect($match->refresh()->status)->not->toBe('pending');
});
