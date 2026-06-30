<?php

namespace App\Filament\Resources\EnEntityResource\Pages;

use App\Filament\Resources\EnEntityResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEnEntities extends ListRecords
{
    protected static string $resource = EnEntityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
