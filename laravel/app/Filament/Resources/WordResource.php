<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WordResource\Pages;
use App\Filament\Resources\WordResource\RelationManagers;
use App\Models\Word;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\CheckboxInput;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WordResource extends Resource
{
    protected static ?string $model = Word::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('word'),
                TextInput::make('knowledge')->numeric(),
                TextInput::make('less_100')->numeric(),
                TextInput::make('less_500')->numeric(),
                TextInput::make('less_1000')->numeric(),
                TextInput::make('less_3000')->numeric(),
                TextInput::make('less_5000')->numeric(),
                TextInput::make('less_8000')->numeric(),
                TextInput::make('less_10000')->numeric(),
                TextInput::make('less_20000')->numeric(),
                Checkbox::make('is_known'),
                Checkbox::make('is_full'),
                Checkbox::make('has_definitions'),
                // CheckboxColumn::make('has_definitions'),
                // TextInput::make('is_known')->(),
                // TextInput::make('knowledge')->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('word')->searchable()->sortable(),
                TextColumn::make('knowledge')->sortable(),
                TextColumn::make('updated_at'),
                CheckboxColumn::make('for_crossword'),
                TextColumn::make('definitions.definition')->limit(100),
            ])
            ->filters([
                Filter::make('Not for crossword')
                ->query(function (Builder $query) {
                    return $query->where('for_crossword', false);
                }),
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
            // TextColumn::make('definitions.definition'),
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
