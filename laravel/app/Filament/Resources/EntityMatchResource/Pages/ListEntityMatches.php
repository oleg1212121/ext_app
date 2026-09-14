<?php

namespace App\Filament\Resources\EntityMatchResource\Pages;

use App\Filament\Resources\EntityMatchResource;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
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
                ->action(function (array $data) {
                    [$first, $second] = [
                        Entity::query()->findOrFail((int) $data['first_entity_id']),
                        Entity::query()->findOrFail((int) $data['second_entity_id']),
                    ];

                    if ($first->work_id !== $second->work_id) {
                        Notification::make()
                            ->title('Entities must be from the same work.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $aId = min($first->id, $second->id);
                    $bId = max($first->id, $second->id);

                    EntityMatch::where('a_entity_id', $aId)
                        ->where('b_entity_id', $bId)
                        ->get()
                        ->each(function (EntityMatch $existing) {
                            $existing->meaningMatches()->delete();
                            $existing->delete();
                        });

                    $entityMatch = EntityMatch::create([
                        'a_entity_id' => $aId,
                        'b_entity_id' => $bId,
                        'chunk_size' => $data['chunk_size'] ?? 75,
                        'max_n' => $data['max_n'] ?? 6,
                        'status' => 'pending',
                    ]);

                    AlignEntitySentences::beginFromScratch($entityMatch->id);

                    Notification::make()
                        ->title('Alignment started')
                        ->body("Processing entity pair #{$entityMatch->id}")
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
