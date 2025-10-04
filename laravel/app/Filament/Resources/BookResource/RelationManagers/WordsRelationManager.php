<?php

namespace App\Filament\Resources\BookResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WordsRelationManager extends RelationManager
{
    protected static string $relationship = 'words';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('word')
                    ->required()
                    ->maxLength(255)
                    ->readOnly(),
                Forms\Components\TextInput::make('count')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1000)
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('word')
            ->columns([
                Tables\Columns\TextColumn::make('word')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('count'),
                Tables\Columns\CheckboxColumn::make('is_known')->sortable(),
                Tables\Columns\CheckboxColumn::make('for_crossword')->sortable(),
                Tables\Columns\TextColumn::make('knowledge')->sortable(),
                Tables\Columns\TextColumn::make('less_100')->sortable(),
                Tables\Columns\TextColumn::make('less_500')->sortable(),
                Tables\Columns\TextColumn::make('less_1000')->sortable(),
                Tables\Columns\TextColumn::make('less_3000')->sortable(),
                Tables\Columns\TextColumn::make('less_5000')->sortable(),
                Tables\Columns\TextColumn::make('less_10000')->sortable(),
                Tables\Columns\TextColumn::make('less_20000')->sortable(),
                Tables\Columns\TextColumn::make('less_50000')->sortable(),
                Tables\Columns\TextColumn::make('less_1000000')->sortable(),
            ])
            ->filters([
                Filter::make('Unknown')
                ->query(function (Builder $query) {
                    return $query->where('is_known', false);
                }),
                Filter::make('Weak knowledge')
                ->query(function (Builder $query) {
                    return $query->where('knowledge', '<', 60);
                }),

                Filter::make('For studying')
                ->query(function (Builder $query) {
                    return $query->where('is_known', false)
                    ->where('book_word.is_solved', false)
                    ->where('knowledge', '<', 60);
                }),
                Filter::make('3000 most common')
                ->query(function (Builder $query) {
                    return $query->where('less_3000', '>', 0);
                }),
                Filter::make('5000 most common')
                ->query(function (Builder $query) {
                    return $query->where('less_5000', '>', 0);
                }),
                Filter::make('10000 most common')
                ->query(function (Builder $query) {
                    return $query->where('less_10000', '>', 0);
                }),
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
                Tables\Actions\AttachAction::make()
                ->form(fn (Tables\Actions\AttachAction $action): array => [
                    $action->getRecordSelect(),
                    Forms\Components\TextInput::make('count')->required(),
                ])
                ->recordSelectOptionsQuery(fn (Builder $query, $search) => $query->where('word', 'like', "{$search}%")),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Tables\Actions\DeleteAction::make(),
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
