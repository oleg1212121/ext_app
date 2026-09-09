<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserSettingsResource\Pages;
use App\Models\UserSettings;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserSettingsResource extends Resource
{
    protected static ?string $model = UserSettings::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'User Settings';

    protected static ?string $modelLabel = 'User setting';

    protected static ?string $pluralModelLabel = 'User settings';

    public static function form(Schema $schema): Schema
    {
        $nativeLanguage = fn (Builder $query) => $query->where('is_enabled', true)->orderBy('sort_order')->orderBy('name');

        return $schema
            ->schema([
                Select::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->unique(ignoreRecord: true),
                Select::make('native_language_id')
                    ->label('Native language')
                    ->relationship('nativeLanguage', 'name', $nativeLanguage)
                    ->searchable()
                    ->preload()
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nativeLanguage.name')
                    ->label('Native language')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('user.name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserSettings::route('/'),
            'create' => Pages\CreateUserSettings::route('/create'),
            'edit' => Pages\EditUserSettings::route('/{record}/edit'),
        ];
    }
}
