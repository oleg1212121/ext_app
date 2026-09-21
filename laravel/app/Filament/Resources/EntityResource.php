<?php

namespace App\Filament\Resources;

use App\Classes\TextSignatureService;
use App\Filament\Resources\EntityResource\Pages;
use App\Filament\Resources\EntityResource\RelationManagers;
use App\Jobs\AlignEntitySentences;
use App\Jobs\GenerateEntitySignature;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\Language;
use App\Models\Work;
use Filament\Actions;
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
                Actions\Action::make('manageSentences')
                    ->label('Sentences')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (Entity $record): string => static::getUrl('edit', ['record' => $record])),
                Actions\Action::make('generateSignature')
                    ->label('Signature')
                    ->icon('heroicon-o-cpu-chip')
                    ->action(fn (Entity $record) => GenerateEntitySignature::dispatch(
                        $record->id,
                        $record->file_path,
                    ))
                    ->requiresConfirmation()
                    ->visible(fn (Entity $record) => $record->file_path !== null),
                Actions\Action::make('findMatch')
                    ->label('Find Match')
                    ->icon('heroicon-o-language')
                    ->color('info')
                    ->form(function (Entity $record): array {
                        $service = TextSignatureService::create();
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
                    ->action(function (Entity $record, array $data) {
                        $aId = min($record->id, (int) $data['other_entity_id']);
                        $bId = max($record->id, (int) $data['other_entity_id']);

                        EntityMatch::query()
                            ->where('a_entity_id', $aId)
                            ->where('b_entity_id', $bId)
                            ->get()
                            ->each(function (EntityMatch $existing) {
                                $existing->meaningMatches()->delete();
                                $existing->delete();
                            });

                        $entityMatch = EntityMatch::create([
                            'a_entity_id' => $aId,
                            'b_entity_id' => $bId,
                            'status' => 'pending',
                        ]);

                        AlignEntitySentences::beginFromScratch($entityMatch->id);

                        Notification::make()
                            ->title('Alignment started')
                            ->body('Linking sentences between entities')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Entity $record) => $record->signature !== null && $record->sentences()->exists()),
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
