<?php

namespace App\Filament\Resources\EnEntityResource\Pages;

use App\Filament\Resources\EnEntityResource;
use App\Jobs\ProcessEntityFile;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEnEntity extends EditRecord
{
    protected static string $resource = EnEntityResource::class;

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
                'en',
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
