<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected $settingsNativeLanguageId;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => $this->record->id !== auth()->id()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['settings_native_language_id'] = $this->record->settings?->native_language_id;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->settingsNativeLanguageId = $data['settings_native_language_id'] ?? null;

        unset($data['settings_native_language_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->settings()->updateOrCreate(
            ['user_id' => $this->record->id],
            ['native_language_id' => filled($this->settingsNativeLanguageId) ? $this->settingsNativeLanguageId : null],
        );
    }
}
