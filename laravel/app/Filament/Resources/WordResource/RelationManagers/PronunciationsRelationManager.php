<?php

namespace App\Filament\Resources\WordResource\RelationManagers;

use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PronunciationsRelationManager extends RelationManager
{
    protected static string $relationship = 'pronunciations';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('path')
                    ->required()
                    ->maxLength(256),
                Select::make('transcription_type_id')
                    ->label('Type')
                    ->relationship('transcriptionType', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->columns([
                TextColumn::make('path'),
                TextColumn::make('transcriptionType.title')
                    ->label('Type'),
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
