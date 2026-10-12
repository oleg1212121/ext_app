<?php

namespace App\Filament\Resources\EntityTagResource\Pages;

use App\Filament\Resources\EntityTagResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEntityTags extends ListRecords
{
    protected static string $resource = EntityTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
