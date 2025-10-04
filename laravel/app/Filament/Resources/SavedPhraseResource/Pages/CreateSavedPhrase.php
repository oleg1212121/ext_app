<?php

namespace App\Filament\Resources\SavedPhraseResource\Pages;

use App\Filament\Resources\SavedPhraseResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSavedPhrase extends CreateRecord
{
    protected static string $resource = SavedPhraseResource::class;
}
