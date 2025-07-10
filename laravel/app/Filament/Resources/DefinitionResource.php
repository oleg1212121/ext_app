<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DefinitionResource\Pages;
use App\Filament\Resources\DefinitionResource\RelationManagers;
use App\Models\Definition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DefinitionResource extends Resource
{
    protected static ?string $model = Definition::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
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
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
