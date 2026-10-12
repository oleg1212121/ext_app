<?php

namespace App\Filament\Resources\AlignmentTagResource\Pages;

use App\Filament\Resources\AlignmentTagResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAlignmentTag extends EditRecord
{
    protected static string $resource = AlignmentTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
