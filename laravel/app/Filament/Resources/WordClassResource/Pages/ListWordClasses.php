<?php

namespace App\Filament\Resources\WordClassResource\Pages;

use App\Filament\Resources\WordClassResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWordClasses extends ListRecords
{
    protected static string $resource = WordClassResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
