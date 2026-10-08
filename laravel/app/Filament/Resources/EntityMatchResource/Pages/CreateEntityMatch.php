<?php

namespace App\Filament\Resources\EntityMatchResource\Pages;

use App\Classes\EntityMatchCreationService;
use App\Exceptions\CrossWorkEntityPair;
use App\Exceptions\ProcessingLimitReached;
use App\Filament\Resources\EntityMatchResource;
use App\Models\Entity;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEntityMatch extends CreateRecord
{
    protected static string $resource = EntityMatchResource::class;

    /** The creation module is the only writer of Entity matches; this page resolves the two entities, calls it once, and renders the outcome as notifications. */
    protected function handleRecordCreation(array $data): Model
    {
        $first = Entity::query()->find((int) ($data['first_entity_id'] ?? 0));
        $second = Entity::query()->find((int) ($data['second_entity_id'] ?? 0));

        if ($first === null || $second === null) {
            // A stale form submission: an entity was deleted between render
            // and submit. Nothing is created.
            Notification::make()
                ->title('Entity not found.')
                ->danger()
                ->send();

            $this->halt();
        }

        $creator = auth()->user();

        assert($creator instanceof User); // the panel request is authenticated

        // Empty form fields pass nulls so the module's knob defaults apply.
        try {
            $result = (new EntityMatchCreationService)->create(
                $creator,
                $first,
                $second,
                filled($data['chunk_size'] ?? null) ? (int) $data['chunk_size'] : null,
                filled($data['max_n'] ?? null) ? (int) $data['max_n'] : null,
            );
        } catch (CrossWorkEntityPair|ProcessingLimitReached $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        if ($result['status'] === 'duplicate') {
            EntityMatchResource::duplicateMatchNotification($result['existing'])->send();

            $this->halt();
        }

        if ($result['status'] === 'created_from_copy') {
            Notification::make()
                ->title('Alignment copied')
                ->body('An identical text pair already had a completed alignment — reused.')
                ->success()
                ->send();

            return $result['match'];
        }

        Notification::make()
            ->title('Alignment started')
            ->body('Processing sentences for this entity pair')
            ->success()
            ->send();

        return $result['match'];
    }
}
