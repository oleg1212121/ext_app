<?php

namespace App\Filament\Resources\UiStringKeyResource\Pages;

use App\Filament\Resources\UiStringKeyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUiStringKeys extends ListRecords
{
    protected static string $resource = UiStringKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
