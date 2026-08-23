<?php

namespace App\Filament\Resources\AiProviderResource\Pages;

use App\Filament\Resources\AiProviderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAiProvider extends EditRecord
{
    protected static string $resource = AiProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
