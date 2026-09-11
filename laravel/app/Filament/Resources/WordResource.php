<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WordResource\Pages;
use App\Filament\Resources\WordResource\RelationManagers;
use App\Models\Language;
use App\Models\Word;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WordResource extends Resource
{
    protected static ?string $model = Word::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-language';

    protected static string|\UnitEnum|null $navigationGroup = 'Words';

    protected static ?string $navigationLabel = 'Words';

    protected static ?string $modelLabel = 'Word';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('language_id')
                    ->label('Language')
                    ->options(fn (): array => Language::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->searchable()
                    ->live()
                    ->required(),
                TextInput::make('word')
                    ->required()
                    ->maxLength(256),
                TextInput::make('l_word')
                    ->maxLength(256),
                TextInput::make('frequency')
                    ->numeric()
                    ->default(0),
                Select::make('word_class_id')
                    ->label('Word class')
                    ->relationship(
                        'wordClass',
                        'title',
                        modifyQueryUsing: fn (Builder $query, Get $get) => $query->when(
                            $get('language_id'),
                            fn (Builder $query, int $languageId) => $query->where('language_id', $languageId),
                        ),
                    )
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
                TextColumn::make('language.name')
                    ->label('Language')
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
                SelectFilter::make('language')
                    ->relationship('language', 'name'),
                SelectFilter::make('wordClass')
                    ->relationship('wordClass', 'title'),
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
            RelationManagers\FormsRelationManager::class,
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
            'index' => Pages\ListWords::route('/'),
            'create' => Pages\CreateWord::route('/create'),
            'edit' => Pages\EditWord::route('/{record}/edit'),
        ];
    }
}
