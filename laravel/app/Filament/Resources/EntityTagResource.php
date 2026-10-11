<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EntityTagResource\Pages;
use App\Models\EntityTag;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EntityTagResource extends Resource
{
    protected static ?string $model = EntityTag::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Entities';

    protected static ?string $navigationLabel = 'Entity Tags';

    protected static ?string $modelLabel = 'Entity tag';

    protected static ?string $pluralModelLabel = 'Entity tags';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(256)
                    ->helperText('Display name of the tag'),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->helperText('URL-friendly identifier, e.g. dialogue-heavy'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entities_count')
                    ->counts('entities')
                    ->label('Entities')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEntityTags::route('/'),
            'create' => Pages\CreateEntityTag::route('/create'),
            'edit' => Pages\EditEntityTag::route('/{record}/edit'),
        ];
    }
}
