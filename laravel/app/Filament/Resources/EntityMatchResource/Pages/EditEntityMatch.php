<?php

namespace App\Filament\Resources\EntityMatchResource\Pages;

use App\Filament\Resources\EntityMatchResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEntityMatch extends EditRecord
{
    protected static string $resource = EntityMatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
