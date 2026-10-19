<?php

namespace App\Filament\Resources\WordResource\RelationManagers;

use App\Models\Form;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use App\Support\LemmaForms;
use App\Support\StressMarks;
use App\Support\WordLookupKey;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TranslationsRelationManager extends RelationManager
{
    protected static string $relationship = 'translationLinks';

    protected static ?string $title = 'Translations';

    public function table(Table $table): Table
    {
        // The relationship must stay a Relation (the table calls ->getQuery()
        // on it): take the a-side hasMany and orWhere the b-side, giving
        // word_a_id = owner OR word_b_id = owner.
        return $table
            ->defaultSort('id')
            ->relationship(function (RelationManager $livewire) {
                $owner = $livewire->getOwnerRecord();

                return $owner->translationLinks()
                    ->with(['wordA.language', 'wordA.wordClass', 'wordB.language', 'wordB.wordClass'])
                    ->orWhere('word_b_id', $owner->getKey());
            })
            ->columns([
                TextColumn::make('translation')
                    ->label('Translation')
                    ->state(function (WordTranslation $record, RelationManager $livewire) {
                        return $record->otherWord((int) $livewire->getOwnerRecord()->getKey())?->word;
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->whereHas('wordA', fn (Builder $query) => $query->where('word', 'like', "%{$search}%"))
                            ->orWhereHas('wordB', fn (Builder $query) => $query->where('word', 'like', "%{$search}%")),
                    )),
                TextColumn::make('translation_language')
                    ->label('Language')
                    ->state(function (WordTranslation $record, RelationManager $livewire) {
                        return $record->otherWord((int) $livewire->getOwnerRecord()->getKey())?->language?->name;
                    }),
                TextColumn::make('translation_class')
                    ->label('Class')
                    ->state(function (WordTranslation $record, RelationManager $livewire) {
                        return $record->otherWord((int) $livewire->getOwnerRecord()->getKey())?->wordClass?->title;
                    }),
            ])
            ->headerActions([
                $this->attachTranslationAction(),
                $this->createWordAndLinkAction(),
            ])
            ->recordActions([
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected function attachTranslationAction(): Actions\Action
    {
        return Actions\Action::make('attachTranslation')
            ->label('Attach translation')
            ->icon('heroicon-m-link')
            ->color('gray')
            ->schema([
                Select::make('translation_word_id')
                    ->label('Word')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(function (string $search, RelationManager $livewire): array {
                        // The stored l_word key is the shared normalization
                        // (lowercase, no combining marks): a stressed search
                        // string must resolve against it too.
                        $key = WordLookupKey::normalize($search);

                        return Word::query()
                            ->where('language_id', '!=', $livewire->getOwnerRecord()->language_id)
                            ->where(function (Builder $query) use ($search, $key): void {
                                $query
                                    ->where('word', 'like', "%{$search}%")
                                    ->orWhere('l_word', 'like', "%{$key}%");
                            })
                            ->with(['language', 'wordClass'])
                            ->orderBy('word')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Word $word): array => [$word->getKey() => $word->dictionaryLabel()])
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => Word::query()
                        ->with(['language', 'wordClass'])
                        ->find($value)
                        ?->dictionaryLabel())
                    ->rules([
                        fn (RelationManager $livewire): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($livewire): void {
                            $owner = $livewire->getOwnerRecord();
                            $target = Word::query()->find($value);

                            if ($target === null) {
                                $fail('The selected word no longer exists.');

                                return;
                            }

                            if ((int) $target->language_id === (int) $owner->language_id) {
                                $fail('A translation must be a word in a different language.');

                                return;
                            }

                            if (WordTranslation::isLinked((int) $owner->getKey(), (int) $target->getKey())) {
                                $fail('These words are already linked as translations.');
                            }
                        },
                    ]),
            ])
            ->action(function (array $data, RelationManager $livewire): void {
                WordTranslation::link((int) $livewire->getOwnerRecord()->getKey(), (int) $data['translation_word_id']);

                Notification::make()
                    ->title('Translation linked.')
                    ->success()
                    ->send();
            });
    }

    protected function createWordAndLinkAction(): Actions\Action
    {
        return Actions\Action::make('createWordAndLink')
            ->label('Create word & link')
            ->icon('heroicon-m-plus-circle')
            ->color('gray')
            ->schema([
                TextInput::make('word')
                    ->required()
                    ->maxLength(256),
                Select::make('language_id')
                    ->label('Language')
                    ->live()
                    ->options(fn (RelationManager $livewire): array => Language::query()
                        ->whereKeyNot($livewire->getOwnerRecord()->language_id)
                        ->orderBy('sort_order')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('word_class_id')
                    ->label('Word class')
                    ->options(fn (Get $get): array => WordClass::query()
                        ->where('language_id', (int) ($get('language_id') ?? 0))
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, RelationManager $livewire): void {
                // The shared creator hygiene: the display word stores plain
                // (no combining stress marks for ru/be/uk), the lookup key
                // goes through WordLookupKey, and the created word carries
                // itself as its lemma form.
                $language = Language::query()->findOrFail($data['language_id']);
                $stripsMarks = StressMarks::stripsFor((string) $language->code);
                $display = $stripsMarks ? StressMarks::strip($data['word']) : $data['word'];

                // Uniqueness keys on (l_word, language, class): the typed
                // word is checked by its lookup key, so the case/ё variant
                // of an existing row is reused, not minted. The typed
                // display only fills a genuinely new row.
                $target = Word::query()->firstOrCreate(
                    [
                        'l_word' => WordLookupKey::normalize($data['word']),
                        'language_id' => $data['language_id'],
                        'word_class_id' => $data['word_class_id'],
                    ],
                    ['word' => $display],
                );

                if ($target->wasRecentlyCreated) {
                    Form::upsert(
                        LemmaForms::rows($target->word, [], $target->id, $stripsMarks),
                        ['form', 'word_id'],
                    );
                }

                WordTranslation::link((int) $livewire->getOwnerRecord()->getKey(), (int) $target->getKey());

                Notification::make()
                    ->title($target->wasRecentlyCreated
                        ? "Created word «{$target->word}» and linked it as a translation."
                        : "Linked the existing word «{$target->word}».")
                    ->success()
                    ->send();
            });
    }
}
