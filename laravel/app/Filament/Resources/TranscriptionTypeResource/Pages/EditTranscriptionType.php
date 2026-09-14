<?php

namespace App\Filament\Resources\TranscriptionTypeResource\Pages;

use App\Filament\Resources\TranscriptionTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTranscriptionType extends EditRecord
{
    protected static string $resource = TranscriptionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
