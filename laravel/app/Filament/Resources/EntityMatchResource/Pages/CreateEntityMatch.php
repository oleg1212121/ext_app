<?php

namespace App\Filament\Resources\EntityMatchResource\Pages;

use App\Classes\EntityMatchCreationService;
use App\Exceptions\CrossWorkEntityPair;
use App\Exceptions\ProcessingLimitReached;
use App\Filament\Resources\EntityMatchResource;
use App\Models\Entity;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEntityMatch extends CreateRecord
{
    protected static string $resource = EntityMatchResource::class;

    /**
     * The creation module is the only writer of Entity matches: same-Work
     * validation, canonical sides, duplicate rejection, the Processing limit
     * and the copy-vs-pipeline decision all live there. This page resolves
     * the two entities from the submitted form, calls the module once, and
     * renders the outcome as notifications. Returning the module's match
     * makes it the record Filament carries (redirect, created event) — the
     * page never inserts a row itself, so there is no double insert.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $first = Entity::query()->findOrFail((int) ($data['first_entity_id'] ?? 0));
        $second = Entity::query()->findOrFail((int) ($data['second_entity_id'] ?? 0));

        $creator = User::query()->findOrFail((int) auth()->id());

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
            $existing = $result['existing'];

            // The module rejected the pair without touching anything; point
            // the administrator at the match that is already there.
            Notification::make()
                ->title('Match already exists')
                ->body("A sentence alignment for this entity pair already exists ({$existing->status}).")
                ->warning()
                ->actions([
                    Action::make('viewMatch')
                        ->label('View match')
                        ->url(EntityMatchResource::getUrl('view', ['record' => $existing])),
                ])
                ->send();

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
