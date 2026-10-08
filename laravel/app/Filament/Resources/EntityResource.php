<?php

namespace App\Filament\Resources;

use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\EntityMatchCreationService;
use App\Classes\TextSignatureService;
use App\Exceptions\CrossWorkEntityPair;
use App\Exceptions\ProcessingLimitReached;
use App\Filament\Resources\EntityResource\Pages;
use App\Filament\Resources\EntityResource\RelationManagers;
use App\Jobs\EnrichEntitySentences;
use App\Jobs\GenerateEntitySignature;
use App\Models\Entity;
use App\Models\Language;
use App\Models\User;
use App\Models\Work;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EntityResource extends Resource
{
    protected static ?string $model = Entity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Entities';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('work_id')
                    ->label('Work')
                    ->relationship('work', 'title')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('title')->required()->maxLength(512),
                        TextInput::make('author')->maxLength(512),
                        Select::make('original_language_id')
                            ->label('Original language')
                            ->options(fn (): array => Language::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->createOptionUsing(fn (array $data): int => Work::query()->create($data)->id)
                    ->required(),
                Select::make('language_id')
                    ->label('Language')
                    ->options(fn (): array => Language::query()->enabled()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(512),
                TextInput::make('label')
                    ->label('Translator / edition note')
                    ->maxLength(256),
                Textarea::make('description')
                    ->maxLength(2048),
                Toggle::make('is_restricted')
                    ->label('Restricted (only admin and granted users can read)')
                    ->default(true),
                Toggle::make('is_approved')
                    ->label('Approved (entity and its alignments are edit-locked)')
                    ->default(false),
                TextInput::make('signature'),
                FileUpload::make('file')
                    ->label('Text File')
                    ->disk('local')
                    ->acceptedFileTypes(['text/plain'])
                    ->maxSize(10240)
                    ->directory(fn (Get $get): string => 'entities/'.(Language::find($get('language_id'))?->code ?? 'misc')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('language.name')
                    ->label('Language'),
                TextColumn::make('work.title')
                    ->label('Work')
                    ->searchable(),
                TextColumn::make('label')
                    ->label('Translator / edition'),
                TextColumn::make('description')
                    ->limit(30),
                TextColumn::make('file_path')
                    ->limit(30),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'processing' => 'warning',
                        'failed' => 'danger',
                        default => 'success',
                    }),
                IconColumn::make('is_restricted')
                    ->boolean()
                    ->label('Restricted'),
                IconColumn::make('is_approved')
                    ->boolean()
                    ->label('Approved'),
                TextColumn::make('uploader.name')
                    ->label('Uploader'),
                TextColumn::make('text_hash')
                    ->label('Text hash')
                    ->limit(12)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sentences_count')
                    ->counts('sentences')
                    ->label('Sentences'),
                TextColumn::make('created_at')
                    ->dateTime(),
                TextColumn::make('updated_at')
                    ->dateTime(),
            ])
            ->filters([
                SelectFilter::make('language')
                    ->relationship('language', 'name'),
                SelectFilter::make('work')
                    ->relationship('work', 'title'),
                TernaryFilter::make('is_restricted')
                    ->label('Restricted')
                    ->trueLabel('Restricted only')
                    ->falseLabel('Public only')
                    ->native(false),
                TernaryFilter::make('is_approved')
                    ->label('Approved')
                    ->trueLabel('Approved only')
                    ->falseLabel('Not approved only')
                    ->native(false),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
                Action::make('manageSentences')
                    ->label('Sentences')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (Entity $record): string => static::getUrl('edit', ['record' => $record])),
                Action::make('generateSignature')
                    ->label('Signature')
                    ->icon('heroicon-o-cpu-chip')
                    ->action(function (Entity $record) {
                        // The embedding pass runs in the background; the
                        // entity holds a processing slot until it lands.
                        $record->forceFill(['status' => 'processing'])->save();

                        GenerateEntitySignature::dispatch(
                            $record->id,
                            $record->file_path,
                        );
                    })
                    ->requiresConfirmation()
                    ->visible(fn (Entity $record) => $record->file_path !== null),
                Action::make('findMatch')
                    ->label('Find Match')
                    ->icon('heroicon-o-language')
                    ->color('info')
                    ->form(function (Entity $record): array {
                        $service = app(TextSignatureService::class);
                        $matches = $service->findCrossLanguage($record)
                            ->filter(fn (array $match): bool => $match['entity']->work_id === $record->work_id);

                        return [
                            Placeholder::make('matches_info')
                                ->label('')
                                ->content($matches->isEmpty()
                                    ? 'No matching entities of the same work found. Make sure both entities have signatures generated and share the work.'
                                    : "Found {$matches->count()} match(es):\n".$matches->map(fn ($m) => sprintf(
                                        '%s (similarity: %.4f)',
                                        $m['entity']->name,
                                        $m['similarity'],
                                    ))->implode("\n")),
                            Select::make('other_entity_id')
                                ->label('Select Entity to Align')
                                ->options($matches->mapWithKeys(fn ($m): array => [
                                    $m['entity']->id => sprintf('%s (%.4f)', $m['entity']->name, $m['similarity']),
                                ])->toArray())
                                ->required()
                                ->searchable(),
                        ];
                    })
                    // The creation module is the only writer of Entity
                    // matches: same-Work validation, canonical sides,
                    // duplicate rejection, the Processing limit and the
                    // copy-vs-pipeline decision all live there. This action
                    // resolves the two entities from the submitted form,
                    // calls the module once, and renders the outcome as
                    // notifications — never deleting anything. No knobs are
                    // offered, so nulls pass and the module's defaults apply.
                    ->action(function (Entity $record, array $data) {
                        $other = Entity::query()->findOrFail((int) $data['other_entity_id']);
                        $creator = User::query()->findOrFail((int) auth()->id());

                        try {
                            $result = (new EntityMatchCreationService)->create($creator, $record, $other);
                        } catch (CrossWorkEntityPair|ProcessingLimitReached $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($result['status'] === 'duplicate') {
                            $existing = $result['existing'];

                            // The module rejected the pair without touching
                            // anything; point the administrator at the match
                            // that is already there.
                            Notification::make()
                                ->title('Match already exists')
                                ->body("A sentence alignment for this entity pair already exists ({$existing->status}).")
                                ->warning()
                                ->actions([
                                    Action::make('viewMatch')
                                        ->label('View match')
                                        ->url(EntityMatchResource::getUrl('view', ['record' => $existing])),
                                ])
                                ->send();

                            return;
                        }

                        if ($result['status'] === 'created_from_copy') {
                            Notification::make()
                                ->title('Alignment copied')
                                ->body('An identical text pair already had a completed alignment — reused.')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Alignment started')
                            ->body('Linking sentences between entities')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Entity $record) => $record->signature !== null && $record->sentences()->exists()),
                Action::make('enrichSentences')
                    ->label('Enrich')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->action(function (Entity $record) {
                        // Stress marks / phrasal verbs run locally in the
                        // background pipeline (ADR 0052).
                        EnrichEntitySentences::begin($record->id);

                        Notification::make()
                            ->title('Enrichment started')
                            ->body('Computing stress marks and phrasal verbs')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Entity $record) => $record->sentences()->exists()
                        && app(EnricherRegistry::class)->forLanguage($record->language?->code ?? '') !== []),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                    Actions\BulkAction::make('generateSignatures')
                        ->label('Generate Signatures')
                        ->icon('heroicon-o-cpu-chip')
                        ->action(function ($records) {
                            $records->each(function ($record) {
                                if ($record->file_path) {
                                    GenerateEntitySignature::dispatch(
                                        $record->id,
                                        $record->file_path,
                                    );
                                }
                            });
                        })
                        ->requiresConfirmation(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SentencesRelationManager::class,
            RelationManagers\GrantedUsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEntities::route('/'),
            'create' => Pages\CreateEntity::route('/create'),
            'edit' => Pages\EditEntity::route('/{record}/edit'),
        ];
    }
}
