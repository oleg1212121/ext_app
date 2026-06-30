<?php

namespace App\Filament\Resources\EnEntityResource\Pages;

use App\Filament\Resources\EnEntityResource;
use App\Jobs\ProcessEntityFile;
use Filament\Resources\Pages\CreateRecord;

class CreateEnEntity extends CreateRecord
{
    protected static string $resource = EnEntityResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['file_path'] = $data['file'] ?? null;
        unset($data['file']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record->file_path) {
            ProcessEntityFile::dispatch(
                $this->record->id,
                $this->record->file_path,
                'en',
            );
        }
    }
}
