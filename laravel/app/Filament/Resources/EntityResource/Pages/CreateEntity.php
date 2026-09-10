<?php

namespace App\Filament\Resources\EntityResource\Pages;

use App\Filament\Resources\EntityResource;
use App\Jobs\ProcessEntityFile;
use Filament\Resources\Pages\CreateRecord;

class CreateEntity extends CreateRecord
{
    protected static string $resource = EntityResource::class;

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
            );
        }
    }
}
