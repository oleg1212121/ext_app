<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected $settingsNativeLanguageId;

    protected $settingsInterfaceLanguageId;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->settingsNativeLanguageId = $data['settings_native_language_id'] ?? null;
        $this->settingsInterfaceLanguageId = $data['settings_interface_language_id'] ?? null;

        unset($data['settings_native_language_id'], $data['settings_interface_language_id']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->settings()->create([
            'native_language_id' => filled($this->settingsNativeLanguageId) ? $this->settingsNativeLanguageId : null,
            'interface_language_id' => filled($this->settingsInterfaceLanguageId) ? $this->settingsInterfaceLanguageId : null,
        ]);
    }
}
