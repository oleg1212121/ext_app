<?php

namespace App\Filament\Resources\RuEntityResource\RelationManagers;

use App\Classes\SparseOrderService;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\SentenceType;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
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
                    ->options(function (?RuEntitySentence $record, RelationManager $livewire): array {
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
                    ->default(function (?RuEntitySentence $record, RelationManager $livewire): string {
                        $owner = $livewire->getOwnerRecord();

                        if (! $record) {
                            $last = $owner->sentences()->orderByDesc('order')->first();

                            return $last
                                ? (string) $last->getKey()
                                : (string) SparseOrderService::BEGINNING_SENTINEL;
                        }

                        $previous = $owner->sentences()
                            ->where('order', '<', $record->order)
                            ->orderByDesc('order')
                            ->first();

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
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->createAnother(false)
                    ->using(function (array $data, RelationManager $livewire): RuEntitySentence {
                        $owner = $livewire->getOwnerRecord();
                        $data['order'] = $this->computeOrder($owner, null, $data['insert_after']);
                        unset($data['insert_after']);

                        $sentence = new RuEntitySentence($data);
                        $owner->sentences()->save($sentence);

                        return $sentence;
                    }),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->using(function (array $data, RelationManager $livewire, Model $record): RuEntitySentence {
                        $owner = $livewire->getOwnerRecord();
                        $data['order'] = $this->computeOrder($owner, $record, $data['insert_after']);
                        unset($data['insert_after']);

                        $record->update($data);

                        return $record;
                    }),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function computeOrder(RuEntity $owner, ?RuEntitySentence $excluding, string $insertAfterId): int
    {
        $service = app(SparseOrderService::class);

        $sentences = $owner->sentences()
            ->when($excluding, fn ($query) => $query->whereKeyNot($excluding->getKey()))
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'order']);

        $isBeginning = $insertAfterId === (string) SparseOrderService::BEGINNING_SENTINEL;

        $previous = null;
        $next = null;

        if ($isBeginning) {
            $next = $sentences->first();
        } else {
            $found = false;

            foreach ($sentences as $sentence) {
                if ($found) {
                    $next = $sentence;
                    break;
                }

                if ((string) $sentence->getKey() === $insertAfterId) {
                    $previous = $sentence;
                    $found = true;
                }
            }
        }

        $order = $service->between(
            $previous ? (int) $previous->order : null,
            $next ? (int) $next->order : null,
        );

        if ($order !== null) {
            return $order;
        }

        $service->rebalanceAll(RuEntitySentence::class, 'ru_entity_id', $owner->id);

        $sentences = $owner->sentences()
            ->when($excluding, fn ($query) => $query->whereKeyNot($excluding->getKey()))
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'order']);

        if ($isBeginning) {
            $previous = null;
            $next = $sentences->first();
        } else {
            $found = false;
            $previous = null;
            $next = null;

            foreach ($sentences as $sentence) {
                if ($found) {
                    $next = $sentence;
                    break;
                }

                if ((string) $sentence->getKey() === $insertAfterId) {
                    $previous = $sentence;
                    $found = true;
                }
            }
        }

        return $service->between(
            $previous ? (int) $previous->order : null,
            $next ? (int) $next->order : null,
        ) ?? $service->initial($sentences->count());
    }
}
