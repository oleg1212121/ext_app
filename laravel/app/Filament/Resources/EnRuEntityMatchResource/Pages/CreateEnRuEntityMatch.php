<?php

namespace App\Filament\Resources\EnRuEntityMatchResource\Pages;

use App\Filament\Resources\EnRuEntityMatchResource;
use App\Jobs\AlignEntitySentences;
use App\Models\EnRuEntityMatch;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateEnRuEntityMatch extends CreateRecord
{
    protected static string $resource = EnRuEntityMatchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'pending';

        return $data;
    }

    protected function afterCreate(): void
    {
        EnRuEntityMatch::where('id', '!=', $this->record->id)
            ->where('en_entity_id', $this->record->en_entity_id)
            ->where('ru_entity_id', $this->record->ru_entity_id)
            ->get()
            ->each(function (EnRuEntityMatch $existing) {
                $existing->meaningMatches()->delete();
                $existing->delete();
            });

        AlignEntitySentences::dispatch($this->record->id);

        Notification::make()
            ->title('Alignment started')
            ->body('Processing sentences for this entity pair')
            ->success()
            ->send();
    }
}
