<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SavedPhraseResource\Pages;
use App\Models\SavedPhrase;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SavedPhraseResource extends Resource
{
    protected static ?string $model = SavedPhrase::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('phrase'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSavedPhrases::route('/'),
            'create' => Pages\CreateSavedPhrase::route('/create'),
            'edit' => Pages\EditSavedPhrase::route('/{record}/edit'),
        ];
    }
}
