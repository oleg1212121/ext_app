<?php

namespace App\Filament\Resources\DefinitionResource\Pages;

use App\Filament\Resources\DefinitionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDefinition extends EditRecord
{
    protected static string $resource = DefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
