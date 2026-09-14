<?php

namespace App\Filament\Resources\WorkResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EntitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'entities';

    protected static ?string $title = 'Entities (translations)';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('language.name')
                    ->label('Language'),
                TextColumn::make('name'),
                TextColumn::make('label')
                    ->label('Translator / edition'),
                IconColumn::make('is_restricted')
                    ->boolean()
                    ->label('Restricted'),
                TextColumn::make('sentences_count')
                    ->counts('sentences')
                    ->label('Sentences'),
            ]);
    }
}
