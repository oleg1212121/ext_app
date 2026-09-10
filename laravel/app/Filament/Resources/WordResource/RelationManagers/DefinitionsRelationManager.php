<?php

namespace App\Filament\Resources\WordResource\RelationManagers;

use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DefinitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'definitions';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('definition')
                    ->required()
                    ->maxLength(500),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('definition')
            ->columns([
                TextColumn::make('definition'),
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
