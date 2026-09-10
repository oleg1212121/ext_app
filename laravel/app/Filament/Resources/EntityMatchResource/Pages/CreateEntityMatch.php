<?php

namespace App\Filament\Resources\EntityMatchResource\Pages;

use App\Filament\Resources\EntityMatchResource;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateEntityMatch extends CreateRecord
{
    protected static string $resource = EntityMatchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $first = Entity::query()->find((int) ($data['first_entity_id'] ?? 0));
        $second = Entity::query()->find((int) ($data['second_entity_id'] ?? 0));

        if ($first === null || $second === null
            || $first->work_id !== $second->work_id
            || $first->language_id === $second->language_id) {
            Notification::make()
                ->title('Entities must be from the same work in different languages.')
                ->danger()
                ->send();

            $this->halt();
        }

        unset($data['first_entity_id'], $data['second_entity_id']);

        return [
            ...$data,
            'a_entity_id' => min($first->id, $second->id),
            'b_entity_id' => max($first->id, $second->id),
            'status' => 'pending',
        ];
    }

    protected function afterCreate(): void
    {
        EntityMatch::where('id', '!=', $this->record->id)
            ->where('a_entity_id', $this->record->a_entity_id)
            ->where('b_entity_id', $this->record->b_entity_id)
            ->get()
            ->each(function (EntityMatch $existing) {
                $existing->meaningMatches()->delete();
                $existing->delete();
            });

        AlignEntitySentences::beginFromScratch($this->record->id);

        Notification::make()
            ->title('Alignment started')
            ->body('Processing sentences for this entity pair')
            ->success()
            ->send();
    }
}
