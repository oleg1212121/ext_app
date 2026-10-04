<?php

namespace App\Filament\Resources\EntityResource\RelationManagers;

use App\Classes\SentenceOrderService;
use App\Classes\SparseOrderService;
use App\Enums\SentenceAnchor;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\SentenceType;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SentencesRelationManager extends RelationManager
{
    protected static string $relationship = 'sentences';

    protected static ?string $title = 'Sentences';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('insert_after')
                    ->label('Insert after')
                    ->options(function (?EntitySentence $record, RelationManager $livewire): array {
                        $owner = $livewire->getOwnerRecord();

                        $sentences = $owner->sentences()
                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->orderBy('order')
                            ->get(['id', 'content']);

                        $options = [
                            (string) SparseOrderService::BEGINNING_SENTINEL => '— At the beginning —',
                        ];

                        foreach ($sentences as $sentence) {
                            $options[(string) $sentence->getKey()] = Str::limit($sentence->content, 60);
                        }

                        return $options;
                    })
                    ->default(function (?EntitySentence $record, RelationManager $livewire): string {
                        $owner = $livewire->getOwnerRecord();

                        if (! $record) {
                            $last = $owner->sentences()->orderByDesc('order')->first();

                            return $last
                                ? (string) $last->getKey()
                                : (string) SparseOrderService::BEGINNING_SENTINEL;
                        }

                        $previous = $this->predecessorOf($owner, $record);

                        return $previous
                            ? (string) $previous->getKey()
                            : (string) SparseOrderService::BEGINNING_SENTINEL;
                    })
                    ->required(),
                Textarea::make('content')
                    ->required()
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Select::make('sentence_type_id')
                    ->label('Sentence type')
                    ->relationship('sentenceType', 'name')
                    ->default(fn () => SentenceType::query()->where('name', 'sentence')->value('id'))
                    ->required()
                    ->searchable()
                    ->preload(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('content')
            ->inverseRelationship('entity')
            ->defaultSort('order')
            ->columns([
                TextColumn::make('order')
                    ->sortable(),
                TextColumn::make('content')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('sentenceType.name')
                    ->label('Type'),
                TextColumn::make('stressed_content')
                    ->label('Stress marks')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('phrasal_verbs.label')
                    ->label('Phrasal verbs')
                    ->state(fn (EntitySentence $record): string => collect($record->phrasal_verbs ?? [])
                        ->map(fn (array $hit) => $hit['verb'].' '.implode(' ', $hit['particles'] ?? []))
                        ->implode(', '))
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->createAnother(false)
                    ->using(function (array $data, RelationManager $livewire): EntitySentence {
                        $owner = $livewire->getOwnerRecord();

                        $order = DB::transaction(
                            fn (): int => app(SentenceOrderService::class)
                                ->place($owner->id, $this->anchorFromOption($data['insert_after'])),
                        );
                        unset($data['insert_after']);

                        return $owner->sentences()->create([...$data, 'order' => $order]);
                    })
                    ->after(fn (RelationManager $livewire) => EntityMatch::syncTotalsForEntity($livewire->getOwnerRecord()->id)),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->using(function (array $data, RelationManager $livewire, Model $record): EntitySentence {
                        $owner = $livewire->getOwnerRecord();
                        $insertAfter = (string) $data['insert_after'];
                        unset($data['insert_after']);

                        // Only a changed drop position re-places the record —
                        // a content-only edit must not renumber it.
                        if ($this->positionChanged($owner, $record, $insertAfter)) {
                            DB::transaction(function () use ($owner, $record, $insertAfter): void {
                                app(SentenceOrderService::class)->place(
                                    $owner->id,
                                    $this->anchorFromOption($insertAfter),
                                    $record->getKey(),
                                );
                            });
                            $record->refresh();
                        }

                        $record->update($data);

                        return $record;
                    })
                    ->after(fn (RelationManager $livewire) => EntityMatch::syncTotalsForEntity($livewire->getOwnerRecord()->id)),
                Actions\DeleteAction::make()
                    ->after(fn (RelationManager $livewire) => EntityMatch::syncTotalsForEntity($livewire->getOwnerRecord()->id)),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->after(fn (RelationManager $livewire) => EntityMatch::syncTotalsForEntity($livewire->getOwnerRecord()->id)),
                ]),
            ]);
    }

    /**
     * The form's "insert after" select spells the beginning as the
     * BEGINNING_SENTINEL string; every other option is a sentence id.
     */
    private function anchorFromOption(int|string $insertAfterId): SentenceAnchor
    {
        if ((string) $insertAfterId === (string) SparseOrderService::BEGINNING_SENTINEL) {
            return SentenceAnchor::beginning();
        }

        return SentenceAnchor::after((int) $insertAfterId);
    }

    /**
     * Whether the chosen insert position differs from the record's current
     * place in document order — its predecessor under the (order, id)
     * sort.
     */
    private function positionChanged(Entity $owner, Model $record, string $insertAfterId): bool
    {
        $predecessor = $this->predecessorOf($owner, $record);

        return $insertAfterId !== ($predecessor !== null
            ? (string) $predecessor->getKey()
            : (string) SparseOrderService::BEGINNING_SENTINEL);
    }

    /**
     * The sentence immediately before $record in the (order, id) document
     * order, or null at the beginning.
     */
    private function predecessorOf(Entity $owner, EntitySentence $record): ?EntitySentence
    {
        return $owner->sentences()
            ->where(function ($query) use ($record): void {
                $query
                    ->where('order', '<', $record->order)
                    ->orWhere(fn ($q) => $q->where('order', $record->order)->where('id', '<', $record->id));
            })
            ->orderByDesc('order')
            ->orderByDesc('id')
            ->first();
    }
}
