<?php

namespace App\Filament\Resources\EntityResource\Pages;

use App\Classes\EntityTextHasher;
use App\Filament\Resources\EntityResource;
use App\Jobs\ProcessEntityFile;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEntity extends EditRecord
{
    protected static string $resource = EntityResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['file']) && $data['file'] !== $this->record->file_path) {
            $data['file_path'] = $data['file'];
            $data['file_hash'] = EntityTextHasher::hashStoredFile((string) $data['file']);
            $data['sentences_updated_at'] = now();
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
