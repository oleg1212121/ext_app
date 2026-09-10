<?php

namespace App\Filament\Resources\EntityMatchResource\Pages;

use App\Filament\Resources\EntityMatchResource;
use App\Jobs\AlignEntitySentences;
use App\Models\EntityMatch;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateEntityMatch extends CreateRecord
{
    protected static string $resource = EntityMatchResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $aId = min((int) $data['first_entity_id'], (int) $data['second_entity_id']);
        $bId = max((int) $data['first_entity_id'], (int) $data['second_entity_id']);

        unset($data['first_entity_id'], $data['second_entity_id']);

        return [
            ...$data,
            'a_entity_id' => $aId,
            'b_entity_id' => $bId,
            'status' => 'pending',
        ];
    }

    protected function beforeCreate(): void
    {
        $data = $this->form->getRawState();

        $this->validatePair((int) $data['first_entity_id'], (int) $data['second_entity_id']);
    }

    private function validatePair(int $firstEntityId, int $secondEntityId): void
    {
        $entities = \App\Models\Entity::query()->whereIn('id', [$firstEntityId, $secondEntityId])->get()->keyBy('id');

        $first = $entities->get($firstEntityId);
        $second = $entities->get($secondEntityId);

        if ($first === null || $second === null) {
            $this->halt();
        }

        if ($first->work_id !== $second->work_id) {
            Notification::make()
                ->title('Both entities must belong to the same work.')
                ->danger()
                ->send();

            $this->halt();
        }

        if ($first->language_id === $second->language_id) {
            Notification::make()
                ->title('Both entities must be in different languages.')
                ->danger()
                ->send();

            $this->halt();
        }
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
