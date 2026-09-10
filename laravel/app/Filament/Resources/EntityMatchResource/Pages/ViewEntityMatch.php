<?php

namespace App\Filament\Resources\EntityMatchResource\Pages;

use App\Classes\MeaningMatchPresenter;
use App\Filament\Resources\EntityMatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewEntityMatch extends ViewRecord
{
    protected static string $resource = EntityMatchResource::class;

    protected string $view = 'filament.pages.view-entity-alignment';

    public int $displayPage = 1;

    public int $displayPerPage = 50;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editAlignment')
                ->label('Edit alignment')
                ->icon('heroicon-o-pencil-square')
                ->url(fn (): string => EntityMatchResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function goToDisplayPage(int $page): void
    {
        $this->displayPage = max(1, $page);
    }

    /**
     * Display label for one side: the entity's language name, falling back
     * to the side letter.
     *
     * @param  'a'|'b'  $side
     */
    public function sideLabel(string $side): string
    {
        $this->record->loadMissing(['aEntity.language', 'bEntity.language']);

        $entity = $side === 'a' ? $this->record->aEntity : $this->record->bEntity;

        return $entity?->language?->name ?? strtoupper($side);
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     last_page: int
     * }
     */
    public function getDisplayData(): array
    {
        $presenter = app(MeaningMatchPresenter::class);
        $query = $presenter->meaningMatchesQuery($this->record);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $this->displayPerPage));
        $page = min(max(1, $this->displayPage), $lastPage);

        $meaningMatches = (clone $query)
            ->forPage($page, $this->displayPerPage)
            ->get();

        return [
            'rows' => $presenter->toDisplayRows($meaningMatches),
            'total' => $total,
            'page' => $page,
            'per_page' => $this->displayPerPage,
            'last_page' => $lastPage,
        ];
    }
}
