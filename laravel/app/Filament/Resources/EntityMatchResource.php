<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EntityMatchResource\Pages;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\MeaningMatch;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EntityMatchResource extends Resource
{
    protected static ?string $model = EntityMatch::class;

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
                    ->description('Entities of one work in two different languages. Alignment needs JSON signatures (run Signature on each entity) and at least one sentence per side (split/process the uploaded file). The original side is derived from the work\'s original language.')
                    ->schema([
                        Select::make('first_entity_id')
                            ->label('First Entity')
                            ->options(fn (): array => self::entityOptions())
                            ->required()
                            ->searchable(),
                        Select::make('second_entity_id')
                            ->label('Second Entity')
                            ->options(fn (): array => self::entityOptions())
                            ->required()
                            ->searchable(),
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

    /**
     * @return array<int, string>
     */
    private static function entityOptions(): array
    {
        return Entity::query()
            ->with('language')
            ->withCount('sentences')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Entity $record): array => [
                $record->id => self::formatEntityOptionLabel($record),
            ])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('aEntity.name')
                    ->label('A Entity')
                    ->searchable()
                    ->limit(30),
                TextColumn::make('bEntity.name')
                    ->label('B Entity')
                    ->searchable()
                    ->limit(30),
                TextColumn::make('aEntity.work.title')
                    ->label('Work')
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
                    ->getStateUsing(function (EntityMatch $record) {
                        $total = $record->a_total_sentences + $record->b_total_sentences;
                        if ($total === 0) {
                            return '-';
                        }

                        return "{$record->confirmed_count} links";
                    }),
                TextColumn::make('a_total_sentences')
                    ->label('A Sents')
                    ->toggleable(),
                TextColumn::make('b_total_sentences')
                    ->label('B Sents')
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
                    ->url(fn (EntityMatch $record): string => static::getUrl('edit', ['record' => $record])),
                Actions\Action::make('realign')
                    ->label('Re-align')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(function (EntityMatch $record): string {
                        $humanMade = MeaningMatch::query()
                            ->where('entity_match_id', $record->id)
                            ->where('alignment_chunk', -1)
                            ->count();

                        $confident = MeaningMatch::query()
                            ->where('entity_match_id', $record->id)
                            ->where('similarity', '>=', AlignEntitySentences::LANDMARK_THRESHOLD)
                            ->where('alignment_chunk', '!=', -1)
                            ->count();

                        return "{$humanMade} human-made + {$confident} confident row(s) preserved; only low-confidence rows will be re-aligned.";
                    })
                    ->action(fn (EntityMatch $record) => AlignEntitySentences::begin($record->id))
                    ->visible(fn (EntityMatch $record) => in_array($record->status, ['completed', 'failed'])),
                Actions\Action::make('rerunScratch')
                    ->label('Run from scratch')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(function (EntityMatch $record): string {
                        $description = 'This deletes ALL meaning matches (including human-made ones) and re-runs the alignment pipeline from scratch.';

                        $humanMadeCount = MeaningMatch::query()
                            ->where('entity_match_id', $record->id)
                            ->where('alignment_chunk', -1)
                            ->count();

                        if ($humanMadeCount > 0) {
                            $description .= " {$humanMadeCount} human-made row(s) will be deleted.";
                        }

                        return $description;
                    })
                    ->action(fn (EntityMatch $record) => AlignEntitySentences::beginFromScratch($record->id))
                    ->visible(fn (EntityMatch $record) => in_array($record->status, ['completed', 'failed'])),
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
            'index' => Pages\ListEntityMatches::route('/'),
            'create' => Pages\CreateEntityMatch::route('/create'),
            'view' => Pages\ViewEntityMatch::route('/{record}'),
            'edit' => Pages\EditEntityAlignment::route('/{record}/edit'),
        ];
    }

    private static function formatEntityOptionLabel(Entity $record): string
    {
        $missing = [];
        if (! filled($record->signature)) {
            $missing[] = 'signature';
        }
        if ((int) ($record->sentences_count ?? 0) === 0) {
            $missing[] = 'sentences';
        }

        $language = $record->language?->name ?? '?';

        if ($missing === []) {
            return "[{$language}] {$record->name}";
        }

        return "[{$language}] {$record->name} (needs: ".implode(', ', $missing).')';
    }
}
