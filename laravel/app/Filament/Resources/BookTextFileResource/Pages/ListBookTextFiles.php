<?php

namespace App\Filament\Resources\BookTextFileResource\Pages;

use App\Filament\Resources\BookTextFileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBookTextFiles extends ListRecords
{
    protected static string $resource = BookTextFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
