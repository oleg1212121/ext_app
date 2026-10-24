<?php

namespace App\Filament\Resources\WordResource\Pages;

use App\Filament\Resources\WordResource;
use App\Support\WordLookupKey;
use Filament\Resources\Pages\CreateRecord;

class CreateWord extends CreateRecord
{
    protected static string $resource = WordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The l_word key is the uniqueness identity: a blank one would store
        // an unkeyed row, so it autofills through the shared normalization
        // (the FormsRelationManager precedent). A typed key is the author's
        // call and stays verbatim — a colliding one surfaces the panel's
        // unique-violation error, as any duplicate input does.
        if (blank($data['l_word'] ?? null)) {
            $data['l_word'] = WordLookupKey::normalize($data['word']);
        }

        return $data;
    }
}
