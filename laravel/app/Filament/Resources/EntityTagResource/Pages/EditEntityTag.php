<?php

namespace App\Filament\Resources\EntityTagResource\Pages;

use App\Filament\Resources\EntityTagResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEntityTag extends EditRecord
{
    protected static string $resource = EntityTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
