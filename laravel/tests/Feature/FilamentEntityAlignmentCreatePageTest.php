<?php

use App\Filament\Resources\EnRuEntityMatchResource\Pages\CreateEnRuEntityMatch;
use App\Models\EnEntity;
use App\Models\User;
use Livewire\Livewire;

test('filament alignment create page lists entities without signature or sentences', function () {
    $user = User::factory()->create();

    $entity = EnEntity::create([
        'name' => 'Freshly Created English Entity',
        'description' => null,
        'signature' => null,
        'file_path' => null,
    ]);

    Livewire::actingAs($user)
        ->test(CreateEnRuEntityMatch::class)
        ->assertSuccessful()
        ->assertSee($entity->name, false);
});
