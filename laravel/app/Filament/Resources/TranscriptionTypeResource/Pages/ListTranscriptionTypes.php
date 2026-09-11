<?php

namespace App\Filament\Resources\TranscriptionTypeResource\Pages;

use App\Filament\Resources\TranscriptionTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTranscriptionTypes extends ListRecords
{
    protected static string $resource = TranscriptionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
