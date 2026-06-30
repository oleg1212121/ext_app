<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RuWordResource\Pages;
use App\Filament\Resources\RuWordResource\RelationManagers;
use App\Models\RuWord;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RuWordResource extends Resource
{
    protected static ?string $model = RuWord::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string | \UnitEnum | null $navigationGroup = 'Words';

    protected static ?string $navigationLabel = 'Russian Words';

    protected static ?string $modelLabel = 'Russian Word';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('word')
                    ->required()
                    ->maxLength(256),
                TextInput::make('l_word')
                    ->maxLength(256),
                TextInput::make('frequency')
                    ->numeric()
                    ->default(0),
                Select::make('ru_word_class_id')
                    ->relationship('wordClass', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('word')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('l_word')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('frequency')
                    ->sortable()
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('wordClass.slug')
                    ->label('Class')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime(),
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
            RelationManagers\DefinitionsRelationManager::class,
            RelationManagers\TranslationsRelationManager::class,
            RelationManagers\TranscriptionsRelationManager::class,
            RelationManagers\ExamplesRelationManager::class,
            RelationManagers\EtymologiesRelationManager::class,
            RelationManagers\PronunciationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRuWords::route('/'),
            'create' => Pages\CreateRuWord::route('/create'),
            'edit' => Pages\EditRuWord::route('/{record}/edit'),
        ];
    }
}
