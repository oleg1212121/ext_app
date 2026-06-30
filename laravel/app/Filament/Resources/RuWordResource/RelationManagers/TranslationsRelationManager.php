<?php

namespace App\Filament\Resources\RuWordResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class TranslationsRelationManager extends RelationManager
{
    protected static string $relationship = 'translations';

    protected static ?string $recordTitleAttribute = 'word';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('en_word_id')
                    ->relationship('wordClass', 'title')
                    ->searchable(['word', 'l_word'])
                    ->preload()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('word')
                    ->searchable(),
                Tables\Columns\TextColumn::make('l_word'),
                Tables\Columns\TextColumn::make('wordClass.title')
                    ->label('Class'),
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->recordTitleAttribute('word')
                    ->preloadRecordSelect(),
            ])
            ->recordActions([
                Actions\DetachAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
