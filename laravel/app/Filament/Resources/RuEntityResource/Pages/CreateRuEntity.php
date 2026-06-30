<?php

namespace App\Filament\Resources\RuEntityResource\Pages;

use App\Filament\Resources\RuEntityResource;
use App\Jobs\ProcessEntityFile;
use Filament\Resources\Pages\CreateRecord;

class CreateRuEntity extends CreateRecord
{
    protected static string $resource = RuEntityResource::class;

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
                'ru',
            );
        }
    }
}
