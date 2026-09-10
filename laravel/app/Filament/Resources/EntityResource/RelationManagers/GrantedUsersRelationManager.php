<?php

namespace App\Filament\Resources\EntityResource\RelationManagers;

use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GrantedUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'grantedUsers';

    protected static ?string $title = 'Granted users';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('user_id')
                    ->label('User')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('similarity')
                    ->label('Signature similarity')
                    ->numeric()
                    ->nullable(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('User'),
                TextColumn::make('email'),
                TextColumn::make('pivot.similarity')
                    ->label('Signature similarity'),
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->form(fn (Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('similarity')
                            ->label('Signature similarity')
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
