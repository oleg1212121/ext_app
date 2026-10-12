<?php

namespace App\Filament\Resources\WorkTagResource\Pages;

use App\Filament\Resources\WorkTagResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWorkTag extends EditRecord
{
    protected static string $resource = WorkTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
