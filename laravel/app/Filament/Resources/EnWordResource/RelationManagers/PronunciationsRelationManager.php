<?php

namespace App\Filament\Resources\EnWordResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PronunciationsRelationManager extends RelationManager
{
    protected static string $relationship = 'pronunciations';

    protected static ?string $recordTitleAttribute = 'path';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('path')
                    ->required()
                    ->maxLength(256),
                Forms\Components\Select::make('en_transcription_type_id')
                    ->relationship('type', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('path'),
                Tables\Columns\TextColumn::make('type.title')
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
