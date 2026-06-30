<?php

namespace App\Filament\Resources\RuWordResource\Pages;

use App\Filament\Resources\RuWordResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRuWords extends ListRecords
{
    protected static string $resource = RuWordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
