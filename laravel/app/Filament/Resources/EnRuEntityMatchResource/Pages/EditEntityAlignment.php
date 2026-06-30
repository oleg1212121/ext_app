<?php

namespace App\Filament\Resources\EnRuEntityMatchResource\Pages;

use App\Classes\AlignmentEditorDraftStore;
use App\Classes\AlignmentEditorPersister;
use App\Classes\AlignmentEditorPresenter;
use App\Classes\SparseOrderService;
use App\Filament\Resources\EnRuEntityMatchResource;
use App\Models\EnRuEntityMatch;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EditEntityAlignment extends Page
{
    use InteractsWithRecord {
        getRecord as getResolvedRecord;
    }

    protected static string $resource = EnRuEntityMatchResource::class;

    protected static ?string $title = 'Edit Alignment';

    protected static ?string $navigationLabel = 'Edit Alignment';

    protected string $view = 'filament.pages.edit-entity-alignment';

    public int $meaningPage = 1;

    public int $meaningPerPage = 25;

    public int $meaningRowsTotal = 0;

    public int $meaningLastPage = 1;

    /** @var list<array<string, mixed>> */
    public array $visibleMeaningRows = [];

    public int $unmatchedEnPage = 1;

    public int $unmatchedRuPage = 1;

    public int $unmatchedPerPage = 15;

    public int $unmatchedEnTotal = 0;

    public int $unmatchedRuTotal = 0;

    public int $unmatchedEnLastPage = 1;

    public int $unmatchedRuLastPage = 1;

    /** @var list<array<string, mixed>> */
    public array $visibleUnmatchedEn = [];

    /** @var list<array<string, mixed>> */
    public array $visibleUnmatchedRu = [];

    public bool $isDirty = false;

    public bool $showAddModal = false;

    public string $addLang = 'en';

    public int $addAfterOrder = 0;

    public string $addContent = '';

    public bool $showConnectModal = false;

    public string $connectLang = 'en';

    public string $connectSentenceKey = '';

    public string $connectTargetMeaningKey = '';

    public int $connectMode = 0;

    public int $connectInsertAfterMeaningOrder = 0;

    public function getMaxContentWidth(): \Filament\Support\Enums\Width|string|null
    {
        return \Filament\Support\Enums\Width::Full;
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->initializeDraft();
        $this->refreshVisibleData();
    }

    public function getRecord(): EnRuEntityMatch
    {
        /** @var EnRuEntityMatch $record */
        $record = $this->getResolvedRecord();

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function goToMeaningPage(int $page): void
    {
        $this->meaningPage = max(1, $page);
        $this->refreshVisibleData();
    }

    public function goToUnmatchedEnPage(int $page): void
    {
        $this->unmatchedEnPage = max(1, $page);
        $this->refreshVisibleData();
    }

    public function goToUnmatchedRuPage(int $page): void
    {
        $this->unmatchedRuPage = max(1, $page);
        $this->refreshVisibleData();
    }

    public function initializeDraft(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        if ($this->draftStore()->get($this->getRecord()->id, $userId) !== null) {
            return;
        }

        $draft = app(AlignmentEditorPresenter::class)->toDraft($this->getRecord());
        $this->draftStore()->put($this->getRecord()->id, $userId, $draft);
        $this->isDirty = false;
    }

    public function loadDraftFromDatabase(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        $this->draftStore()->forget($this->getRecord()->id, $userId);
        $draft = app(AlignmentEditorPresenter::class)->toDraft($this->getRecord());
        $this->draftStore()->put($this->getRecord()->id, $userId, $draft);
        $this->isDirty = false;
        $this->refreshVisibleData();
    }

    public function save(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        app(AlignmentEditorPersister::class)->persist($this->getRecord(), $this->getDraft());
        $this->loadDraftFromDatabase();

        Notification::make()
            ->title('Alignment saved')
            ->success()
            ->send();
    }

    public function discardChanges(): void
    {
        $this->loadDraftFromDatabase();

        Notification::make()
            ->title('Changes discarded')
            ->send();
    }

    public function updateSentenceContent(string $lang, string $sentenceKey, string $content): void
    {
        $draft = $this->getDraft();

        if (! $this->updateSentenceInDraft($draft, $lang, $sentenceKey, $content)) {
            return;
        }

        $this->putDraft($draft);
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function insertMeaningRowAfter(string $rowKey): void
    {
        $draft = $this->getDraft();
        $rowIndex = $this->findMeaningRowIndex($draft, $rowKey);

        if ($rowIndex === null) {
            return;
        }

        $row = $draft['meaning_rows'][$rowIndex];
        $meaningPlacement = $this->meaningOrderPlacement($draft, null, (int) $row['order']);
        $this->applyMeaningOrders($draft, $meaningPlacement['items']);

        $presenter = app(AlignmentEditorPresenter::class);
        $newRow = $presenter->newMeaningRow($meaningPlacement['order']);

        foreach (['en', 'ru'] as $lang) {
            $sideKey = $lang === 'en' ? 'en_sentences' : 'ru_sentences';
            $sentencePlacement = $this->sentenceOrderPlacement(
                draft: $draft,
                lang: $lang,
                movingKey: null,
                afterOrder: $this->lastSentenceOrderAtOrBeforeRow($draft, $lang, (int) $row['order']),
            );
            $this->applySentenceOrders($draft, $lang, $sentencePlacement['items']);

            $newRow[$sideKey][] = $presenter->sentencePayload(
                id: null,
                content: '',
                order: $sentencePlacement['order'],
                tempId: 'tmp-'.Str::uuid(),
            );
        }

        $draft['meaning_rows'][] = $newRow;
        usort($draft['meaning_rows'], fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $this->putDraft($draft);
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function saveMeaningRow(string $rowKey): void
    {
        $draft = $this->getDraft();
        $rowIndex = $this->findMeaningRowIndex($draft, $rowKey);

        if ($rowIndex === null) {
            return;
        }

        $row = $draft['meaning_rows'][$rowIndex];

        if ($row['id'] === null && ! $this->hasFilledSentencesOnBothSides($row)) {
            Notification::make()
                ->title('Add both EN and RU sentences before saving this row')
                ->danger()
                ->send();

            return;
        }

        app(AlignmentEditorPersister::class)->persist($this->getRecord(), $draft);
        $this->loadDraftFromDatabase();

        Notification::make()
            ->title('Row saved')
            ->success()
            ->send();
    }

    public function unlinkMeaningRow(string $rowKey): void
    {
        $draft = $this->getDraft();
        $rowIndex = $this->findMeaningRowIndex($draft, $rowKey);

        if ($rowIndex === null) {
            return;
        }

        $row = $draft['meaning_rows'][$rowIndex];

        foreach ($row['en_sentences'] as $sentence) {
            $draft['unmatched_en'][] = $sentence;
        }

        foreach ($row['ru_sentences'] as $sentence) {
            $draft['unmatched_ru'][] = $sentence;
        }

        unset($draft['meaning_rows'][$rowIndex]);
        $draft['meaning_rows'] = array_values($draft['meaning_rows']);

        usort($draft['unmatched_en'], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        usort($draft['unmatched_ru'], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $this->removeEmptyMeaningRows($draft);
        $this->putDraft($draft);

        app(AlignmentEditorPersister::class)->persist($this->getRecord(), $draft);
        $this->loadDraftFromDatabase();

        Notification::make()
            ->title('Link removed')
            ->success()
            ->send();
    }

    public function moveSentence(string $lang, string $sentenceKey, string $direction): void
    {
        $draft = $this->getDraft();
        $all = $this->allSentencesForLang($draft, $lang);
        $index = $this->findSentenceIndex($all, $sentenceKey);

        if ($index === null) {
            return;
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapIndex < 0 || $swapIndex >= count($all)) {
            return;
        }

        $afterOrder = $direction === 'up'
            ? ($index > 1 ? (int) $all[$index - 2]['order'] : SparseOrderService::BEGINNING_SENTINEL)
            : (int) $all[$swapIndex]['order'];

        $placement = $this->sentenceOrderPlacement(
            draft: $draft,
            lang: $lang,
            movingKey: $sentenceKey,
            afterOrder: $afterOrder,
        );

        $all = $placement['items'];
        $all[] = [
            'key' => $sentenceKey,
            'order' => $placement['order'],
        ];

        $this->applySentenceOrders($draft, $lang, $all);
        $this->putDraft($draft);
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function deleteSentence(string $lang, string $sentenceKey): void
    {
        $draft = $this->getDraft();
        $this->removeSentenceFromDraft($draft, $lang, $sentenceKey);
        $this->removeEmptyMeaningRows($draft);
        $this->putDraft($draft);
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function unlinkSentence(string $lang, string $sentenceKey): void
    {
        $draft = $this->getDraft();
        $sentence = $this->extractSentenceFromMeaningRows($draft, $lang, $sentenceKey);

        if ($sentence === null) {
            return;
        }

        $unmatchedKey = $lang === 'en' ? 'unmatched_en' : 'unmatched_ru';
        $draft[$unmatchedKey][] = $sentence;
        usort($draft[$unmatchedKey], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $this->removeEmptyMeaningRows($draft);
        $this->putDraft($draft);
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function openAddModal(string $lang): void
    {
        $this->addLang = $lang;
        $this->addAfterOrder = SparseOrderService::BEGINNING_SENTINEL;
        $this->addContent = '';
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
    }

    public function addSentence(): void
    {
        $content = trim($this->addContent);

        if ($content === '') {
            return;
        }

        $draft = $this->getDraft();
        $presenter = app(AlignmentEditorPresenter::class);
        $tempId = 'tmp-'.Str::uuid();
        $placement = $this->sentenceOrderPlacement(
            draft: $draft,
            lang: $this->addLang,
            movingKey: null,
            afterOrder: $this->addAfterOrder,
        );
        $this->applySentenceOrders($draft, $this->addLang, $placement['items']);

        $newSentence = $presenter->sentencePayload(
            id: null,
            content: $content,
            order: $placement['order'],
            tempId: $tempId,
        );

        $unmatchedKey = $this->addLang === 'en' ? 'unmatched_en' : 'unmatched_ru';

        $draft[$unmatchedKey][] = $newSentence;
        usort($draft[$unmatchedKey], fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $this->putDraft($draft);
        $this->showAddModal = false;
        $this->addContent = '';
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function openConnectModal(string $lang, string $sentenceKey): void
    {
        $this->connectLang = $lang;
        $this->connectSentenceKey = $sentenceKey;
        $this->connectTargetMeaningKey = $this->visibleMeaningRows[0]['key'] ?? '';
        $this->connectMode = $this->visibleMeaningRows === [] ? 1 : 0;
        $this->connectInsertAfterMeaningOrder = count($this->visibleMeaningRows) > 0
            ? max(array_column($this->visibleMeaningRows, 'order'))
            : SparseOrderService::BEGINNING_SENTINEL;
        $this->showConnectModal = true;
    }

    public function closeConnectModal(): void
    {
        $this->showConnectModal = false;
    }

    public function connectSentence(): void
    {
        $draft = $this->getDraft();
        $sentence = $this->extractSentenceFromUnmatched($draft, $this->connectLang, $this->connectSentenceKey);

        if ($sentence === null) {
            return;
        }

        $sideKey = $this->connectLang === 'en' ? 'en_sentences' : 'ru_sentences';

        if ($this->connectMode === 1) {
            $presenter = app(AlignmentEditorPresenter::class);
            $placement = $this->meaningOrderPlacement($draft, null, $this->connectInsertAfterMeaningOrder);
            $this->applyMeaningOrders($draft, $placement['items']);

            $newRow = $presenter->newMeaningRow($placement['order']);
            $newRow[$sideKey][] = $sentence;
            $draft['meaning_rows'][] = $newRow;
            usort($draft['meaning_rows'], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        } else {
            foreach ($draft['meaning_rows'] as &$row) {
                if ($row['key'] !== $this->connectTargetMeaningKey) {
                    continue;
                }

                $row[$sideKey][] = $sentence;
                usort($row[$sideKey], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
                break;
            }
            unset($row);
        }

        $this->putDraft($draft);
        $this->showConnectModal = false;
        $this->markDirty();
        $this->refreshVisibleData();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getInsertOrderOptions(string $lang): array
    {
        $all = $this->allSentencesForLang($this->getDraft(), $lang);
        $options = [['value' => SparseOrderService::BEGINNING_SENTINEL, 'label' => 'Beginning (before #1)']];

        foreach ($all as $index => $sentence) {
            $options[] = [
                'value' => $sentence['order'],
                'label' => 'After #'.($index + 1),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getMeaningRowOptions(): array
    {
        $options = [];
        $rowOffset = ($this->meaningPage - 1) * $this->meaningPerPage;

        foreach ($this->visibleMeaningRows as $index => $row) {
            $enPreview = $this->sentencePreview($row['en_sentences']);
            $ruPreview = $this->sentencePreview($row['ru_sentences']);
            $options[] = [
                'value' => $row['key'],
                'label' => '#'.($rowOffset + $index + 1).': '.$enPreview.' / '.$ruPreview,
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getMeaningInsertOptions(): array
    {
        $options = [['value' => SparseOrderService::BEGINNING_SENTINEL, 'label' => 'Beginning']];
        $rowOffset = ($this->meaningPage - 1) * $this->meaningPerPage;

        foreach ($this->visibleMeaningRows as $index => $row) {
            $options[] = [
                'value' => $row['order'],
                'label' => 'After row #'.($rowOffset + $index + 1),
            ];
        }

        return $options;
    }

    private function refreshVisibleData(): void
    {
        $draft = $this->getDraft();
        $store = $this->draftStore();

        $meaning = $store->paginateMeaningRows($draft, $this->meaningPage, $this->meaningPerPage);
        $this->visibleMeaningRows = $meaning['rows'];
        $this->meaningRowsTotal = $meaning['total'];
        $this->meaningLastPage = $meaning['last_page'];
        $this->meaningPage = min($this->meaningPage, $this->meaningLastPage);

        $unmatchedEn = $store->paginateUnmatched($draft, 'en', $this->unmatchedEnPage, $this->unmatchedPerPage);
        $this->visibleUnmatchedEn = $unmatchedEn['rows'];
        $this->unmatchedEnTotal = $unmatchedEn['total'];
        $this->unmatchedEnLastPage = $unmatchedEn['last_page'];
        $this->unmatchedEnPage = min($this->unmatchedEnPage, $this->unmatchedEnLastPage);

        $unmatchedRu = $store->paginateUnmatched($draft, 'ru', $this->unmatchedRuPage, $this->unmatchedPerPage);
        $this->visibleUnmatchedRu = $unmatchedRu['rows'];
        $this->unmatchedRuTotal = $unmatchedRu['total'];
        $this->unmatchedRuLastPage = $unmatchedRu['last_page'];
        $this->unmatchedRuPage = min($this->unmatchedRuPage, $this->unmatchedRuLastPage);
    }

    /**
     * @return array<string, mixed>
     */
    private function getDraft(): array
    {
        $userId = Auth::id();

        if ($userId === null) {
            return [
                'meaning_rows' => [],
                'unmatched_en' => [],
                'unmatched_ru' => [],
            ];
        }

        $draft = $this->draftStore()->get($this->getRecord()->id, $userId);

        if ($draft !== null) {
            return $draft;
        }

        $draft = app(AlignmentEditorPresenter::class)->toDraft($this->getRecord());
        $this->draftStore()->put($this->getRecord()->id, $userId, $draft);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function putDraft(array $draft): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        $this->draftStore()->put($this->getRecord()->id, $userId, $draft);
    }

    private function draftStore(): AlignmentEditorDraftStore
    {
        return app(AlignmentEditorDraftStore::class);
    }

    /**
     * @param  list<array<string, mixed>>  $sentences
     */
    private function sentencePreview(array $sentences): string
    {
        if ($sentences === []) {
            return '—';
        }

        return Str::limit((string) $sentences[0]['content'], 40);
    }

    private function markDirty(): void
    {
        $this->isDirty = true;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    private function allSentencesForLang(array $draft, string $lang): array
    {
        $sideKey = $lang === 'en' ? 'en_sentences' : 'ru_sentences';
        $unmatchedKey = $lang === 'en' ? 'unmatched_en' : 'unmatched_ru';
        $all = [];

        foreach ($draft['meaning_rows'] as $row) {
            foreach ($row[$sideKey] as $sentence) {
                $all[] = $sentence;
            }
        }

        foreach ($draft[$unmatchedKey] as $sentence) {
            $all[] = $sentence;
        }

        usort($all, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $all;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<array<string, mixed>>  $all
     */
    private function applySentenceOrders(array &$draft, string $lang, array $all): void
    {
        $lookup = [];

        foreach ($all as $sentence) {
            $lookup[$sentence['key']] = $sentence['order'];
        }

        $sideKey = $lang === 'en' ? 'en_sentences' : 'ru_sentences';
        $unmatchedKey = $lang === 'en' ? 'unmatched_en' : 'unmatched_ru';

        foreach ($draft['meaning_rows'] as &$row) {
            foreach ($row[$sideKey] as &$sentence) {
                if (isset($lookup[$sentence['key']])) {
                    $sentence['order'] = $lookup[$sentence['key']];
                }
            }
            unset($sentence);
        }
        unset($row);

        foreach ($draft[$unmatchedKey] as &$sentence) {
            if (isset($lookup[$sentence['key']])) {
                $sentence['order'] = $lookup[$sentence['key']];
            }
        }
        unset($sentence);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{order: int, items: list<array{key: string, order: int}>}
     */
    private function sentenceOrderPlacement(array $draft, string $lang, ?string $movingKey, int $afterOrder): array
    {
        $items = array_map(
            fn (array $sentence): array => [
                'key' => (string) $sentence['key'],
                'order' => (int) $sentence['order'],
            ],
            $this->allSentencesForLang($draft, $lang),
        );

        return app(SparseOrderService::class)->orderForInsertAfter($items, $movingKey, $afterOrder);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{order: int, items: list<array{key: string, order: int}>}
     */
    private function meaningOrderPlacement(array $draft, ?string $movingKey, int $afterOrder): array
    {
        $items = array_map(
            fn (array $row): array => [
                'key' => (string) $row['key'],
                'order' => (int) $row['order'],
            ],
            $draft['meaning_rows'],
        );

        return app(SparseOrderService::class)->orderForInsertAfter($items, $movingKey, $afterOrder);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<array{key: string, order: int}>  $items
     */
    private function applyMeaningOrders(array &$draft, array $items): void
    {
        $lookup = [];

        foreach ($items as $item) {
            $lookup[$item['key']] = $item['order'];
        }

        foreach ($draft['meaning_rows'] as &$row) {
            if (isset($lookup[$row['key']])) {
                $row['order'] = $lookup[$row['key']];
            }
        }
        unset($row);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function lastSentenceOrderAtOrBeforeRow(array $draft, string $lang, int $rowOrder): int
    {
        $sideKey = $lang === 'en' ? 'en_sentences' : 'ru_sentences';
        $lastOrder = SparseOrderService::BEGINNING_SENTINEL;

        foreach ($draft['meaning_rows'] as $row) {
            if ($row['order'] > $rowOrder) {
                continue;
            }

            foreach ($row[$sideKey] as $sentence) {
                $lastOrder = max($lastOrder, (int) $sentence['order']);
            }
        }

        return $lastOrder;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function renormalizeEntityOrders(array &$draft, string $lang): void
    {
        $all = $this->allSentencesForLang($draft, $lang);
        $all = app(SparseOrderService::class)->rebalanceItems(array_map(
            fn (array $sentence): array => [
                'key' => (string) $sentence['key'],
                'order' => (int) $sentence['order'],
            ],
            $all,
        ));

        $this->applySentenceOrders($draft, $lang, $all);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function updateSentenceInDraft(array &$draft, string $lang, string $sentenceKey, string $content): bool
    {
        $sideKey = $lang === 'en' ? 'en_sentences' : 'ru_sentences';
        $unmatchedKey = $lang === 'en' ? 'unmatched_en' : 'unmatched_ru';

        foreach ($draft['meaning_rows'] as &$row) {
            foreach ($row[$sideKey] as &$sentence) {
                if ($sentence['key'] === $sentenceKey) {
                    $sentence['content'] = $content;

                    return true;
                }
            }
        }
        unset($row, $sentence);

        foreach ($draft[$unmatchedKey] as &$sentence) {
            if ($sentence['key'] === $sentenceKey) {
                $sentence['content'] = $content;

                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function removeSentenceFromDraft(array &$draft, string $lang, string $sentenceKey): void
    {
        $this->extractSentenceFromMeaningRows($draft, $lang, $sentenceKey);
        $this->extractSentenceFromUnmatched($draft, $lang, $sentenceKey);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function extractSentenceFromMeaningRows(array &$draft, string $lang, string $sentenceKey): ?array
    {
        $sideKey = $lang === 'en' ? 'en_sentences' : 'ru_sentences';

        foreach ($draft['meaning_rows'] as &$row) {
            foreach ($row[$sideKey] as $index => $sentence) {
                if ($sentence['key'] !== $sentenceKey) {
                    continue;
                }

                unset($row[$sideKey][$index]);
                $row[$sideKey] = array_values($row[$sideKey]);

                return $sentence;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function extractSentenceFromUnmatched(array &$draft, string $lang, string $sentenceKey): ?array
    {
        $unmatchedKey = $lang === 'en' ? 'unmatched_en' : 'unmatched_ru';

        foreach ($draft[$unmatchedKey] as $index => $sentence) {
            if ($sentence['key'] !== $sentenceKey) {
                continue;
            }

            unset($draft[$unmatchedKey][$index]);
            $draft[$unmatchedKey] = array_values($draft[$unmatchedKey]);

            return $sentence;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $all
     */
    private function findSentenceIndex(array $all, string $sentenceKey): ?int
    {
        foreach ($all as $index => $sentence) {
            if ($sentence['key'] === $sentenceKey) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function findMeaningRowIndex(array $draft, string $rowKey): ?int
    {
        foreach ($draft['meaning_rows'] as $index => $row) {
            if ($row['key'] === $rowKey) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function nextSentenceOrderAfterRow(array $draft, string $lang, int $rowOrder): int
    {
        $sideKey = $lang === 'en' ? 'en_sentences' : 'ru_sentences';
        $maxOrder = 0;

        foreach ($draft['meaning_rows'] as $row) {
            if ($row['order'] > $rowOrder) {
                continue;
            }

            foreach ($row[$sideKey] as $sentence) {
                $maxOrder = max($maxOrder, (int) $sentence['order']);
            }
        }

        return $maxOrder + 1;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function shiftSentenceOrdersAtOrAfter(array &$draft, string $lang, int $insertOrder): void
    {
        $sideKey = $lang === 'en' ? 'en_sentences' : 'ru_sentences';
        $unmatchedKey = $lang === 'en' ? 'unmatched_en' : 'unmatched_ru';

        foreach ($draft['meaning_rows'] as &$row) {
            foreach ($row[$sideKey] as &$sentence) {
                if ($sentence['order'] >= $insertOrder) {
                    $sentence['order']++;
                }
            }
            unset($sentence);
        }
        unset($row);

        foreach ($draft[$unmatchedKey] as &$sentence) {
            if ($sentence['order'] >= $insertOrder) {
                $sentence['order']++;
            }
        }
        unset($sentence);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasFilledSentencesOnBothSides(array $row): bool
    {
        return $this->hasFilledSentence($row['en_sentences'])
            && $this->hasFilledSentence($row['ru_sentences']);
    }

    /**
     * @param  list<array<string, mixed>>  $sentences
     */
    private function hasFilledSentence(array $sentences): bool
    {
        foreach ($sentences as $sentence) {
            if (trim((string) $sentence['content']) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function removeEmptyMeaningRows(array &$draft): void
    {
        $draft['meaning_rows'] = array_values(array_filter(
            $draft['meaning_rows'],
            fn (array $row): bool => $row['en_sentences'] !== [] || $row['ru_sentences'] !== [],
        ));

        usort($draft['meaning_rows'], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
    }
}
