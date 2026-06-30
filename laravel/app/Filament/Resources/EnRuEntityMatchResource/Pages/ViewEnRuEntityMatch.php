<?php

namespace App\Filament\Resources\EnRuEntityMatchResource\Pages;

use App\Classes\MeaningMatchPresenter;
use App\Filament\Resources\EnRuEntityMatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewEnRuEntityMatch extends ViewRecord
{
    protected static string $resource = EnRuEntityMatchResource::class;

    protected string $view = 'filament.pages.view-entity-alignment';

    public int $displayPage = 1;

    public int $displayPerPage = 50;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editAlignment')
                ->label('Edit alignment')
                ->icon('heroicon-o-pencil-square')
                ->url(fn (): string => EnRuEntityMatchResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function goToDisplayPage(int $page): void
    {
        $this->displayPage = max(1, $page);
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
