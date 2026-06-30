<?php

namespace App\Filament\Resources\EnWordResource\Pages;

use App\Filament\Resources\EnWordResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEnWord extends EditRecord
{
    protected static string $resource = EnWordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
