<?php

namespace App\Filament\Resources\RuEntityResource\Pages;

use App\Filament\Resources\RuEntityResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRuEntities extends ListRecords
{
    protected static string $resource = RuEntityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
