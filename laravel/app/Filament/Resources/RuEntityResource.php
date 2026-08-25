<?php

namespace App\Filament\Resources;

use App\Classes\TextSignatureService;
use App\Filament\Resources\RuEntityResource\Pages;
use App\Filament\Resources\RuEntityResource\RelationManagers;
use App\Jobs\AlignEntitySentences;
use App\Jobs\GenerateEntitySignature;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RuEntityResource extends Resource
{
    protected static ?string $model = RuEntity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Entities';

    protected static ?string $label = 'Russian Entity';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(512),
                Textarea::make('description')
                    ->maxLength(2048),
                Toggle::make('is_restricted')
                    ->label('Restricted (only admin and granted users can read)')
                    ->default(true),
                TextInput::make('signature'),
                FileUpload::make('file')
                    ->label('Text File')
                    ->disk('local')
                    ->acceptedFileTypes(['text/plain'])
                    ->directory('entities/ru'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('description')
                    ->limit(30),
                TextColumn::make('file_path')
                    ->limit(30),
                IconColumn::make('is_restricted')
                    ->boolean()
                    ->label('Restricted'),
                TextColumn::make('sentences_count')
                    ->counts('sentences')
                    ->label('Sentences'),
                TextColumn::make('created_at')
                    ->dateTime(),
                TextColumn::make('updated_at')
                    ->dateTime(),
            ])
            ->filters([
                TernaryFilter::make('is_restricted')
                    ->label('Restricted')
                    ->trueLabel('Restricted only')
                    ->falseLabel('Public only')
                    ->native(false),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
                Actions\Action::make('manageSentences')
                    ->label('Sentences')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (RuEntity $record): string => static::getUrl('edit', ['record' => $record])),
                Actions\Action::make('generateSignature')
                    ->label('Signature')
                    ->icon('heroicon-o-cpu-chip')
                    ->action(fn (RuEntity $record) => GenerateEntitySignature::dispatch(
                        $record->id,
                        $record->file_path,
                        'ru',
                    ))
                    ->requiresConfirmation()
                    ->visible(fn (RuEntity $record) => $record->file_path !== null),
                Actions\Action::make('findMatch')
                    ->label('Find Match')
                    ->icon('heroicon-o-language')
                    ->color('info')
                    ->form([
                        Placeholder::make('matches_info')
                            ->label('')
                            ->content(function (RuEntity $record) {
                                $service = TextSignatureService::create();
                                $matches = $service->findCrossLanguage($record);

                                if ($matches->isEmpty()) {
                                    return 'No matching English entities found. Make sure both entities have signatures generated.';
                                }

                                $lines = $matches->map(fn ($m) => sprintf(
                                    '%s (similarity: %.4f)',
                                    $m['entity']->name,
                                    $m['similarity'],
                                ))->implode("\n");

                                return "Found {$matches->count()} match(es):\n{$lines}";
                            }),
                        Select::make('en_entity_id')
                            ->label('Select English Entity to Align')
                            ->options(function (RuEntity $record) {
                                $service = TextSignatureService::create();
                                $matches = $service->findCrossLanguage($record);

                                return $matches->mapWithKeys(fn ($m) => [
                                    $m['entity']->id => sprintf(
                                        '%s (%.4f)',
                                        $m['entity']->name,
                                        $m['similarity'],
                                    ),
                                ])->toArray();
                            })
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (RuEntity $record, array $data) {
                        // Delete existing alignment for this pair
                        EnRuEntityMatch::where('en_entity_id', $data['en_entity_id'])
                            ->where('ru_entity_id', $record->id)
                            ->get()
                            ->each(function (EnRuEntityMatch $existing) {
                                $existing->meaningMatches()->delete();
                                $existing->delete();
                            });

                        $entityMatch = EnRuEntityMatch::create([
                            'en_entity_id' => $data['en_entity_id'],
                            'ru_entity_id' => $record->id,
                            'status' => 'pending',
                        ]);

                        AlignEntitySentences::beginFromScratch($entityMatch->id);

                        Notification::make()
                            ->title('Alignment started')
                            ->body('Linking sentences between entities')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (RuEntity $record) => $record->signature !== null && $record->sentences()->exists()),
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
                                        'ru',
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
            'index' => Pages\ListRuEntities::route('/'),
            'create' => Pages\CreateRuEntity::route('/create'),
            'edit' => Pages\EditRuEntity::route('/{record}/edit'),
        ];
    }
}
