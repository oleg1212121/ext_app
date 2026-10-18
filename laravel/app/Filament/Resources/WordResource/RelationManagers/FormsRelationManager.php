<?php

namespace App\Filament\Resources\WordResource\RelationManagers;

use App\Support\StressMarks;
use App\Support\WordLookupKey;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FormsRelationManager extends RelationManager
{
    protected static string $relationship = 'forms';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('form')
                    ->required()
                    ->maxLength(256),
                TextInput::make('l_word')
                    ->label('Lowercase form')
                    ->maxLength(256),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('form')
            ->columns([
                TextColumn::make('form'),
                TextColumn::make('l_word'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data, RelationManager $livewire): array => $this->normalizeFormRow($data, $livewire)),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data, RelationManager $livewire): array => $this->normalizeFormRow($data, $livewire)),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * The manual form row obeys the import conventions: the display form
     * stores plain (the combining stress marks leave it for ru/be/uk, so a
     * stressed variant collapses into the same row as its plain spelling),
     * and the blank l_word autofills through the shared lookup-key rule
     * instead of a bare lowercase.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeFormRow(array $data, RelationManager $livewire): array
    {
        $stripsMarks = StressMarks::stripsFor((string) $livewire->getOwnerRecord()->language->code);

        if ($stripsMarks) {
            $data['form'] = StressMarks::strip((string) ($data['form'] ?? ''));
        }

        if (blank($data['l_word'] ?? null)) {
            $data['l_word'] = WordLookupKey::normalize((string) ($data['form'] ?? ''));
        }

        return $data;
    }
}
