<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WordResource\Pages;
use App\Models\Word;
use Filament\Actions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WordResource extends Resource
{
    protected static ?string $model = Word::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string | \UnitEnum | null $navigationGroup = 'Words';

    protected static ?string $navigationLabel = 'Legacy Words';

    public static function form(Schema $schema): Schema
    {
        return $schema
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
                Filter::make('Unknown')
                    ->query(function (Builder $query) {
                        return $query->where('knowledge', '<=', 0)->where('is_known', false);
                    }),
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
