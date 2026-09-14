<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UiStringKeyResource\Pages;
use App\Models\Language;
use App\Models\UiStringKey;
use Filament\Actions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UiStringKeyResource extends Resource
{
    protected static ?string $model = UiStringKey::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-language';

    protected static string|\UnitEnum|null $navigationGroup = 'Localization';

    protected static ?string $navigationLabel = 'UI Strings';

    protected static ?string $modelLabel = 'UI string';

    protected static ?string $pluralModelLabel = 'UI Strings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('key')
                    ->required()
                    ->rules(['regex:/^[a-z0-9_]+(\.[a-z0-9_]+)+$/'])
                    ->unique(ignoreRecord: true)
                    ->helperText('Dotted key: group.name, e.g. nav.library — the group is derived from the first segment'),
                Repeater::make('strings')
                    ->label('Translations')
                    ->relationship()
                    ->columns(2)
                    ->distinct('language_id')
                    ->schema([
                        Select::make('language_id')
                            ->label('Language')
                            ->required()
                            ->options(fn (): array => Language::query()
                                ->interfaceEnabled()
                                ->orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn (Language $language) => [
                                    $language->id => $language->native_name ?? $language->name,
                                ])
                                ->all()),
                        Textarea::make('text')
                            ->required()
                            ->rows(2)
                            ->columnSpan(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $interfaceLanguages = Language::query()
            ->interfaceEnabled()
            ->orderBy('sort_order')
            ->get();

        return $table
            ->columns([
                TextColumn::make('key')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('group')
                    ->badge()
                    ->sortable(),
                ...$interfaceLanguages->map(fn (Language $language) => TextColumn::make("string_{$language->code}")
                    ->label($language->native_name ?? $language->name)
                    ->state(fn (UiStringKey $record) => $record->strings->firstWhere('language_id', $language->id)?->text)
                    ->limit(40)
                    ->placeholder('—')),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->filters([
                SelectFilter::make('group')
                    ->options(fn (): array => UiStringKey::query()
                        ->distinct()
                        ->orderBy('group')
                        ->pluck('group', 'group')
                        ->all()),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUiStringKeys::route('/'),
            'create' => Pages\CreateUiStringKey::route('/create'),
            'edit' => Pages\EditUiStringKey::route('/{record}/edit'),
        ];
    }
}
