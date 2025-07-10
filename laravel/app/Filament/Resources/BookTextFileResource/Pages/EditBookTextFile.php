<?php

namespace App\Filament\Resources\BookTextFileResource\Pages;

use App\Filament\Resources\BookTextFileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBookTextFile extends EditRecord
{
    protected static string $resource = BookTextFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
