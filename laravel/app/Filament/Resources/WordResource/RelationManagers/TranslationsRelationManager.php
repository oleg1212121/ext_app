<?php

namespace App\Filament\Resources\WordResource\RelationManagers;

use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TranslationsRelationManager extends RelationManager
{
    protected static string $relationship = 'translations';

    protected static ?string $recordTitleAttribute = 'word';

    protected static ?string $title = 'Translations (this word → target)';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('word')
                    ->searchable(),
                TextColumn::make('language.name')
                    ->label('Language'),
                TextColumn::make('l_word'),
                TextColumn::make('wordClass.title')
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
