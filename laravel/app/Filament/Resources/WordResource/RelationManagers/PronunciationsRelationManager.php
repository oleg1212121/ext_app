<?php

namespace App\Filament\Resources\WordResource\RelationManagers;

use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PronunciationsRelationManager extends RelationManager
{
    protected static string $relationship = 'pronunciations';

    protected static ?string $title = 'Pronunciations';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                FileUpload::make('path')
                    ->label('Audio file')
                    ->disk('public')
                    ->directory('pronunciations')
                    ->acceptedFileTypes(['audio/*'])
                    ->maxSize(10240)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->columns([
                TextColumn::make('path'),
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->using(function (Model $record) {
                        $this->deleteAudioFile($record);

                        return $record->delete();
                    }),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->fetchSelectedRecords()
                        ->using(function ($records): void {
                            $records->each(function (Model $record): void {
                                $this->deleteAudioFile($record);
                                $record->delete();
                            });
                        }),
                ]),
            ]);
    }

    private function deleteAudioFile(Model $record): void
    {
        if (filled($record->path) && Storage::disk('public')->exists($record->path)) {
            Storage::disk('public')->delete($record->path);
        }
    }
}
