<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TranscriptionTypeResource\Pages;
use App\Models\Language;
use App\Models\TranscriptionType;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class TranscriptionTypeResource extends Resource
{
    protected static ?string $model = TranscriptionType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-speaker-wave';

    protected static \UnitEnum|string|null $navigationGroup = 'Words';

    protected static ?string $navigationLabel = 'Transcription types';

    protected static ?string $modelLabel = 'Transcription type';

    protected static ?string $pluralModelLabel = 'Transcription types';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('language_id')
                    ->label('Language')
                    ->options(fn (): array => Language::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live(),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(100)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('language_id', $get('language_id')),
                    )
                    ->helperText('Transcription kind identifier, e.g. ipa, enpr'),
                TextInput::make('title')
                    ->required()
                    ->maxLength(256)
                    ->helperText('Human-readable name, in the type language'),
                Textarea::make('description')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('language.name')
                    ->label('Language')
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('language')
                    ->relationship('language', 'name'),
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
            'index' => Pages\ListTranscriptionTypes::route('/'),
            'create' => Pages\CreateTranscriptionType::route('/create'),
            'edit' => Pages\EditTranscriptionType::route('/{record}/edit'),
        ];
    }
}
