<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DefinitionResource\Pages;
use App\Models\Definition;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DefinitionResource extends Resource
{
    protected static ?string $model = Definition::class;

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
                TextColumn::make('pos'),
                TextColumn::make('word'),
                TextColumn::make('definitio'),
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
            'index' => Pages\ListDefinitions::route('/'),
            'create' => Pages\CreateDefinition::route('/create'),
            'edit' => Pages\EditDefinition::route('/{record}/edit'),
        ];
    }
}
