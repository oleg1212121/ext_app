<?php

namespace App\Filament\Resources\EntityResource\RelationManagers;

use App\Classes\Enrichment\Annotation;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\EntitySentenceStore;
use App\Classes\SparseOrderService;
use App\Enums\SentenceAnchor;
use App\Models\Entity;
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
use Illuminate\Support\Collection;
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
                // One preview column per registry annotation (ADR 0067) —
                // the enrichers own their admin preview.
                ...collect(app(EnricherRegistry::class)->annotations())
                    ->map(fn (Annotation $annotation): TextColumn => ($annotation->adminPreview)())
                    ->all(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->createAnother(false)
                    ->using(function (array $data, RelationManager $livewire): EntitySentence {
                        $owner = $livewire->getOwnerRecord();
                        $anchor = $this->anchorFromOption($data['insert_after']);
                        unset($data['insert_after']);

                        return app(EntitySentenceStore::class)->insert($owner, $data, $anchor);
                    }),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->using(function (array $data, RelationManager $livewire, Model $record): EntitySentence {
                        $owner = $livewire->getOwnerRecord();
                        $insertAfter = (string) $data['insert_after'];
                        unset($data['insert_after']);

                        // Only a changed drop position re-places the record —
                        // a content-only edit must not renumber it.
                        $anchor = $this->positionChanged($owner, $record, $insertAfter)
                            ? $this->anchorFromOption($insertAfter)
                            : null;

                        return app(EntitySentenceStore::class)->update($record, $data, $anchor);
                    }),
                Actions\DeleteAction::make()
                    ->using(function (Model $record): bool {
                        app(EntitySentenceStore::class)->delete($record);

                        return true;
                    }),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->using(function (Collection $records): void {
                            app(EntitySentenceStore::class)->deleteMany($records);
                        }),
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
