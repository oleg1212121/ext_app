<?php

namespace App\Filament\Resources\EnEntityResource\RelationManagers;

use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GrantedUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'grantedUsers';

    protected static ?string $inverseRelationship = 'grantedEnEntities';

    protected static ?string $title = 'Granted users';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email'),
                TextColumn::make('pivot.similarity')
                    ->label('Match similarity')
                    ->numeric(4),
            ])
            ->filters([])
            ->headerActions([
                Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->form([
                        TextInput::make('similarity')
                            ->label('Match similarity')
                            ->numeric()
                            ->nullable(),
                    ]),
            ])
            ->recordActions([
                Actions\DetachAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
