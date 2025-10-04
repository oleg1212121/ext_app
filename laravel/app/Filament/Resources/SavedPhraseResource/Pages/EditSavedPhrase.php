<?php

namespace App\Filament\Resources\SavedPhraseResource\Pages;

use App\Filament\Resources\SavedPhraseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSavedPhrase extends EditRecord
{
    protected static string $resource = SavedPhraseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
