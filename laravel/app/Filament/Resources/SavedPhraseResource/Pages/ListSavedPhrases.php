<?php

namespace App\Filament\Resources\SavedPhraseResource\Pages;

use App\Filament\Resources\SavedPhraseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSavedPhrases extends ListRecords
{
    protected static string $resource = SavedPhraseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
