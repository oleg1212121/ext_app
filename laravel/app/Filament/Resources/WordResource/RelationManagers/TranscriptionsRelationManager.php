<?php

namespace App\Filament\Resources\WordResource\RelationManagers;

use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TranscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transcriptions';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('transcription')
                    ->required()
                    ->maxLength(100),
                Select::make('transcription_type_id')
                    ->label('Type')
                    ->relationship(
                        'transcriptionType',
                        'title',
                        modifyQueryUsing: fn (Builder $query, RelationManager $livewire) => $query->where('language_id', $livewire->getOwnerRecord()->language_id),
                    )
                    ->searchable()
                    ->preload()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transcription')
            ->columns([
                TextColumn::make('transcription'),
                TextColumn::make('transcriptionType.title')
                    ->label('Type'),
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
