<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AlignmentTagResource\Pages;
use App\Models\AlignmentTag;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AlignmentTagResource extends Resource
{
    protected static ?string $model = AlignmentTag::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Entities';

    protected static ?string $navigationLabel = 'Alignment Tags';

    protected static ?string $modelLabel = 'Alignment tag';

    protected static ?string $pluralModelLabel = 'Alignment tags';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(256)
                    ->helperText('Display name of the tag'),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->helperText('URL-friendly identifier, e.g. hand-verified'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('alignments_count')
                    ->counts('alignments')
                    ->label('Alignments')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListAlignmentTags::route('/'),
            'create' => Pages\CreateAlignmentTag::route('/create'),
            'edit' => Pages\EditAlignmentTag::route('/{record}/edit'),
        ];
    }
}
