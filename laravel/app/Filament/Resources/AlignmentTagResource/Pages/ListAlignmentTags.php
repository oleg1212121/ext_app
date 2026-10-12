<?php

namespace App\Filament\Resources\AlignmentTagResource\Pages;

use App\Filament\Resources\AlignmentTagResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAlignmentTags extends ListRecords
{
    protected static string $resource = AlignmentTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
