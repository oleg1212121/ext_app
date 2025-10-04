<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookTextFileResource\Pages;
use App\Filament\Resources\BookTextFileResource\RelationManagers;
use App\Models\Book;
use App\Models\BookTextFile;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;


class BookTextFileResource extends Resource
{
    protected static ?string $model = BookTextFile::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name'),
                // TextInput::make('path'),
                TextInput::make('lang'),
                Select::make('book_id')
                ->options(
                    Book::pluck('name', 'id')
                ),
                FileUpload::make('attachment')
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
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
