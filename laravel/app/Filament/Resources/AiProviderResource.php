<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiProviderResource\Pages;
use App\Models\AiProvider;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiProviderResource extends Resource
{
    protected static ?string $model = AiProvider::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationLabel = 'AI Providers';

    protected static ?string $modelLabel = 'AI Provider';

    protected static ?string $pluralModelLabel = 'AI Providers';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('key')
                    ->required()
                    ->maxLength(50)
                    ->disabled(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_enabled')
                    ->label('Enabled'),
                Textarea::make('description'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('is_enabled')
                    ->label('Enabled')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'warning')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->sortable(),
                TextColumn::make('description')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\Action::make('toggleEnabled')
                    ->label(fn (AiProvider $record): string => $record->is_enabled ? 'Disable' : 'Enable')
                    ->icon(fn (AiProvider $record): string => $record->is_enabled ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (AiProvider $record): string => $record->is_enabled ? 'warning' : 'success')
                    ->action(fn (AiProvider $record) => $record->update(['is_enabled' => ! $record->is_enabled])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiProviders::route('/'),
            'edit' => Pages\EditAiProvider::route('/{record}/edit'),
        ];
    }
}
