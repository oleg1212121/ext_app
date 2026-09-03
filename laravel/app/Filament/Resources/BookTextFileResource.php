<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookTextFileResource\Pages;
use App\Models\Book;
use App\Models\BookTextFile;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BookTextFileResource extends Resource
{
    protected static ?string $model = BookTextFile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name'),
                // TextInput::make('path'),
                TextInput::make('lang'),
                Select::make('book_id')
                    ->options(
                        Book::pluck('name', 'id')
                    ),
                FileUpload::make('attachment'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('path'),
                TextColumn::make('book_id'),
                TextColumn::make('lang'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookTextFiles::route('/'),
            'create' => Pages\CreateBookTextFile::route('/create'),
            'edit' => Pages\EditBookTextFile::route('/{record}/edit'),
        ];
    }
}
