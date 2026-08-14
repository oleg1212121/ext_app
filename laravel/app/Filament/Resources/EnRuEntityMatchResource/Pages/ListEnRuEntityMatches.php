<?php

namespace App\Filament\Resources\EnRuEntityMatchResource\Pages;

use App\Filament\Resources\EnRuEntityMatchResource;
use App\Jobs\AlignEntitySentences;
use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListEnRuEntityMatches extends ListRecords
{
    protected static string $resource = EnRuEntityMatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New Alignment')
                ->icon('heroicon-o-plus')
                ->form([
                    Select::make('en_entity_id')
                        ->label('English Entity')
                        ->required()
                        ->options(
                            EnEntity::whereNotNull('signature')
                                ->whereHas('sentences')
                                ->orderBy('name')
                                ->pluck('name', 'id'),
                        )
                        ->searchable()
                        ->preload(),
                    Select::make('ru_entity_id')
                        ->label('Russian Entity')
                        ->required()
                        ->options(
                            RuEntity::whereNotNull('signature')
                                ->whereHas('sentences')
                                ->orderBy('name')
                                ->pluck('name', 'id'),
                        )
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
                    EnRuEntityMatch::where('en_entity_id', $data['en_entity_id'])
                        ->where('ru_entity_id', $data['ru_entity_id'])
                        ->get()
                        ->each(function (EnRuEntityMatch $existing) {
                            $existing->meaningMatches()->delete();
                            $existing->delete();
                        });

                    $entityMatch = EnRuEntityMatch::create([
                        'en_entity_id' => $data['en_entity_id'],
                        'ru_entity_id' => $data['ru_entity_id'],
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
}
