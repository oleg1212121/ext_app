<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnRuEntityMatchResource\Pages;
use App\Jobs\AlignEntitySentences;
use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\RuEntity;
use Filament\Actions;
use Filament\Forms\Components\Radio;
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

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Entities';

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
                        Radio::make('is_original_en')
                            ->label('Original Text')
                            ->boolean('English', 'Russian')
                            ->default(true),
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
                TextColumn::make('is_original_en')
                    ->label('Original')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'EN' : 'RU')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'info' : 'warning'),
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

                        return "{$record->confirmed_count} links";
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
                Actions\Action::make('realign')
                    ->label('Re-align')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(function (EnRuEntityMatch $record): string {
                        $humanMade = EnRuMeaningMatch::query()
                            ->where('en_ru_entity_match_id', $record->id)
                            ->where('alignment_chunk', -1)
                            ->count();

                        $confident = EnRuMeaningMatch::query()
                            ->where('en_ru_entity_match_id', $record->id)
                            ->where('similarity', '>=', AlignEntitySentences::LANDMARK_THRESHOLD)
                            ->where('alignment_chunk', '!=', -1)
                            ->count();

                        return "{$humanMade} human-made + {$confident} confident row(s) preserved; only low-confidence rows will be re-aligned.";
                    })
                    ->action(fn (EnRuEntityMatch $record) => AlignEntitySentences::begin($record->id))
                    ->visible(fn (EnRuEntityMatch $record) => in_array($record->status, ['completed', 'failed'])),
                Actions\Action::make('rerunScratch')
                    ->label('Run from scratch')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(function (EnRuEntityMatch $record): string {
                        $description = 'This deletes ALL meaning matches (including human-made ones) and re-runs the alignment pipeline from scratch.';

                        $humanMadeCount = EnRuMeaningMatch::query()
                            ->where('en_ru_entity_match_id', $record->id)
                            ->where('alignment_chunk', -1)
                            ->count();

                        if ($humanMadeCount > 0) {
                            $description .= " {$humanMadeCount} human-made row(s) will be deleted.";
                        }

                        return $description;
                    })
                    ->action(fn (EnRuEntityMatch $record) => AlignEntitySentences::beginFromScratch($record->id))
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
