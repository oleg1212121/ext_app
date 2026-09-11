<?php

namespace App\Filament\Resources\WordResource\RelationManagers;

use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FormsRelationManager extends RelationManager
{
    protected static string $relationship = 'forms';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('form')
                    ->required()
                    ->maxLength(256),
                TextInput::make('l_word')
                    ->label('Lowercase form')
                    ->maxLength(256),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('form')
            ->columns([
                TextColumn::make('form'),
                TextColumn::make('l_word'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (blank($data['l_word'] ?? null)) {
                            $data['l_word'] = mb_strtolower((string) $data['form']);
                        }

                        return $data;
                    }),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (blank($data['l_word'] ?? null)) {
                            $data['l_word'] = mb_strtolower((string) $data['form']);
                        }

                        return $data;
                    }),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
