<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PromptTemplateResource\Pages;
use App\Models\PromptTemplate;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PromptTemplateResource extends Resource
{
    protected static ?string $model = PromptTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Prompt Templates';

    protected static ?string $modelLabel = 'Prompt Template';

    protected static ?string $pluralModelLabel = 'Prompt Templates';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // The key is a code contract: rows are seeded and read by it.
                TextInput::make('key')
                    ->required()
                    ->maxLength(100)
                    ->disabled(),
                Textarea::make('text')
                    ->required()
                    ->rows(8)
                    ->hint('Placeholders are substituted at request time: :base/:learning — assessment question, column language names; :word/:native — word explanation, clicked word and native language.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('text')
                    ->limit(80),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromptTemplates::route('/'),
            'edit' => Pages\EditPromptTemplate::route('/{record}/edit'),
        ];
    }
}
