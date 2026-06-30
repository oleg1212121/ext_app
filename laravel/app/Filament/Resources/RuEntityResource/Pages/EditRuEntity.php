<?php

namespace App\Filament\Resources\RuEntityResource\Pages;

use App\Filament\Resources\RuEntityResource;
use App\Jobs\ProcessEntityFile;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRuEntity extends EditRecord
{
    protected static string $resource = RuEntityResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['file']) && $data['file'] !== $this->record->file_path) {
            $data['file_path'] = $data['file'];
        }
        unset($data['file']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->data['file'] ?? null) {
            ProcessEntityFile::dispatch(
                $this->record->id,
                $this->record->file_path,
                'ru',
            );
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
