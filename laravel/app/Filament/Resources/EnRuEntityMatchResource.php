<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnRuEntityMatchResource\Pages;
use App\Jobs\AlignEntitySentences;
use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnRuEntityMatchResource extends Resource
{
    protected static ?string $model = EnRuEntityMatch::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-link';

    protected static string | \UnitEnum | null $navigationGroup = 'Entities';

    protected static ?string $label = 'Sentence Alignment';

    protected static ?string $pluralLabel = 'Sentence Alignments';

    protected static ?string $navigationLabel = 'Sentence Alignment';

    protected static ?string $slug = 'sentence-alignments';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Entities')
                    ->description('All entities are listed. Alignment needs JSON signatures (run Signature on each entity) and at least one sentence per side (split/process the uploaded file).')
                    ->schema([
                        Select::make('en_entity_id')
                            ->label('English Entity')
                            ->relationship(
                                name: 'enEntity',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->withCount('sentences')->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (EnEntity $record): string => self::formatEntityOptionLabel($record))
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('ru_entity_id')
                            ->label('Russian Entity')
                            ->relationship(
                                name: 'ruEntity',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->withCount('sentences')->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (RuEntity $record): string => self::formatEntityOptionLabel($record))
                            ->required()
                            ->searchable()
                            ->preload(),
                    ]),
                TextInput::make('chunk_size')
                    ->label('Chunk Size')
                    ->numeric()
                    ->default(75)
                    ->minValue(25)
                    ->maxValue(100),
                TextInput::make('max_n')
                    ->label('Max Sentence Span')
                    ->numeric()
                    ->default(6)
                    ->minValue(1)
                    ->maxValue(8),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('enEntity.name')
                    ->label('EN Entity')
                    ->searchable()
                    ->limit(30),
                TextColumn::make('ruEntity.name')
                    ->label('RU Entity')
                    ->searchable()
                    ->limit(30),
                TextColumn::make('entity_similarity')
                    ->label('Similarity')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 4) : '-')
                    ->color(fn ($state) => match (true) {
                        $state >= 0.85 => 'success',
                        $state >= 0.70 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('progress')
                    ->label('Progress')
                    ->getStateUsing(function (EnRuEntityMatch $record) {
                        $total = $record->en_total_sentences + $record->ru_total_sentences;
                        if ($total === 0) {
                            return '-';
                        }

                        return "{$record->linked_count} links";
                    }),
                TextColumn::make('en_total_sentences')
                    ->label('EN Sents')
                    ->toggleable(),
                TextColumn::make('ru_total_sentences')
                    ->label('RU Sents')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'gray',
                        'verifying' => 'info',
                        'aligning' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\Action::make('editAlignment')
                    ->label('Edit alignment')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (EnRuEntityMatch $record): string => static::getUrl('edit', ['record' => $record])),
                Actions\Action::make('rerun')
                    ->label('Re-run')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (EnRuEntityMatch $record) {
                        $record->meaningMatches()->delete();
                        $record->update([
                            'status' => 'pending',
                            'linked_count' => 0,
                            'dp_path' => null,
                            'error_message' => null,
                            'started_at' => null,
                            'completed_at' => null,
                        ]);

                        AlignEntitySentences::dispatch($record->id);
                    })
                    ->visible(fn (EnRuEntityMatch $record) => in_array($record->status, ['completed', 'failed'])),
                Actions\DeleteAction::make(),
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
            'index' => Pages\ListEnRuEntityMatches::route('/'),
            'create' => Pages\CreateEnRuEntityMatch::route('/create'),
            'view' => Pages\ViewEnRuEntityMatch::route('/{record}'),
            'edit' => Pages\EditEntityAlignment::route('/{record}/edit'),
        ];
    }

    private static function formatEntityOptionLabel(EnEntity|RuEntity $record): string
    {
        $missing = [];
        if (! filled($record->signature)) {
            $missing[] = 'signature';
        }
        if ((int) ($record->sentences_count ?? 0) === 0) {
            $missing[] = 'sentences';
        }

        if ($missing === []) {
            return $record->name;
        }

        return "{$record->name} (needs: ".implode(', ', $missing).')';
    }
}
