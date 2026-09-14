<?php

namespace App\Filament\Resources\EntityMatchResource\Pages;

use App\Classes\AlignmentEditorDraftStore;
use App\Classes\AlignmentEditorPersister;
use App\Classes\AlignmentEditorPresenter;
use App\Classes\SparseOrderService;
use App\Filament\Resources\EntityMatchResource;
use App\Models\EntityMatch;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EditEntityAlignment extends Page
{
    use InteractsWithRecord {
        getRecord as getResolvedRecord;
    }

    protected static string $resource = EntityMatchResource::class;

    protected static ?string $title = 'Edit Alignment';

    protected static ?string $navigationLabel = 'Edit Alignment';

    protected string $view = 'filament.pages.edit-entity-alignment';

    public int $meaningPage = 1;

    public int $meaningPerPage = 25;

    public int $meaningRowsTotal = 0;

    public int $meaningLastPage = 1;

    /** @var list<array<string, mixed>> */
    public array $visibleMeaningRows = [];

    public int $unmatchedAPage = 1;

    public int $unmatchedBPage = 1;

    public int $unmatchedPerPage = 15;

    public int $unmatchedATotal = 0;

    public int $unmatchedBTotal = 0;

    public int $unmatchedALastPage = 1;

    public int $unmatchedBLastPage = 1;

    /** @var list<array<string, mixed>> */
    public array $visibleUnmatchedA = [];

    /** @var list<array<string, mixed>> */
    public array $visibleUnmatchedB = [];

    public bool $isDirty = false;

    public bool $showAddModal = false;

    public string $addSide = 'a';

    public int $addAfterOrder = 0;

    public string $addContent = '';

    public bool $showConnectModal = false;

    public string $connectSide = 'a';

    public string $connectSentenceKey = '';

    public string $connectTargetMeaningKey = '';

    public int $connectMode = 0;

    public int $connectInsertAfterMeaningOrder = 0;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->initializeDraft();
        $this->refreshVisibleData();
    }

    public function getRecord(): EntityMatch
    {
        /** @var EntityMatch $record */
        $record = $this->getResolvedRecord();

        return $record;
    }

    /**
     * Display label for one side: the entity's language name, falling back
     * to the side letter.
     *
     * @param  'a'|'b'  $side
     */
    public function sideLabel(string $side): string
    {
        $this->getRecord()->loadMissing(['aEntity.language', 'bEntity.language']);

        $entity = $side === 'a' ? $this->getRecord()->aEntity : $this->getRecord()->bEntity;

        return $entity?->language?->name ?? strtoupper($side);
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

    public function goToUnmatchedAPage(int $page): void
    {
        $this->unmatchedAPage = max(1, $page);
        $this->refreshVisibleData();
    }

    public function goToUnmatchedBPage(int $page): void
    {
        $this->unmatchedBPage = max(1, $page);
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

    public function updateSentenceContent(string $side, string $sentenceKey, string $content): void
    {
        $draft = $this->getDraft();

        if (! $this->updateSentenceInDraft($draft, $side, $sentenceKey, $content)) {
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

        foreach (['a', 'b'] as $side) {
            $sideKey = $side === 'a' ? 'a_sentences' : 'b_sentences';
            $sentencePlacement = $this->sentenceOrderPlacement(
                draft: $draft,
                side: $side,
                movingKey: null,
                afterOrder: $this->lastSentenceOrderAtOrBeforeRow($draft, $side, (int) $row['order']),
            );
            $this->applySentenceOrders($draft, $side, $sentencePlacement['items']);

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
                ->title('Add sentences on both sides before saving this row')
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

        foreach ($row['a_sentences'] as $sentence) {
            $draft['unmatched_a'][] = $sentence;
        }

        foreach ($row['b_sentences'] as $sentence) {
            $draft['unmatched_b'][] = $sentence;
        }

        unset($draft['meaning_rows'][$rowIndex]);
        $draft['meaning_rows'] = array_values($draft['meaning_rows']);

        usort($draft['unmatched_a'], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        usort($draft['unmatched_b'], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $this->putDraft($draft);

        app(AlignmentEditorPersister::class)->persist($this->getRecord(), $draft);
        $this->loadDraftFromDatabase();

        Notification::make()
            ->title('Link removed')
            ->success()
            ->send();
    }

    public function moveSentence(string $side, string $sentenceKey, string $direction): void
    {
        $draft = $this->getDraft();
        $all = $this->allSentencesForLang($draft, $side);
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
            side: $side,
            movingKey: $sentenceKey,
            afterOrder: $afterOrder,
        );

        $all = $placement['items'];
        $all[] = [
            'key' => $sentenceKey,
            'order' => $placement['order'],
        ];

        $this->applySentenceOrders($draft, $side, $all);
        $this->putDraft($draft);
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function deleteSentence(string $side, string $sentenceKey): void
    {
        $draft = $this->getDraft();
        $this->removeSentenceFromDraft($draft, $side, $sentenceKey);
        $this->putDraft($draft);
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function unlinkSentence(string $side, string $sentenceKey): void
    {
        $draft = $this->getDraft();
        $sentence = $this->extractSentenceFromMeaningRows($draft, $side, $sentenceKey);

        if ($sentence === null) {
            return;
        }

        $unmatchedKey = $side === 'a' ? 'unmatched_a' : 'unmatched_b';
        $draft[$unmatchedKey][] = $sentence;
        usort($draft[$unmatchedKey], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $this->putDraft($draft);
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function openAddModal(string $side): void
    {
        $this->addSide = $side;
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
            side: $this->addSide,
            movingKey: null,
            afterOrder: $this->addAfterOrder,
        );
        $this->applySentenceOrders($draft, $this->addSide, $placement['items']);

        $newSentence = $presenter->sentencePayload(
            id: null,
            content: $content,
            order: $placement['order'],
            tempId: $tempId,
        );

        $unmatchedKey = $this->addSide === 'a' ? 'unmatched_a' : 'unmatched_b';

        $draft[$unmatchedKey][] = $newSentence;
        usort($draft[$unmatchedKey], fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $this->putDraft($draft);
        $this->showAddModal = false;
        $this->addContent = '';
        $this->markDirty();
        $this->refreshVisibleData();
    }

    public function openConnectModal(string $side, string $sentenceKey): void
    {
        $this->connectSide = $side;
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
        $sentence = $this->extractSentenceFromUnmatched($draft, $this->connectSide, $this->connectSentenceKey);

        if ($sentence === null) {
            return;
        }

        $sideKey = $this->connectSide === 'a' ? 'a_sentences' : 'b_sentences';

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
    public function getInsertOrderOptions(string $side): array
    {
        $all = $this->allSentencesForLang($this->getDraft(), $side);
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
            $aPreview = $this->sentencePreview($row['a_sentences']);
            $bPreview = $this->sentencePreview($row['b_sentences']);
            $options[] = [
                'value' => $row['key'],
                'label' => '#'.($rowOffset + $index + 1).': '.$aPreview.' / '.$bPreview,
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

        $unmatchedA = $store->paginateUnmatched($draft, 'a', $this->unmatchedAPage, $this->unmatchedPerPage);
        $this->visibleUnmatchedA = $unmatchedA['rows'];
        $this->unmatchedATotal = $unmatchedA['total'];
        $this->unmatchedALastPage = $unmatchedA['last_page'];
        $this->unmatchedAPage = min($this->unmatchedAPage, $this->unmatchedALastPage);

        $unmatchedB = $store->paginateUnmatched($draft, 'b', $this->unmatchedBPage, $this->unmatchedPerPage);
        $this->visibleUnmatchedB = $unmatchedB['rows'];
        $this->unmatchedBTotal = $unmatchedB['total'];
        $this->unmatchedBLastPage = $unmatchedB['last_page'];
        $this->unmatchedBPage = min($this->unmatchedBPage, $this->unmatchedBLastPage);
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
                'unmatched_a' => [],
                'unmatched_b' => [],
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
    private function allSentencesForLang(array $draft, string $side): array
    {
        $sideKey = $side === 'a' ? 'a_sentences' : 'b_sentences';
        $unmatchedKey = $side === 'a' ? 'unmatched_a' : 'unmatched_b';
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
    private function applySentenceOrders(array &$draft, string $side, array $all): void
    {
        $lookup = [];

        foreach ($all as $sentence) {
            $lookup[$sentence['key']] = $sentence['order'];
        }

        $sideKey = $side === 'a' ? 'a_sentences' : 'b_sentences';
        $unmatchedKey = $side === 'a' ? 'unmatched_a' : 'unmatched_b';

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
    private function sentenceOrderPlacement(array $draft, string $side, ?string $movingKey, int $afterOrder): array
    {
        $items = array_map(
            fn (array $sentence): array => [
                'key' => (string) $sentence['key'],
                'order' => (int) $sentence['order'],
            ],
            $this->allSentencesForLang($draft, $side),
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
    private function lastSentenceOrderAtOrBeforeRow(array $draft, string $side, int $rowOrder): int
    {
        $sideKey = $side === 'a' ? 'a_sentences' : 'b_sentences';
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
    private function renormalizeEntityOrders(array &$draft, string $side): void
    {
        $all = $this->allSentencesForLang($draft, $side);
        $all = app(SparseOrderService::class)->rebalanceItems(array_map(
            fn (array $sentence): array => [
                'key' => (string) $sentence['key'],
                'order' => (int) $sentence['order'],
            ],
            $all,
        ));

        $this->applySentenceOrders($draft, $side, $all);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function updateSentenceInDraft(array &$draft, string $side, string $sentenceKey, string $content): bool
    {
        $sideKey = $side === 'a' ? 'a_sentences' : 'b_sentences';
        $unmatchedKey = $side === 'a' ? 'unmatched_a' : 'unmatched_b';

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
    private function removeSentenceFromDraft(array &$draft, string $side, string $sentenceKey): void
    {
        $this->extractSentenceFromMeaningRows($draft, $side, $sentenceKey);
        $this->extractSentenceFromUnmatched($draft, $side, $sentenceKey);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function extractSentenceFromMeaningRows(array &$draft, string $side, string $sentenceKey): ?array
    {
        $sideKey = $side === 'a' ? 'a_sentences' : 'b_sentences';

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
    private function extractSentenceFromUnmatched(array &$draft, string $side, string $sentenceKey): ?array
    {
        $unmatchedKey = $side === 'a' ? 'unmatched_a' : 'unmatched_b';

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
    private function nextSentenceOrderAfterRow(array $draft, string $side, int $rowOrder): int
    {
        $sideKey = $side === 'a' ? 'a_sentences' : 'b_sentences';
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
    private function shiftSentenceOrdersAtOrAfter(array &$draft, string $side, int $insertOrder): void
    {
        $sideKey = $side === 'a' ? 'a_sentences' : 'b_sentences';
        $unmatchedKey = $side === 'a' ? 'unmatched_a' : 'unmatched_b';

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
        return $this->hasFilledSentence($row['a_sentences'])
            && $this->hasFilledSentence($row['b_sentences']);
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
}
