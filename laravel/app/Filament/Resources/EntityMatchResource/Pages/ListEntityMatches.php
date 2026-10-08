<?php

namespace App\Filament\Resources\EntityMatchResource\Pages;

use App\Classes\EntityMatchCreationService;
use App\Exceptions\CrossWorkEntityPair;
use App\Exceptions\ProcessingLimitReached;
use App\Filament\Resources\EntityMatchResource;
use App\Models\Entity;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListEntityMatches extends ListRecords
{
    protected static string $resource = EntityMatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New Alignment')
                ->icon('heroicon-o-plus')
                ->form([
                    Select::make('first_entity_id')
                        ->label('First Entity')
                        ->required()
                        ->options(fn (): array => $this->eligibleEntityOptions())
                        ->searchable()
                        ->preload(),
                    Select::make('second_entity_id')
                        ->label('Second Entity (same work)')
                        ->required()
                        ->options(fn (): array => $this->eligibleEntityOptions())
                        ->searchable()
                        ->preload(),
                    TextInput::make('chunk_size')
                        ->label('Chunk Size')
                        ->numeric()
                        ->default(75)
                        ->minValue(25)
                        ->maxValue(100),
                    TextInput::make('max_n')
                        ->label('Max Sentence Span')
                        ->numeric()
                        ->default(6)
                        ->minValue(1)
                        ->maxValue(8),
                ])
                // The creation module is the only writer of Entity matches; this action resolves the two entities, calls it once, and renders the outcome as notifications.
                ->action(function (array $data) {
                    [$first, $second] = [
                        Entity::query()->findOrFail((int) $data['first_entity_id']),
                        Entity::query()->findOrFail((int) $data['second_entity_id']),
                    ];

                    $creator = auth()->user();

                    assert($creator instanceof User); // the panel request is authenticated

                    // Empty form fields pass nulls so the module's knob
                    // defaults apply.
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

                        return;
                    }

                    if ($result['status'] === 'duplicate') {
                        EntityMatchResource::duplicateMatchNotification($result['existing'])->send();

                        return;
                    }

                    if ($result['status'] === 'created_from_copy') {
                        Notification::make()
                            ->title('Alignment copied')
                            ->body("An identical text pair already had a completed alignment — reused for pair #{$result['match']->id}")
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Alignment started')
                        ->body("Processing entity pair #{$result['match']->id}")
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function eligibleEntityOptions(): array
    {
        return Entity::query()
            ->whereNotNull('signature')
            ->whereHas('sentences')
            ->with('language')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Entity $record): array => [
                $record->id => "[{$record->language?->name}] {$record->name}",
            ])
            ->all();
    }
}
